<?php
declare(strict_types=1);

/**
 * Sample forms populator — Wrapper backward-compatible.
 *
 * La logique métier est dans App\Forms\SampleFormsService.
 *
 * @package lib
 * @deprecated Utilisez App\Forms\SampleFormsService directement.
 */

function populate_sample_forms(PDO $pdo): string {
    $service = new \App\Forms\SampleFormsService(\App\Core\App::db());
    return $service->populate();
}
