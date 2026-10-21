<?php

/**
 * Copyright (c) 2023-2026 Dartmoon S.r.l. <hello@dartmoon.io>
 * SPDX-License-Identifier: MIT
 * See LICENSE for the full license text.
 */

declare(strict_types=1);

namespace Dartmoon\PrestaShopInstaller;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class CreateModuleCommand extends Command
{
    public function __construct(private ProjectCreator $creator = new ProjectCreator())
    {
        parent::__construct('create-module');
    }

    protected function configure(): void
    {
        $this->setDescription('Create a module from dartmoon/prestashop-module')
            ->addArgument('module-name', InputArgument::REQUIRED, 'Technical module name, for example mymodule')
            ->addOption('directory', null, InputOption::VALUE_REQUIRED, 'Destination directory (defaults to ./<module-name>)')
            ->addOption('template-path', null, InputOption::VALUE_REQUIRED, 'Use an unconfigured local boilerplate checkout')
            ->addOption('template-version', null, InputOption::VALUE_REQUIRED, 'Boilerplate branch/tag to download', 'dev-main')
            ->addOption('toolkit-path', null, InputOption::VALUE_REQUIRED, 'Use a local toolkit Composer path repository')
            ->addOption('namespace', null, InputOption::VALUE_REQUIRED, 'Application PHP namespace')
            ->addOption('display-name', null, InputOption::VALUE_REQUIRED, 'Module display name')
            ->addOption('author', null, InputOption::VALUE_REQUIRED, 'Module author')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Module description')
            ->addOption('module-version', null, InputOption::VALUE_REQUIRED, 'Initial module version, for example 1.0.0')
            ->addOption('no-install', null, InputOption::VALUE_NONE, 'Configure the project without installing its Composer dependencies');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = array_filter($input->getOptions(), static fn (mixed $value): bool => $value !== null);
        try {
            $directory = $this->creator->create((string) $input->getArgument('module-name'), $options, $output, $input->isInteractive());
        } catch (\Throwable $exception) {
            $output->writeln('<error>' . \Symfony\Component\Console\Formatter\OutputFormatter::escape($exception->getMessage()) . '</error>');

            return self::FAILURE;
        }
        $output->writeln('Created ' . \Symfony\Component\Console\Formatter\OutputFormatter::escape($directory));
        $output->writeln(($options['no-install'] ?? false)
            ? 'Next: run composer install and composer tools:install in the new module.'
            : 'Next: run composer quality:fix, develop your module, then run composer verify (set TEMPLATE_PATH and TOOLKIT_PATH for local regression tests).');

        return self::SUCCESS;
    }
}
