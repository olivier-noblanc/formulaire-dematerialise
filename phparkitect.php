<?php
declare(strict_types=1);

use Arkitect\ClassSet;
use Arkitect\CLI\Config;
use Arkitect\Expression\ForClasses\HaveNameMatching;
use Arkitect\Expression\ForClasses\NotHaveDependencyOutsideNamespace;
use Arkitect\Expression\ForClasses\ResideInOneOfTheseNamespaces;
use Arkitect\Rules\Rule;

return static function (Config $config): void {

    $srcSet    = ClassSet::fromDir(__DIR__ . '/src');
    $pagesSet  = ClassSet::fromDir(__DIR__ . '/pages');
    $libSet    = ClassSet::fromDir(__DIR__ . '/lib');
    $testsSet  = ClassSet::fromDir(__DIR__ . '/tests');

    // ── R1 : Controllers must follow naming convention ──────────
    $rules[] = Rule::allClasses()
        ->that(new ResideInOneOfTheseNamespaces('App\Controller'))
        ->should(new HaveNameMatching('*Controller'))
        ->because('all controllers must end with Controller');

    // ── R2 : Services must follow naming convention ─────────────
    $rules[] = Rule::allClasses()
        ->that(new ResideInOneOfTheseNamespaces('App\Services'))
        ->should(new HaveNameMatching('*Service'))
        ->because('all services must end with Service');

    // ── R3 : Repositories must follow naming convention ─────────
    $rules[] = Rule::allClasses()
        ->that(new ResideInOneOfTheseNamespaces('App\Repository'))
        ->should(new HaveNameMatching('*Repository'))
        ->because('all repositories must end with Repository');

    // ── R4 : Domain services must not depend on controllers ─────
    $rules[] = Rule::allClasses()
        ->that(new ResideInOneOfTheseNamespaces('App\Mail', 'App\Token', 'App\Workflow', 'App\Webhook', 'App\Attachment', 'App\Auth', 'App\Security', 'App\Audit', 'App\Validation', 'App\Export', 'App\Settings', 'App\Render'))
        ->should(new NotHaveDependencyOutsideNamespace('App', ['App\Core', 'PHPMailer']))
        ->because('domain services must not depend on controllers or procedural code');

    // ── R5 : Repositories must not depend on controllers ────────
    $rules[] = Rule::allClasses()
        ->that(new ResideInOneOfTheseNamespaces('App\Repository'))
        ->should(new NotHaveDependencyOutsideNamespace('App\Repository', ['App\Core', 'App\Contract']))
        ->because('repositories must only depend on their own domain and contracts');

    $config->add($srcSet, ...$rules);
};
