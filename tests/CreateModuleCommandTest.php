<?php

/**
 * Copyright (c) 2023-2026 Dartmoon S.r.l. <hello@dartmoon.io>
 * SPDX-License-Identifier: MIT
 * See LICENSE for the full license text.
 */

declare(strict_types=1);

namespace Dartmoon\PrestaShopInstaller\Tests;

use Dartmoon\PrestaShopInstaller\CreateModuleCommand;
use Dartmoon\PrestaShopInstaller\ProcessRunner;
use Dartmoon\PrestaShopInstaller\ProjectCreator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class CreateModuleCommandTest extends TestCase
{
    private string $temp;
    private string $template;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->temp = sys_get_temp_dir() . '/dm-installer-test-' . bin2hex(random_bytes(6));
        $this->filesystem->mkdir($this->temp);
        $this->template = realpath(getenv('TEMPLATE_PATH') ?: dirname(__DIR__, 2) . '/prestashop-module') ?: '';
        if ($this->template === '') {
            self::fail('Set TEMPLATE_PATH to a current prestashop-module checkout.');
        }
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->temp);
    }

    private function execute(array $options, ?ProjectCreator $creator = null): CommandTester
    {
        $tester = new CommandTester(new CreateModuleCommand($creator ?? new ProjectCreator()));
        $tester->execute(array_merge([
            'module-name' => 'mymodule', '--directory' => $this->temp . '/mymodule',
            '--template-path' => $this->template, '--no-install' => true,
        ], $options), ['interactive' => false]);

        return $tester;
    }

    public function testLocalTemplateCreatesConfiguredModuleAndPreservesSource(): void
    {
        $before = hash_file('sha256', $this->template . '/composer.json');
        $tester = $this->execute([
            '--namespace' => 'Acme\\MyModule', '--display-name' => 'O\'Reilly "Demo"',
            '--author' => 'Author\\Name', '--module-version' => '2.1.0',
        ]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $root = $this->temp . '/mymodule';
        $config = require $root . '/config/module.php';
        self::assertSame('mymodule', $config['name']);
        self::assertSame('O\'Reilly "Demo"', $config['display_name']);
        self::assertSame('2.1.0', $config['version']);
        self::assertFileExists($root . '/mymodule.php');
        self::assertFileDoesNotExist($root . '/bin/create-module');
        self::assertFileDoesNotExist($root . '/examplemodule.php');
        self::assertDirectoryDoesNotExist($root . '/vendor');
        self::assertDirectoryDoesNotExist($root . '/tools/vendor');
        self::assertDirectoryDoesNotExist($root . '/node_modules');
        self::assertDirectoryDoesNotExist($root . '/.git');
        self::assertSame($before, hash_file('sha256', $this->template . '/composer.json'));
        $manifest = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['Acme\\MyModule\\' => 'src/'], $manifest['autoload']['psr-4']);
        self::assertStringContainsString('mymodule:hello', file_get_contents($root . '/src/Commands/ExampleCommand.php'));
    }

    public function testLocalToolkitRepositoryAndServiceDefinitionsAreConfigured(): void
    {
        $toolkit = realpath(getenv('TOOLKIT_PATH') ?: dirname(__DIR__, 2) . '/prestashop-module-toolkit');
        self::assertNotFalse($toolkit);
        $tester = $this->execute(['--toolkit-path' => $toolkit, '--namespace' => 'Acme\\ScopedModule']);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $manifest = json_decode(file_get_contents($this->temp . '/mymodule/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($toolkit, $manifest['repositories'][0]['url']);
        self::assertFalse($manifest['repositories'][0]['options']['symlink']);
        self::assertStringContainsString('Acme\\ScopedModule\\Filters\\Greeting', file_get_contents($this->temp . '/mymodule/config/services.php'));
        $config = require $this->temp . '/mymodule/config/module.php';
        self::assertSame(['min' => '9.2.0', 'max' => '9.2.0'], $config['compatibility']);
    }

    public function testExistingDirectoryIsNeverOverwritten(): void
    {
        $this->filesystem->mkdir($this->temp . '/mymodule');
        file_put_contents($this->temp . '/mymodule/keep.txt', 'existing');
        $tester = $this->execute([]);
        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('existing', file_get_contents($this->temp . '/mymodule/keep.txt'));
    }

    public function testInvalidNameFailsBeforeCreatingDestination(): void
    {
        $tester = $this->execute(['module-name' => '../escape']);
        self::assertSame(1, $tester->getStatusCode());
        self::assertDirectoryDoesNotExist($this->temp . '/mymodule');
    }

    public function testBrokenDestinationSymlinkIsNeverReplaced(): void
    {
        $this->filesystem->symlink($this->temp . '/missing', $this->temp . '/mymodule');
        $tester = $this->execute([]);
        self::assertSame(1, $tester->getStatusCode());
        self::assertTrue(is_link($this->temp . '/mymodule'));
    }

    public function testCannotCopyIntoTemplateThroughSymlinkedParent(): void
    {
        // This fake source needs only the compatibility contract; no setup runs.
        $source = $this->temp . '/source';
        $this->filesystem->mkdir([$source . '/bin', $source . '/tools/src']);
        $this->filesystem->copy($this->template . '/composer.json', $source . '/composer.json');
        touch($source . '/bin/module');
        touch($source . '/tools/src/Setup.php');
        $this->filesystem->symlink($source, $this->temp . '/alias');
        $tester = $this->execute(['--template-path' => $source, '--directory' => $this->temp . '/alias/nested/mymodule']);
        self::assertSame(1, $tester->getStatusCode());
        self::assertDirectoryDoesNotExist($source . '/nested');
    }

    public function testInvalidToolkitPathFailsBeforeCreatingDestination(): void
    {
        $tester = $this->execute(['--toolkit-path' => $this->temp]);
        self::assertSame(1, $tester->getStatusCode());
        self::assertDirectoryDoesNotExist($this->temp . '/mymodule');
    }

    public function testConfiguredProjectsCannotBeUsedAsTemplates(): void
    {
        $first = $this->execute([]);
        self::assertSame(0, $first->getStatusCode(), $first->getDisplay());
        $second = $this->execute(['--template-path' => $this->temp . '/mymodule', '--directory' => $this->temp . '/other']);
        self::assertSame(1, $second->getStatusCode());
        self::assertDirectoryDoesNotExist($this->temp . '/other');
    }

    public function testSetupFailureIsReportedAndNewDirectoryRetained(): void
    {
        $tester = $this->execute(['--namespace' => 'Symfony\\Module']);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('retained for inspection', $tester->getDisplay());
        self::assertFileExists($this->temp . '/mymodule/examplemodule.php');
    }

    public function testComposerFailureStopsBeforeToolsInstall(): void
    {
        $runner = new class extends ProcessRunner {
            public array $commands = [];

            public function run(array $command, string $directory, OutputInterface $output, bool $interactive = false): void
            {
                $this->commands[] = $command;
                if (in_array('setup', $command, true)) {
                    parent::run($command, $directory, $output, $interactive);

                    return;
                }
                throw new \RuntimeException('Expected Composer failure');
            }
        };
        $tester = $this->execute(['--no-install' => false], new ProjectCreator($runner));
        self::assertSame(1, $tester->getStatusCode());
        self::assertCount(2, $runner->commands);
        self::assertStringContainsString('Expected Composer failure', $tester->getDisplay());
    }

    public function testDefaultWorkflowFetchesRepositoryThenConfiguresAndInstalls(): void
    {
        $template = $this->template;
        $runner = new class($template) extends ProcessRunner {
            public array $commands = [];

            public function __construct(private string $template)
            {
            }

            public function run(array $command, string $directory, OutputInterface $output, bool $interactive = false): void
            {
                $this->commands[] = $command;
                if (in_array('create-project', $command, true)) {
                    $target = $command[count($command) - 2];
                    $finder = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
                        new \RecursiveDirectoryIterator($this->template, \FilesystemIterator::SKIP_DOTS),
                        static fn (\SplFileInfo $file): bool => !in_array($file->getFilename(), ['.git', 'vendor', 'node_modules', 'dist'], true)
                    ));
                    (new Filesystem())->mirror($this->template, $target, $finder);
                } elseif (in_array('setup', $command, true)) {
                    parent::run($command, $directory, $output, $interactive);
                }
            }
        };
        $tester = new CommandTester(new CreateModuleCommand(new ProjectCreator($runner)));
        $tester->execute(['module-name' => 'mymodule', '--directory' => $this->temp . '/mymodule'], ['interactive' => false]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertCount(4, $runner->commands);
        $download = $runner->commands[0];
        self::assertContains('create-project', $download);
        self::assertContains(ProjectCreator::TEMPLATE_PACKAGE, $download);
        self::assertContains('--repository={"type":"vcs","url":"https:\/\/github.com\/dartmoon-io\/prestashop-module.git"}', $download);
        self::assertContains('--no-scripts', $download);
        self::assertContains('--no-install', $download);
        self::assertContains('--no-plugins', $download);
        self::assertContains('dev-main', $download);
        self::assertContains('setup', $runner->commands[1]);
        self::assertContains('install', $runner->commands[2]);
        self::assertContains('--working-dir=tools', $runner->commands[3]);
    }
}
