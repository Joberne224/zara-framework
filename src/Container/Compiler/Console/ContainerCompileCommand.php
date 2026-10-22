<?php
declare(strict_types=1);

namespace Zara\Container\Compiler\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Zara\Container\Container;
use Zara\Container\Compiler\ContainerCompiler;
use Zara\Container\Compiler\CompiledContainerWriter;

#[AsCommand(name: 'container:compile')]
final class ContainerCompileCommand extends Command
{
    public function __construct(
        private Container $container,
        private string $servicesYamlPath,
        private string $compiledOutputPath,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $registry = $this->container->getRegistry();
        $allIds = $registry->getAllServiceIds();

        $compiler = new ContainerCompiler(
            $registry,
            $this->container->getParameterBag(),
            $this->container->getReflectionCache(),
        );

        $plan = $compiler->plan($allIds);
        $code = $compiler->compile($plan);

        (new CompiledContainerWriter())->write(
            $this->compiledOutputPath,
            $code,
            filemtime($this->servicesYamlPath),
            hash('sha256', serialize($plan->compilable())),
        );

        $output->writeln(sprintf('<info>Compiled %d service(s).</info>', count($plan->compilable())));

        foreach ($plan->excluded() as $id => $reason) {
            $output->writeln("<comment>Skipped '$id': $reason</comment>");
        }

        return Command::SUCCESS;
    }
}
