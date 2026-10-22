<?php
declare(strict_types=1);

namespace Zara\Core\Debug\Profiler;

use Zara\Core\Http\Interfaces\RequestInterface;
use Zara\Core\Http\Interfaces\ResponseInterface;
use Zara\Core\Http\Interfaces\MiddlewareInterface;
use Zara\Core\Http\Request;
use Zara\Core\Http\Response;
use Zara\Core\Config\Env;
use Zara\Core\Debug\Profiler\Support\QueryTracker;
use Zara\Database\Instrumentation\QueryObserverRegistry;

/**
 * Middleware that injects the Zara debug bar into HTML responses.
 *
 * How it works:
 *   1. Passes the request to the next handler.
 *   2. Receives the generated response.
 *   3. If Content-Type is text/html and the body contains </body>, injects
 *      the debug bar HTML immediately before </body>.
 *   4. Returns the modified response.
 *
 * This middleware should only be active in debug mode. Register it as a
 * global middleware only in the development bootstrap:
 *
 *   $router->addGlobalMiddleware($container->get(ProfilerMiddleware::class));
 *
 * or in services.yaml behind a development environment flag.
 */
final class ProfilerMiddleware implements MiddlewareInterface
{
    private DebugBarRenderer $renderer;

    public function __construct(
        private readonly Profiler $profiler,
    ) {
        $this->renderer = new DebugBarRenderer($profiler);
    }

    public function handle(RequestInterface $request, callable $next): ResponseInterface
    {
        if (!Env::isProfilerEnabled()) {
            return $next($request);
        }

        $previousObserver = QueryObserverRegistry::current();
        QueryObserverRegistry::set(QueryTracker::current());
        try {
            // Start the global request timer.
            $this->profiler->startTimer('request', 'Full request');

            /** @var Response $response */
            $response = $next($request);

            $this->profiler->stopTimer('request');

            return $this->inject($response);
        } finally {
            QueryObserverRegistry::set($previousObserver);
        }
    }

    private function inject(ResponseInterface $response): ResponseInterface
    {
        $contentType = $response->getHeader('Content-Type') ?? 'text/html';

        if (!str_contains($contentType, 'text/html')) {
            return $response;
        }

        $bodyStream = $response->getBody();

        if ($bodyStream->isSeekable()) {
            $bodyStream->rewind();
        }

        $html = $bodyStream->getContents();

        // A response may already contain the bar (for example, when it has passed
        // through this middleware more than once). Keep the renderer idempotent:
        // duplicate IDs make the global controls target the wrong bar and can
        // leave a floating bubble visible beside an expanded bar.
        if (str_contains($html, 'id="zdb-root"')) {
            return $response->withBody($html);
        }

        $bar     = $this->renderer->render();
        $closing = strrpos($html, '</body>');

        if ($closing === false) {
            // If there is no closing body tag, append the bar to the response.
            $response = $response->withBody($html . $bar);
        } else {
            $response = $response->withBody(
                substr($html, 0, $closing) . $bar . substr($html, $closing)
            );
        }

        return $response;
    }
}
