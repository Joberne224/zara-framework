<?php
declare(strict_types=1);

namespace Zara\View\Engine;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Zara\Core\Debug\Profiler\Support\ViewTracker;
use Zara\Core\Config\Env;
use Zara\View\Cache\CacheManager;
use Zara\View\Compiler\TemplateCompiler;
use Zara\View\Runtime\IsolatedRenderer;
use Zara\View\Runtime\AttributeMethodCallGuard;
use Zara\View\Interfaces\MethodCallGuardInterface;
use Zara\View\Parser\BlockParser;
use Zara\View\Parser\CommentParser;
use Zara\View\Parser\ControlStructureParser;
use Zara\View\Parser\FunctionParser;
use Zara\View\Parser\IncludeParser;
use Zara\View\Parser\RawParser;
use Zara\View\Parser\VariableDeclarationParser;
use Zara\View\Parser\VariableParser;
use Zara\View\Registry\FilterRegistry;
use Zara\View\Registry\FunctionRegistry;
use Zara\View\Parser\Support\TemplateInclusionStack;
use Zara\View\Parser\VariableBlockCaptureParser;
use Zara\View\Parser\MultipleVariableDeclarationParser;
use Zara\View\Parser\TemplateSyntaxValidator;
use Zara\View\Interfaces\TemplateEngineInterface;
use Zara\View\Interfaces\TemplateExceptionInterface;
use Zara\View\Exceptions\TemplateException;

class TemplateEngine implements TemplateEngineInterface
{
    private TemplateResolver $resolver;
    private CacheManager     $cache;
    private FilterRegistry   $filters;
    private FunctionRegistry $functions;
    private TemplateCompiler $compiler;
    private IsolatedRenderer $isolatedRenderer;
    private LoggerInterface  $logger;
    private ?MethodCallGuardInterface $methodCallGuard;
    private bool             $debug;

    public function __construct(
        TemplateEngineConfig $config,
        ?IsolatedRenderer $isolatedRenderer = null
    ) {
        
        $this->debug            = $config->debug;
        $this->logger           = $config->logger ?? new NullLogger();
        $this->resolver         = new TemplateResolver($config->basePath, $config->cachePath, $config->allowedExtensions);
        $this->cache            = new CacheManager($config->cachePath);
        $this->filters          = new FilterRegistry();
        $this->functions        = new FunctionRegistry(new \Zara\View\Asset\AssetResolver($config->projectRoot), $config->router, $config->baseUrl);
        $this->methodCallGuard = $config->restrictTemplateMethodCalls
            ? new AttributeMethodCallGuard()
            : null;
        $this->isolatedRenderer = $isolatedRenderer ?? new IsolatedRenderer($config->cachePath);
        $this->assertNoNameCollisions();
        $this->compiler         = $this->createCompiler();
    }

