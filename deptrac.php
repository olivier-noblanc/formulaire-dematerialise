<?php

use Deptrac\Deptrac\Contract\Config\Collector\ClassLikeConfig;
use Deptrac\Deptrac\Contract\Config\Collector\DirectoryConfig;
use Deptrac\Deptrac\Contract\Config\DeptracConfig;
use Deptrac\Deptrac\Contract\Config\Layer;
use Deptrac\Deptrac\Contract\Config\Ruleset;

return static function (DeptracConfig $config): void {
    $config
        ->paths('./src')
        ->layers(
            // ── Foundation ──────────────────────────────────
            $enum = Layer::withName('Enum')->collectors(
                ClassLikeConfig::create('App\\Enum\\.*'),
            ),

            $contract = Layer::withName('Contract')->collectors(
                ClassLikeConfig::create('App\\Contract\\.*'),
            ),

            // ── Infrastructure ──────────────────────────────
            $infrastructure = Layer::withName('Infrastructure')->collectors(
                ClassLikeConfig::create('App\\Core\\Database.*'),
                ClassLikeConfig::create('App\\Core\\App'),
                ClassLikeConfig::create('App\\Core\\SlugHelper'),
            ),

            // ── Data layer ──────────────────────────────────
            $repository = Layer::withName('Repository')->collectors(
                ClassLikeConfig::create('App\\Repository\\.*'),
            ),

            // ── Business logic ──────────────────────────────
            $service = Layer::withName('Service')->collectors(
                ClassLikeConfig::create('App\\Auth\\.*'),
                ClassLikeConfig::create('App\\Settings\\.*'),
                ClassLikeConfig::create('App\\Security\\.*'),
                ClassLikeConfig::create('App\\Cache\\.*'),
                ClassLikeConfig::create('App\\Mail\\.*'),
                ClassLikeConfig::create('App\\Audit\\.*'),
                ClassLikeConfig::create('App\\Token\\.*'),
                ClassLikeConfig::create('App\\Workflow\\.*'),
                ClassLikeConfig::create('App\\Forms\\.*'),
                ClassLikeConfig::create('App\\Export\\.*'),
                ClassLikeConfig::create('App\\Rgpd\\.*'),
                ClassLikeConfig::create('App\\Validation\\.*'),
                ClassLikeConfig::create('App\\Email\\.*'),
                ClassLikeConfig::create('App\\Persona\\.*'),
                ClassLikeConfig::create('App\\Stats\\.*'),
                ClassLikeConfig::create('App\\Cron\\.*'),
                ClassLikeConfig::create('App\\Attachment\\.*'),
            ),

            // ── Presentation ────────────────────────────────
            $controller = Layer::withName('Controller')->collectors(
                ClassLikeConfig::create('App\\Controller\\.*'),
            ),

            $render = Layer::withName('Render')->collectors(
                ClassLikeConfig::create('App\\Render\\.*'),
            ),
        )
        ->rulesets(
            // Enum: isolate
            Ruleset::forLayer($enum),

            // Contract: can depend on Enum
            Ruleset::forLayer($contract)->accesses($enum),

            // Infrastructure: depends on Enum, Contract, Service, Repository, Render (DI container wires everything)
            Ruleset::forLayer($infrastructure)->accesses($enum, $contract, $service, $repository, $render),

            // Repository: depends on Infrastructure, Enum, Contract
            Ruleset::forLayer($repository)->accesses($infrastructure, $enum, $contract),

            // Service: depends on Repository, Infrastructure, Enum, Contract, Render
            Ruleset::forLayer($service)->accesses($repository, $infrastructure, $enum, $contract, $render),

            // Render: depends on Service, Infrastructure, Enum, Contract (NOT Repository directly)
            Ruleset::forLayer($render)->accesses($service, $infrastructure, $enum, $contract),

            // Controller: depends on Service, Repository, Render, Infrastructure, Enum, Contract
            Ruleset::forLayer($controller)->accesses($service, $repository, $render, $infrastructure, $enum, $contract),
        );
};
