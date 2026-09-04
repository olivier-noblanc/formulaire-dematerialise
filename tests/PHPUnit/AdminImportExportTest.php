<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Core\App;
use App\Core\Database;
use App\Controller\AdminImportExportHandler;
use App\Repository\FormRepository;

/**
 * Tests régression import/export de formulaires (AdminImportExportHandler).
 *
 * Bugs Oracle (2026-09-01) :
 * - B-FIX3a : l'export perdait forms.relance_delai_h / forms.relance_max
 *   (config de relance par formulaire) → import impossible à l'identique.
 * - B-FIX3b : l'import ne restaurait pas relance_delai_h / relance_max.
 * - B-FIX3b : le round-trip de la condition avec op "in" et value tableau
 *   était cassé : (string) sur un array produisait "Array" en base.
 */
final class AdminImportExportTest extends TestCase
{
    private FormRepository $repo;

    protected function setUp(): void
    {
        $this->repo = App::getInstance()->get(FormRepository::class);
    }

    protected function tearDown(): void
    {
        $_POST = [];
    }

    /**
     * Crée un formulaire source : relance personnalisée, 1 champ demandeur,
     * 1 champ validateur (cible des conditions), 2 steps (condition eq +
     * condition in avec value tableau), 1 destinataire chacun.
     */
    private function createSourceForm(): string
    {
        $id = $this->repo->create([
            'label' => 'Test RI Export ' . uniqid(),
            'slug' => 'test-ri-' . uniqid(),
            'description' => 'Round-trip import/export',
            'relance_delai_h' => 72,
            'relance_max' => 5,
        ]);
        $this->repo->createField([
            'form_id' => $id,
            'label' => 'Nom du demandeur',
            'field_type' => 'text',
            'field_name' => 'nom',
            'required' => 1,
            'ordre' => 1,
        ]);
        // champ validateur : cible des conditions d'étape (exigé par FormJsonValidator)
        $this->repo->createField([
            'form_id' => $id,
            'label' => 'Type de demande',
            'field_type' => 'select',
            'field_name' => 'type_demande',
            'options' => json_encode(['A', 'B', 'C'], JSON_UNESCAPED_UNICODE),
            'required' => 0,
            'ordre' => 2,
            'filled_by' => 'validator',
        ]);
        $step1 = $this->repo->createStep([
            'form_id' => $id,
            'label' => 'Validation manager',
            'ordre' => 1,
            'actif' => 1,
            'condition' => '{"field":"type_demande","op":"eq","value":"A"}',
        ]);
        $this->repo->createRecipient($step1, 'manager@exemple.invalid');
        $step2 = $this->repo->createStep([
            'form_id' => $id,
            'label' => 'Validation RH',
            'ordre' => 2,
            'actif' => 1,
            'condition' => '{"field":"type_demande","op":"in","value":["A","B","C"]}',
        ]);
        $this->repo->createRecipient($step2, 'rh@exemple.invalid');
        return $id;
    }

    private function deleteForm(string $formId): void
    {
        $this->repo->deleteCascade($formId);
    }

    private function extractRedirectFormId(string $redirect): string
    {
        $query = (string) parse_url($redirect, PHP_URL_QUERY);
        parse_str($query, $params);
        self::assertArrayHasKey('form_id', $params);
        return (string) $params['form_id'];
    }

    // ── Export ────────────────────────────────────────────────

    public function testExportIncludesRelanceConfig(): void
    {
        $sourceId = $this->createSourceForm();
        $_POST['form_id'] = $sourceId;
        try {
            $result = AdminImportExportHandler::handleExportForm();
            self::assertArrayNotHasKey('error', $result, $result['error'] ?? '');
            self::assertIsString($result['json_output']);
            $json = json_decode($result['json_output'], true);
            self::assertIsArray($json);
            self::assertSame(72, $json['form']['relance_delai_h'], 'B-FIX3a : relance_delai_h doit être exporté');
            self::assertSame(5, $json['form']['relance_max'], 'B-FIX3a : relance_max doit être exporté');
        } finally {
            $this->deleteForm($sourceId);
        }
    }

    public function testExportPreservesConditionInArrayValue(): void
    {
        $sourceId = $this->createSourceForm();
        $_POST['form_id'] = $sourceId;
        try {
            $result = AdminImportExportHandler::handleExportForm();
            $json = json_decode((string) $result['json_output'], true);
            self::assertSame(
                ['field' => 'type_demande', 'op' => 'in', 'value' => ['A', 'B', 'C']],
                $json['steps'][1]['condition'],
                'B-FIX3c : la condition op "in" avec value tableau doit être exportée intacte'
            );
        } finally {
            $this->deleteForm($sourceId);
        }
    }

    // ── Round-trip : export → import ──────────────────────────

