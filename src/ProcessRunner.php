<?php

declare(strict_types=1);

namespace Dartmoon\PrestaShopInstaller;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class ProcessRunner
{
    public function run(array $command, string $directory, OutputInterface $output, bool $interactive = false): void
    {
        // Composer home remains available for credentials and configuration.
        // Project operations always use the destination manifest
        // and vendor directory, not inherited COMPOSER/VENDOR_DIR overrides.
        $process = new Process($command, $directory, [
            'COMPOSER' => 'composer.json',
            'COMPOSER_VENDOR_DIR' => false,
            'COMPOSER_BIN_DIR' => false,
        ]);
        $process->setTimeout(900);
        $tty = $interactive && defined('STDIN') && stream_isatty(STDIN) && Process::isTtySupported();
        if ($tty) {
            $process->setTty(true);
        }
        $process->mustRun(static function (string $type, string $buffer) use ($output): void {
            $output->write($buffer, false, OutputInterface::OUTPUT_RAW);
        });
    }
}
