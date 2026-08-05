<?php

declare(strict_types=1);

use App\Rector\ReplaceMagicStringWithEnumRector;
use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/phpstan-rules',
    ])
    ->withSets([
        LevelSetList::UP_TO_PHP_85,
        SetList::TYPE_DECLARATION,
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::STRICT_BOOLEANS,
        SetList::EARLY_RETURN,
    ])
    ->withPhpSets(php85: true)
    ->withRules([
        ReplaceMagicStringWithEnumRector::class,
    ]);
