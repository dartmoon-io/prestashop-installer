<?php

/**
 * Copyright (c) 2023-2026 Dartmoon S.r.l. <hello@dartmoon.io>
 * SPDX-License-Identifier: MIT
 * See LICENSE for the full license text.
 */

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

$config = new PrestaShop\CodingStandards\CsFixer\Config();
$finder = PhpCsFixer\Finder::create()->ignoreDotFiles(false)->in(__DIR__)
    ->exclude(['vendor', 'node_modules', 'dist', '.git', '.phpunit.cache'])
    ->notPath('tools/vendor')
    ->name('*.php')->append([__DIR__ . '/bin/prestashop']);

return $config->setFinder($finder)->setUsingCache(false);