    public function testImportRestoresRelanceConfig(): void
    {
        $sourceId = $this->createSourceForm();
        $_POST['form_id'] = $sourceId;
        $exported = AdminImportExportHandler::handleExportForm();
        self::assertIsString($exported['json_output']);
        $this->deleteForm($sourceId);

        $_POST['json_data'] = $exported['json_output'];
        $imported = AdminImportExportHandler::handleImportForm();
        self::assertArrayHasKey('redirect', $imported, 'Import bloqué : ' . ($imported['error'] ?? ''));
        $newId = $this->extractRedirectFormId((string) $imported['redirect']);
        try {
            $row = $this->repo->findById($newId);
            self::assertNotNull($row);
            self::assertSame(72, (int) $row['relance_delai_h'], 'B-FIX3b : relance_delai_h doit être restauré à l\'import');
            self::assertSame(5, (int) $row['relance_max'], 'B-FIX3b : relance_max doit être restauré à l\'import');
        } finally {
            $this->deleteForm($newId);
        }
    }

    public function testImportPreservesConditionInArrayValue(): void
    {
        $sourceId = $this->createSourceForm();
        $_POST['form_id'] = $sourceId;
        $exported = AdminImportExportHandler::handleExportForm();
        self::assertIsString($exported['json_output']);
        $this->deleteForm($sourceId);

        $_POST['json_data'] = $exported['json_output'];
        $imported = AdminImportExportHandler::handleImportForm();
        self::assertArrayHasKey('redirect', $imported, 'Import bloqué : ' . ($imported['error'] ?? ''));
        $newId = $this->extractRedirectFormId((string) $imported['redirect']);
        try {
            $steps = $this->repo->getSteps($newId);
            self::assertCount(2, $steps);

            $cond1 = json_decode($steps[0]['condition'], true);
            self::assertSame('eq', $cond1['op']);
            self::assertSame('A', $cond1['value']);

            $cond2 = json_decode($steps[1]['condition'], true);
            self::assertSame('in', $cond2['op']);
            self::assertSame(
                ['A', 'B', 'C'],
                $cond2['value'],
                'B-FIX3c : value tableau de l\'op "in" doit rester un tableau en base (pas "Array")'
            );
        } finally {
            $this->deleteForm($newId);
        }
    }

    public function testRoundTripExportImportExportIsStable(): void
    {
        $sourceId = $this->createSourceForm();
        $_POST['form_id'] = $sourceId;
        $first = AdminImportExportHandler::handleExportForm();
        self::assertIsString($first['json_output']);
        $this->deleteForm($sourceId);

        $_POST['json_data'] = $first['json_output'];
        $imported = AdminImportExportHandler::handleImportForm();
        self::assertArrayHasKey('redirect', $imported, 'Import bloqué : ' . ($imported['error'] ?? ''));
        $newId = $this->extractRedirectFormId((string) $imported['redirect']);
        try {
            // Ré-exporter le formulaire importé : form + steps doivent être équivalents
            $_POST['form_id'] = $newId;
            $reExported = AdminImportExportHandler::handleExportForm();
            self::assertIsString($reExported['json_output']);
            $json1 = json_decode($first['json_output'], true);
            $json2 = json_decode((string) $reExported['json_output'], true);
            self::assertSame($json1['form']['relance_delai_h'], $json2['form']['relance_delai_h']);
            self::assertSame($json1['form']['relance_max'], $json2['form']['relance_max']);
            self::assertSame($json1['steps'], $json2['steps'], 'Les steps (label, ordre, actif, recipients, condition) doivent être identiques au round-trip');
        } finally {
            $this->deleteForm($newId);
        }
    }

    // ── Garde-fous (comportement conservé) ────────────────────

    public function testImportOfConditionWithUnknownOpIsBlocked(): void
    {
        $_POST['json_data'] = json_encode([
            'form' => ['label' => 'Test RI bad op ' . uniqid()],
            'fields' => [
                ['label' => 'Type de demande', 'field_type' => 'text', 'field_name' => 'type_demande', 'filled_by' => 'validator'],
            ],
            'steps' => [
                [
                    'label' => 'Étape X',
                    'ordre' => 1,
                    'actif' => 1,
                    'recipients' => ['manager@exemple.invalid'],
                    'condition' => ['field' => 'type_demande', 'op' => 'bogus_op', 'value' => 'A'],
                ],
            ],
        ]);
        $imported = AdminImportExportHandler::handleImportForm();
        // FormJsonValidator rejette l'op inconnu → l'import est bloqué (pas de drop silencieux)
        self::assertArrayHasKey('error', $imported, 'Une condition avec op inconnu doit bloquer l\'import');
        self::assertArrayNotHasKey('redirect', $imported);
        if (isset($imported['preserved_json'])) {
            self::assertStringContainsString('bogus_op', $imported['preserved_json']);
        }
    }

    public function testImportWithoutRelanceKeepsDefaults(): void
    {
        $_POST['json_data'] = json_encode([
            'form' => ['label' => 'Test RI no relance ' . uniqid()],
            'fields' => [],
            'steps' => [],
        ]);
        $imported = AdminImportExportHandler::handleImportForm();
        self::assertArrayHasKey('redirect', $imported);
        $newId = $this->extractRedirectFormId((string) $imported['redirect']);
        try {
            $config = $this->repo->getRelanceConfig($newId);
            self::assertSame(48, $config['relance_delai_h'], 'Sans relance dans le JSON, fallback 48 h');
            self::assertSame(3, $config['relance_max'], 'Sans relance dans le JSON, fallback 3 relances');
        } finally {
            $this->deleteForm($newId);
        }
    }
}