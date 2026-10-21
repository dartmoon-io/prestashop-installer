<?php

/**
 * Copyright (c) 2023-2026 Dartmoon S.r.l. <hello@dartmoon.io>
 * SPDX-License-Identifier: MIT
 * See LICENSE for the full license text.
 */

declare(strict_types=1);

namespace Dartmoon\PrestaShopInstaller;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

class ProjectCreator
{
    public const TEMPLATE_PACKAGE = 'dartmoon/prestashop-module';
    public const TEMPLATE_REPOSITORY = 'https://github.com/dartmoon-io/prestashop-module.git';

    public function __construct(private ProcessRunner $runner = new ProcessRunner())
    {
    }

    public function create(string $name, array $options, OutputInterface $output, bool $interactive): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $name) || $name === 'examplemodule') {
            throw new \InvalidArgumentException('Use a lowercase module name, 3–64 characters, starting with a letter; examplemodule is reserved.');
        }
        $directory = $this->resolvePath($options['directory'] ?? $name);
        if (file_exists($directory) || is_link($directory)) {
            throw new \InvalidArgumentException('Destination already exists: ' . $directory);
        }
        $template = null;
        if (isset($options['template-path'])) {
            $template = realpath($options['template-path']);
            if ($template === false) {
                throw new \InvalidArgumentException('The template path does not exist.');
            }
            $this->validateTemplate($template);
            if (Path::isBasePath($template, $directory)) {
                throw new \InvalidArgumentException('Create the module outside the boilerplate directory.');
            }
        }
        if (isset($options['toolkit-path'])) {
            $toolkit = realpath($options['toolkit-path']);
            $package = $toolkit === false ? [] : $this->manifest($toolkit);
            if (($package['name'] ?? '') !== 'dartmoon/prestashop-module-toolkit') {
                throw new \InvalidArgumentException('The toolkit path must point to prestashop-module-toolkit.');
            }
            $options['toolkit-path'] = $toolkit;
        }
        $filesystem = new Filesystem();
        try {
            if ($template !== null) {
                $this->copyTemplate($template, $directory);
            } else {
                $filesystem->mkdir(dirname($directory));
                // Download the boilerplate first, without its dependency install or
                // post-create scripts. Configure metadata before the first install.
                $this->runner->run(array_merge($this->composer(), [
                    'create-project',
                    '--repository=' . json_encode(['type' => 'vcs', 'url' => self::TEMPLATE_REPOSITORY], JSON_THROW_ON_ERROR),
                    '--prefer-dist', '--no-install', '--no-scripts', '--no-plugins',
                    '--no-interaction', '--remove-vcs',
                    self::TEMPLATE_PACKAGE, $directory, $options['template-version'] ?? 'dev-main',
                ]), dirname($directory), $output);
                $this->validateTemplate($directory);
            }
            $setup = [PHP_BINARY, $directory . '/bin/module', 'setup', '--name=' . $name];
            foreach (['namespace', 'display-name', 'author', 'description', 'toolkit-path'] as $option) {
                if (isset($options[$option])) {
                    $setup[] = '--' . $option . '=' . $options[$option];
                }
            }
            if (isset($options['module-version'])) {
                $setup[] = '--version=' . $options['module-version'];
            }
            if (!$interactive) {
                $setup[] = '--no-interaction';
            }
            $this->runner->run($setup, $directory, $output, $interactive);
            if (!($options['no-install'] ?? false)) {
                $this->runner->run(array_merge($this->composer(), ['install', '--prefer-dist', '--no-interaction']), $directory, $output);
                $this->runner->run(array_merge($this->composer(), ['install', '--working-dir=tools', '--prefer-dist', '--no-interaction']), $directory, $output);
            }
        } catch (\Throwable $exception) {
            throw new \RuntimeException('Module creation failed: ' . $exception->getMessage() . (is_dir($directory) ? "\nThe new directory is retained for inspection: " . $directory : ''), 0, $exception);
        }

        return $directory;
    }

    private function copyTemplate(string $source, string $target): void
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir($target);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $item): bool => !$item->isLink()
                && !in_array($item->getFilename(), ['.git', 'vendor', 'node_modules', 'dist', '.phpunit.cache'], true)
                && !str_starts_with($item->getFilename(), '.env')
        ), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $file) {
            $destination = $target . substr($file->getPathname(), strlen($source));
            if ($file->isDir()) {
                $filesystem->mkdir($destination);
            } else {
                $filesystem->copy($file->getPathname(), $destination);
            }
        }
    }

    private function validateTemplate(string $directory): void
    {
        $manifest = $this->manifest($directory);
        if (($manifest['name'] ?? '') !== self::TEMPLATE_PACKAGE
            || ($manifest['extra']['prestashop-module']['name'] ?? '') !== 'examplemodule'
            || !is_file($directory . '/bin/module') || !is_file($directory . '/tools/src/Setup.php')) {
            throw new \InvalidArgumentException('Use an unconfigured, current prestashop-module boilerplate. Until it is published, select its checkout with --template-path.');
        }
    }

    private function manifest(string $directory): array
    {
        if (!is_file($directory . '/composer.json')) {
            return [];
        }

        return json_decode(file_get_contents($directory . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function composer(): array
    {
        $binary = getenv('COMPOSER_BINARY') ?: 'composer';

        return is_file($binary) ? [PHP_BINARY, $binary] : [$binary];
    }

    /** Resolve existing ancestors too, so symlinked parents cannot recurse into a template. */
    private function resolvePath(string $path): string
    {
        $absolute = Path::makeAbsolute($path, getcwd());
        $ancestor = $absolute;
        $parts = [];
        while (!file_exists($ancestor) && !is_link($ancestor)) {
            array_unshift($parts, basename($ancestor));
            $ancestor = dirname($ancestor);
        }
        $real = realpath($ancestor);

        return Path::join($real === false ? $ancestor : $real, ...$parts);
    }
}
