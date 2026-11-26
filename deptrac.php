<?php

declare(strict_types=1);

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
            // Objets-valeurs de validation (format d'ordre, d'adresse) : purs,
            // sans dépendance, donc au même niveau de fondation que Enum/Contract
            // et accessibles depuis toutes les couches (Repository inclus).
            $valueObject = Layer::withName('ValueObject')->collectors(
                ClassLikeConfig::create(\App\Validation\OrderValue::class),
                ClassLikeConfig::create(\App\Validation\RecipientAddress::class),
            ),

            // ── Infrastructure ──────────────────────────────
            $infrastructure = Layer::withName('Infrastructure')->collectors(
                ClassLikeConfig::create('App\\Core\\Database.*'),
                ClassLikeConfig::create(\App\Core\App::class),
                ClassLikeConfig::create(\App\Core\SlugHelper::class),
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
                ClassLikeConfig::create(\App\Validation\ValidationService::class),
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

            // ValueObject: isolate (aucune dépendance sortante)
            Ruleset::forLayer($valueObject),

            // Infrastructure: depends on Enum, Contract, ValueObject, Service, Repository, Render (DI container wires everything)
            Ruleset::forLayer($infrastructure)->accesses($enum, $contract, $valueObject, $service, $repository, $render),

            // Repository: depends on Infrastructure, Enum, Contract, ValueObject
            Ruleset::forLayer($repository)->accesses($infrastructure, $enum, $contract, $valueObject),

            // Service: depends on Repository, Infrastructure, Enum, Contract, ValueObject, Render
            Ruleset::forLayer($service)->accesses($repository, $infrastructure, $enum, $contract, $valueObject, $render),

            // Render: depends on Service, Infrastructure, Enum, Contract, ValueObject (NOT Repository directly)
            Ruleset::forLayer($render)->accesses($service, $infrastructure, $enum, $contract, $valueObject),

            // Controller: depends on Service, Repository, Render, Infrastructure, Enum, Contract, ValueObject
            Ruleset::forLayer($controller)->accesses($service, $repository, $render, $infrastructure, $enum, $contract, $valueObject),
        );
};
