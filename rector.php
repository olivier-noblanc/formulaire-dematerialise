<?php

declare(strict_types=1);

use App\Rector\ReplaceMagicStringWithEnumRector;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])
    ->withSets([
        LevelSetList::UP_TO_PHP_85,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::NAMING,
    ])
    ->withPhpSets(php83: true)
    ->withRules([
        ReplaceMagicStringWithEnumRector::class,
    ]);