    public function render(string $template, array $data = []): string
    {
        // Tracking wraps the whole method rather than just the isolated-render
        // step: compilation time is real render-time cost from the app's point
        // of view, and knowing whether a view had to recompile (vs served from
        // cache) is itself a useful signal in the debugbar.
        $tracking    = Env::isProfilerEnabled();
        $start       = $tracking ? microtime(true) : 0.0;
        $mustCompile = false;
        $failed      = false;

        try {
            $templatePath = $this->resolver->resolveTemplatePath($template);
            $cachePath    = $this->resolver->resolveCachePath($template);

            if (!file_exists($templatePath)) {
                if (file_exists($cachePath)) {
                    $this->cache->deleteFile($cachePath);
                }
                $this->logger->error("Template not found: {$templatePath}");

                throw new TemplateException(
                    "Template not found: $templatePath",
                    $templatePath,
                    0
                );
            }

            $mustCompile = $this->debug || !$this->cache->isValid($templatePath, $cachePath);

            try {
                if ($mustCompile) {
                    $this->logger->debug("Compiling template '{$template}'", [
                        'source' => $templatePath,
                        'cache'  => $cachePath,
                        'debug'  => $this->debug
                    ]);

                    $source = file_get_contents($templatePath);
                    if ($source === false) {
                        throw new \RuntimeException("Unable to read template source: {$templatePath}");
                    }
                    $compiled = $this->compiler->compile($source);
                    $sourceHash = hash('sha256', $source);
                    $compilerVersion = TemplateCompiler::CACHE_VERSION;
                    $compiled = "<?php /* ZARA_SOURCE_SHA256:{$sourceHash} ZARA_COMPILER_VERSION:{$compilerVersion} */ ?>" . $compiled;
                    $this->cache->manageSize();
                    $this->cache->write($cachePath, $compiled);
                }
            } catch (\Throwable $compilerError) {
                $templateLine = $compilerError instanceof TemplateExceptionInterface
                    ? $compilerError->getTemplateLine()
                    : $compilerError->getLine();

                $this->logger->critical("Template compilation error in '{$templatePath}' at line {$templateLine}", [
                    'exception' => $compilerError
                ]);

                throw new TemplateException(
                    "Compilation Error: " . $compilerError->getMessage(),
                    $templatePath,
                    $templateLine,
                    $compilerError
                );
            }

            $filters           = $this->filters->all();
            $callableFunctions = $this->functions->allCallables();

            ob_start();

            try {
                $this->isolatedRenderer->render(
                    $cachePath,
                    $data,
                    $filters,
                    $callableFunctions,
                    $this->methodCallGuard
                );
                $output = ob_get_clean();
            } catch (\Throwable $runtimeError) {
                ob_end_clean();
                $templateLine = $this->mapCacheLineToTemplateLine($cachePath, $runtimeError);

                $this->logger->error("Template runtime error in '{$templatePath}' at line {$templateLine}: {$runtimeError->getMessage()}", [
                    'exception' => $runtimeError
                ]);

                throw new TemplateException(
                    $runtimeError->getMessage(),
                    $templatePath,
                    $templateLine,
                    $runtimeError
                );
            }

            return (string) $output;
        } catch (\Throwable $e) {
            $failed = true;
            throw $e;
        } finally {
            if ($tracking) {
                ViewTracker::current()->record(
                    template: $template,
                    dataKeys: array_keys($data),
                    durationMs: (microtime(true) - $start) * 1000,
                    compiled: $mustCompile,
                    failed: $failed,
                    startedAt: $start,
                );
            }
        }
    }

    public function clearCache(?string $template = null): void
    {
        if ($template) {
            $cachePath = $this->resolver->resolveCachePath($template);
            $this->cache->deleteFile($cachePath);
            $this->logger->info("Cleared cache for template '{$template}'");
        } else {
            $this->cache->clear();
            $this->logger->info("Cleared entire view cache");
        }
    }

    private function createCompiler(): TemplateCompiler
    {
        $compiler        = new TemplateCompiler();
        $syntaxValidator = new TemplateSyntaxValidator();
        $inclusionStack  = new TemplateInclusionStack();

        $compiler->addParser($syntaxValidator);
        $compiler->addParser(new BlockParser($this->resolver, $syntaxValidator, $inclusionStack));
        $compiler->addParser(new IncludeParser($this->resolver, $syntaxValidator, null, $inclusionStack));
        $compiler->addParser(new ControlStructureParser());
        $compiler->addParser(new VariableBlockCaptureParser());
        $compiler->addParser(new MultipleVariableDeclarationParser());
        $compiler->addParser(new VariableDeclarationParser());
        $compiler->addParser(new FunctionParser($this->functions));
        $compiler->addParser(new VariableParser($this->filters));
        $compiler->addParser(new RawParser());
        $compiler->addParser(new CommentParser());

        return $compiler;
    }

    private function mapCacheLineToTemplateLine(string $cachePath, \Throwable $e): int
    {
        $cacheLine = $e->getLine();

        if ($e->getFile() !== $cachePath) {
            foreach ($e->getTrace() as $frame) {
                if (isset($frame['file']) && $frame['file'] === $cachePath) {
                    $cacheLine = $frame['line'];
                    break;
                }
            }
        }

        if (!file_exists($cachePath)) {
            return $cacheLine;
        }

        $lines = file($cachePath);
        
        for ($i = $cacheLine - 1; $i >= 0; $i--) {
            if (isset($lines[$i]) && preg_match('/\/\* LINE: (\d+) \*\//', $lines[$i], $matches)) {
                return (int) $matches[1];
            }
        }

        return $cacheLine;
    }

    private function assertNoNameCollisions(): void
    {
        $collisions = array_intersect_key(
            $this->filters->all(),
            $this->functions->allCallables()
        );

        if ($collisions !== []) {
            $message = sprintf(
                "Name collision(s) between FilterRegistry and FunctionRegistry: %s.",
                implode(', ', array_keys($collisions))
            );
            $this->logger->critical($message);
            throw new \LogicException($message);
        }
    }
}
