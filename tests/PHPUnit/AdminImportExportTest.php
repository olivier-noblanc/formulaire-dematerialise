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
    private function createSourceForm(string $hint = ''): string
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
            'hint' => $hint,
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

    /**
     * D5 — un hint purement numérique doit survivre au round-trip
     * export → import (il était vidé à l'import, et FormJsonValidator le
     * rejetait comme erreur bloquante : perte de donnée à la réimportation).
     */
    public function testRoundTripPreservesNumericHint(): void
    {
        $sourceId = $this->createSourceForm('2');
        $_POST['form_id'] = $sourceId;
        $exported = AdminImportExportHandler::handleExportForm();
        self::assertIsString($exported['json_output']);
        $json = json_decode($exported['json_output'], true);
        self::assertIsArray($json);
        self::assertSame('2', $json['fields'][0]['hint'], 'D5 : le hint numérique doit être exporté intact');
        $this->deleteForm($sourceId);

        $_POST['json_data'] = $exported['json_output'];
        $imported = AdminImportExportHandler::handleImportForm();
        self::assertArrayHasKey('redirect', $imported, 'Import bloqué : ' . ($imported['error'] ?? ''));
        $newId = $this->extractRedirectFormId((string) $imported['redirect']);
        try {
            $fields = $this->repo->getFields($newId);
            self::assertSame(
                '2',
                $fields[0]['hint'],
                'D5 : le hint numérique ne doit pas être vidé à l\'import'
            );
        } finally {
            $this->deleteForm($newId);
        }
    }

    // ── Garde-fous (comportement conservé) ─────────────────────

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

    /**
     * R6 — une condition fournie sous forme de chaîne JSON avec un opérateur
     * inconnu doit être rejetée comme son équivalent objet (asymétrie corrigée).
     */
    public function testImportOfStringConditionWithUnknownOpIsBlocked(): void
    {
        $_POST['json_data'] = json_encode([
            'form' => ['label' => 'Test RI bad op string ' . uniqid()],
            'fields' => [
                ['label' => 'Type de demande', 'field_type' => 'text', 'field_name' => 'type_demande', 'filled_by' => 'validator'],
            ],
            'steps' => [
                [
                    'label' => 'Étape X',
                    'ordre' => 1,
                    'actif' => 1,
                    'recipients' => ['manager@exemple.invalid'],
                    'condition' => '{"field":"type_demande","op":"bogus_op","value":"A"}',
                ],
            ],
        ]);
        $imported = AdminImportExportHandler::handleImportForm();
        self::assertArrayHasKey('error', $imported, 'Une condition chaîne JSON avec op inconnu doit bloquer l\'import');
        self::assertArrayNotHasKey('redirect', $imported, 'Aucun formulaire ne doit être créé');
        self::assertStringContainsString('bogus_op', (string) ($imported['preserved_json'] ?? ''));
    }

    /**
     * R6 — un opérateur valide sous forme de chaîne JSON reste accepté et la
     * value tableau de l'op "in" est préservée (pas de régression B-FIX3c).
     */
    public function testImportStringConditionWithValidOpPreservesArrayValue(): void
    {
        $_POST['json_data'] = json_encode([
            'form' => ['label' => 'Test RI string op in ' . uniqid()],
            'fields' => [
                ['label' => 'Type de demande', 'field_type' => 'text', 'field_name' => 'type_demande', 'filled_by' => 'validator'],
            ],
            'steps' => [
                [
                    'label' => 'Étape Y',
                    'ordre' => 1,
                    'actif' => 1,
                    'recipients' => ['manager@exemple.invalid'],
                    'condition' => '{"field":"type_demande","op":"in","value":["A","B","C"]}',
                ],
            ],
        ]);
        $imported = AdminImportExportHandler::handleImportForm();
        self::assertArrayHasKey('redirect', $imported, 'Import bloqué : ' . ($imported['error'] ?? ''));
        $newId = $this->extractRedirectFormId((string) $imported['redirect']);
        try {
            $steps = $this->repo->getSteps($newId);
            self::assertCount(1, $steps);
            $cond = json_decode($steps[0]['condition'], true);
            self::assertSame('in', $cond['op']);
            self::assertSame(['A', 'B', 'C'], $cond['value'], 'La value tableau doit rester un tableau en base');
        } finally {
            $this->deleteForm($newId);
        }
    }

    /**
     * R6 — round-trip saboté : la condition d'étape (objet légitime à l'export)
     * est remplacée par une chaîne JSON à op inconnu. L'import doit être rejeté
     * proprement (erreur, pas de formulaire créé, JSON préservé pour correction).
     */
    public function testRoundTripImportRejectsTamperedStringCondition(): void
    {
        $sourceId = $this->createSourceForm();
        $_POST['form_id'] = $sourceId;
        $exported = AdminImportExportHandler::handleExportForm();
        self::assertIsString($exported['json_output']);
        $this->deleteForm($sourceId);

        $json = json_decode($exported['json_output'], true);
        self::assertIsArray($json);
        $json['steps'][0]['condition'] = '{"field":"type_demande","op":"bogus_op","value":"A"}';

        $_POST['json_data'] = json_encode($json);
        $imported = AdminImportExportHandler::handleImportForm();

        self::assertArrayHasKey('error', $imported, 'Un op inconnu sous forme de chaîne JSON doit bloquer l\'import');
        self::assertArrayNotHasKey('redirect', $imported, 'Aucun formulaire ne doit être créé (pas de drop silencieux)');
        self::assertStringContainsString('bogus_op', (string) ($imported['preserved_json'] ?? ''));
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

    // ── R1 : rollback sur exception non-PDO ───────────────────

    /**
     * R1 (audit 2026-09-14) : une exception non-PDO levée après
     * beginTransaction() doit (1) être propagée — jamais avalée — et
     * (2) laisser la transaction rollbackée, sans écriture partielle.
     *
     * Reproduction : la connexion SQLite partagée (Database::$pdoTest) est
     * remplacée par un double \PDO dont commit() lève une \RuntimeException
     * (non-PDO). L'import a déjà inséré le formulaire dans la transaction
     * ouverte ; sans rollback garanti, l'écriture resterait persistée.
     */
    public function testNonPdoExceptionDuringImportRollsBackAndPropagates(): void
    {
        $container = App::getInstance();
        $db = $container->get(Database::class);
        $realPdo = $db->getPdo(); // force l'init de la connexion test

        $pdoTestProp = new \ReflectionProperty(Database::class, 'pdoTest');
        $originalPdo = $pdoTestProp->getValue($db);
        self::assertInstanceOf(\PDO::class, $originalPdo);

        $testDbPath = (string) ($GLOBALS['_test_db_path'] ?? dirname(__DIR__, 2) . '/db/workflow_test.db');
        $label = 'Test RI non-PDO ' . uniqid();

        $throwingPdo = new class ('sqlite:' . $testDbPath) extends \PDO {
            public int $rowsVisibleAtCommit = 0;

            public function __construct(string $dsn)
            {
                parent::__construct($dsn);
                $this->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                $this->exec('PRAGMA foreign_keys = ON');
                $this->exec('PRAGMA busy_timeout = 5000');
            }

            public function commit(): bool
            {
                // Preuve que l'import a bien écrit dans la transaction avant
                // l'échec (sinon le test de rollback serait vide).
                $stmt = $this->query('SELECT COUNT(*) FROM forms');
                $this->rowsVisibleAtCommit = $stmt !== false ? (int) $stmt->fetchColumn() : 0;
                throw new \RuntimeException('Panne non-PDO simulée au commit de l\'import');
            }
        };
        $throwingPdo->exec('DELETE FROM forms WHERE label = ' . $throwingPdo->quote($label));

        $pdoTestProp->setValue($db, $throwingPdo);
        $_POST['json_data'] = json_encode([
            'form' => ['label' => $label],
            'fields' => [],
            'steps' => [],
        ]);

        try {
            $thrown = null;
            try {
                AdminImportExportHandler::handleImportForm();
            } catch (\RuntimeException $e) {
                $thrown = $e;
            }

            self::assertInstanceOf(
                \RuntimeException::class,
                $thrown,
                'R1 : une exception non-PDO doit être propagée (surfacée), jamais avalée'
            );
            self::assertSame('Panne non-PDO simulée au commit de l\'import', $thrown->getMessage());
            self::assertGreaterThan(
                0,
                $throwingPdo->rowsVisibleAtCommit,
                'Prémisse : l\'import doit avoir écrit dans la transaction avant l\'échec du commit'
            );
            self::assertFalse(
                $throwingPdo->inTransaction(),
                'R1 : la transaction doit être rollbackée après l\'exception non-PDO'
            );
        } finally {
            // Filet anti-pollution (si le correctif R1 était absent/cassé) puis
            // restauration de la connexion d'origine.
            if ($throwingPdo->inTransaction()) {
                $throwingPdo->rollBack();
            }
            $pdoTestProp->setValue($db, $originalPdo);
        }

        // Vérification sur la connexion d'origine : aucune écriture partielle.
        $stmt = $realPdo->prepare('SELECT COUNT(*) FROM forms WHERE label = ?');
        $stmt->execute([$label]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'R1 : l\'import ne doit pas persister d\'écriture partielle');
    }
}
