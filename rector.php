<?php

declare(strict_types=1);

use App\Rector\ReplaceMagicStringWithEnumRector;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withSets([
        LevelSetList::UP_TO_PHP_85,
    ])
    ->withPhpSets(php85: true)
    ->withRules([
        ReplaceMagicStringWithEnumRector::class,
    ]);
