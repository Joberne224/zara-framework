<?php
declare(strict_types=1);

namespace Zara\Container\Compiler\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Zara\Container\Compiler\CompiledContainerWriter;
use Zara\Container\Exceptions\ContainerException;

#[AsCommand(name: 'container:clear')]
final class ContainerClearCommand extends Command
{
    public function __construct(
        private CompiledContainerWriter $writer,
        private string $compiledOutputPath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Ignore the confirmation (useful in CI or non-interactive scripts)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('force')) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                'Do you really need to clear compiled file (Yes/No)? ',
                false, // Default to No when the user simply presses Enter.
            );

            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('<comment>Aborted.</comment>');
                return Command::SUCCESS;
            }
        }

        try {
            $cleared = $this->writer->clear($this->compiledOutputPath);
        } catch (ContainerException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $output->writeln($cleared
            ? '<info>Compiled container cleared.</info>'
            : '<comment>No compiled container found — nothing to clear.</comment>');

        return Command::SUCCESS;
    }
}
