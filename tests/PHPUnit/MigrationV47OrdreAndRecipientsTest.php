<?php
declare(strict_types=1);

namespace App\Tests;

use App\Validation\OrderValue;
use App\Validation\RecipientAddress;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * v47 — ordres entiers >= 0 et destinataires durcis.
 *
 * La migration est fail-closed : seules les valeurs tronquées documentées
 * sont réparées, et seulement quand le libellé d'étape confirme la
 * correspondance. La rotation legacy `form_fields` est réparée uniquement
 * pour sa signature exacte (`required >= 2`, `ordre = 0`, `card_group`
 * contenant un hint connu, `hint = ''`) ; toute autre valeur invalide ou
 * rotation ambiguë bloque avant écriture.
 */
final class MigrationV47OrdreAndRecipientsTest extends TestCase
{
    /** @var list<string> */
    private array $dbPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->dbPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->dbPaths = [];
    }

    private function newDbPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'v47-contract-');
        self::assertNotFalse($path);
        $this->dbPaths[] = $path;
        return $path;
    }

    private function pdo(string $path): PDO
    {
        require_once dirname(__DIR__, 2) . '/classes/DatabaseMigrations.php';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }

    private function migratedPdo(string $path): PDO
    {
        $pdo = $this->pdo($path);
        db_migrate($pdo);
        return $pdo;
    }

    private function v46Pdo(string $path): PDO
    {
        $pdo = $this->pdo($path);
        $version = apply_schema_initial($pdo);
        $version = apply_migration_v43($pdo, $version);
        $version = apply_migration_v44($pdo, $version);
        $version = apply_migration_v45($pdo, $version);
        $version = apply_migration_v46($pdo, $version);
        self::assertSame(46, $version);
        return $pdo;
    }

    private function tableSql(PDO $pdo, string $table): string
    {
        return (string) $pdo->query(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='{$table}'"
        )->fetchColumn();
    }

    private function formId(PDO $pdo): string
    {
        return (string) $pdo->query('SELECT id FROM forms LIMIT 1')->fetchColumn();
    }

    /** @return array{0: string, 1: string} step id, recipient id */
    private function insertStepWithRecipient(PDO $pdo, string $stepLabel, string $email): array
    {
        $formId = $this->formId($pdo);
        $stepId = \generate_uuid();
        $pdo->prepare(
            "INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, 1, 1)"
        )->execute([$stepId, $formId, $stepLabel]);

        $recipientId = \generate_uuid();
        $pdo->prepare(
            'INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)'
        )->execute([$recipientId, $stepId, $email]);

        return [$stepId, $recipientId];
    }

    private function insertForm(PDO $pdo, string $slug): string
    {
        $formId = \generate_uuid();
        $pdo->prepare('INSERT INTO forms (id, slug, label) VALUES (?, ?, ?)')
            ->execute([$formId, $slug, 'Formulaire ' . $slug]);
        return $formId;
    }

    /**
     * Insère un champ en contournant la CHECK `required IN (0,1)` de la
     * baseline, nécessaire pour reproduire la rotation legacy.
     *
     * @param array<string, int|string> $values
     */
    private function insertLegacyField(PDO $pdo, string $formId, array $values): string
    {
        $fieldId = \generate_uuid();
        $pdo->exec('PRAGMA ignore_check_constraints = ON');
        $pdo->prepare(
            'INSERT INTO form_fields (id, form_id, label, field_name, required, ordre, card_group, hint) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $fieldId,
            $formId,
            (string) ($values['label'] ?? 'Champ legacy'),
            (string) ($values['field_name'] ?? ('champ_' . uniqid())),
            $values['required'] ?? 0,
            $values['ordre'] ?? 0,
            (string) ($values['card_group'] ?? ''),
            (string) ($values['hint'] ?? ''),
        ]);
        $pdo->exec('PRAGMA ignore_check_constraints = OFF');
        return $fieldId;
    }

    public function testFreshDatabaseCarriesOrderAndRecipientChecks(): void
    {
        $pdo = $this->migratedPdo($this->newDbPath());

        self::assertSame(48, (int) $pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn());
        self::assertStringContainsString("typeof(ordre) = 'integer'", $this->tableSql($pdo, 'form_fields'));
        self::assertStringContainsString("typeof(required) = 'integer'", $this->tableSql($pdo, 'form_fields'));
        self::assertStringContainsString("typeof(ordre) = 'integer'", $this->tableSql($pdo, 'steps'));
        self::assertStringContainsString("typeof(actif) = 'integer'", $this->tableSql($pdo, 'steps'));
        self::assertStringContainsString("LIKE '{{%}}'", $this->tableSql($pdo, 'step_recipients'));
    }

    public function testMigrationRepairsOnlyDocumentedTruncatedRecipientOnMatchingStep(): void
    {
        $pdo = $this->v46Pdo($this->newDbPath());
        [, $recipientId] = $this->insertStepWithRecipient($pdo, 'Validation RH', 'dreets-bfc.rh@');
        [, $dynamicId] = $this->insertStepWithRecipient($pdo, 'Validation service', '{{owner_email}}');

        self::assertSame(47, apply_migration_v47($pdo, 46));

        $stmt = $pdo->prepare('SELECT email FROM step_recipients WHERE id = ?');
        $stmt->execute([$recipientId]);
        self::assertSame('dreets-bfc.rh@dreets.gouv.fr', $stmt->fetchColumn());
        $stmt->execute([$dynamicId]);
        self::assertSame('{{owner_email}}', $stmt->fetchColumn());
    }

    public function testUnknownInvalidRecipientBlocksWithoutModification(): void
    {
        $pdo = $this->v46Pdo($this->newDbPath());
        [, $recipientId] = $this->insertStepWithRecipient($pdo, 'Validation RH', 'foo@');

        try {
            apply_migration_v47($pdo, 46);
            self::fail('La v47 doit refuser une valeur invalide non réparable.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('v47 blocked', $exception->getMessage());
        }

        $stmt = $pdo->prepare('SELECT email FROM step_recipients WHERE id = ?');
        $stmt->execute([$recipientId]);
        self::assertSame('foo@', $stmt->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 47')->fetchColumn());
        self::assertStringNotContainsString("typeof(ordre) = 'integer'", $this->tableSql($pdo, 'form_fields'));
    }

    public function testDocumentedTruncatedRecipientOnWrongStepBlocks(): void
    {
        $pdo = $this->v46Pdo($this->newDbPath());
        $this->insertStepWithRecipient($pdo, 'Autre étape', 'dreets-bfc.rh@');

        try {
            apply_migration_v47($pdo, 46);
            self::fail('La v47 ne doit pas réparer sans libellé d’étape exact.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('v47 blocked', $exception->getMessage());
        }

        self::assertSame(46, (int) $pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn());
    }

    public function testDatabaseRejectsEmptyAndTruncatedRecipients(): void
    {
        $pdo = $this->migratedPdo($this->newDbPath());
        [$stepId] = $this->insertStepWithRecipient($pdo, 'Validation test', 'validator@test.com');

        foreach (['', 'dreets-bfc.rh@', 'not-an-email', 'foo bar@test.com'] as $invalid) {
            try {
                $pdo->prepare('INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)')
                    ->execute([\generate_uuid(), $stepId, $invalid]);
                self::fail("La CHECK v47 doit refuser step_recipients.email = '{$invalid}'.");
            } catch (\PDOException $exception) {
                self::assertStringContainsString('constraint', strtolower($exception->getMessage()));
            }
        }
    }

    public function testDatabaseRejectsInvalidOrderValues(): void
    {
        $pdo = $this->migratedPdo($this->newDbPath());
        $formId = $this->formId($pdo);

        foreach ([-1, null, 'abc'] as $invalid) {
            try {
                $pdo->prepare(
                    'INSERT INTO form_fields (id, form_id, label, field_name, ordre) VALUES (?, ?, ?, ?, ?)'
                )->execute([\generate_uuid(), $formId, 'Champ', 'champ_' . uniqid(), $invalid]);
                self::fail('La CHECK v47 doit refuser form_fields.ordre invalide.');
            } catch (\PDOException $exception) {
                self::assertStringContainsString('constraint', strtolower($exception->getMessage()));
            }
        }

        foreach ([0, -1, null, 'abc'] as $invalid) {
            try {
                $pdo->prepare(
                    'INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, 1)'
                )->execute([\generate_uuid(), $formId, 'Étape', $invalid]);
                self::fail('La CHECK v47 doit refuser steps.ordre invalide.');
            } catch (\PDOException $exception) {
                self::assertStringContainsString('constraint', strtolower($exception->getMessage()));
            }
        }
    }

    public function testCardGroupHintRotationIsRepairedWithDeterministicOrder(): void
    {
        $pdo = $this->v46Pdo($this->newDbPath());
        $formId = $this->insertForm($pdo, 'rotation-' . uniqid());

        // Insertion volontairement dans le désordre : `required` porte
        // l'ancien ordre métier (22, puis 21, puis 23) et `ordre` porte
        // l'ancien card_group (0 = défaut, sinon texte du groupe).
        $second = $this->insertLegacyField($pdo, $formId, [
            'required' => 22,
            'ordre' => 'Affectation',
            'card_group' => 'Site et numéro de bureau',
        ]);
        $first = $this->insertLegacyField($pdo, $formId, [
            'required' => 21,
            'ordre' => 0,
            'card_group' => 'Préciser les droits ou le rôle valideur le cas échéant',
        ]);
        $third = $this->insertLegacyField($pdo, $formId, [
            'required' => 23,
            'ordre' => 0,
            'card_group' => 'Matériel attribué par défaut',
        ]);
        // Champ sans hint : card_group observé vide, hint observé vide.
        $fourth = $this->insertLegacyField($pdo, $formId, [
            'required' => 24,
            'ordre' => 0,
            'card_group' => '',
        ]);

        self::assertSame(47, apply_migration_v47($pdo, 46));

        $expected = [
            $first => [
                'ordre' => 1,
                'card_group' => 'Général',
                'hint' => 'Préciser les droits ou le rôle valideur le cas échéant',
            ],
            $second => [
                'ordre' => 2,
                'card_group' => 'Affectation',
                'hint' => 'Site et numéro de bureau',
            ],
            $third => [
                'ordre' => 3,
                'card_group' => 'Général',
                'hint' => 'Matériel attribué par défaut',
            ],
            $fourth => [
                'ordre' => 4,
                'card_group' => 'Général',
                'hint' => '',
            ],
        ];
        foreach ($expected as $fieldId => $row) {
            $stmt = $pdo->prepare('SELECT required, ordre, card_group, hint FROM form_fields WHERE id = ?');
            $stmt->execute([$fieldId]);
            $actual = $stmt->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($actual);
            self::assertSame(0, (int) $actual['required']);
            self::assertSame($row['ordre'], (int) $actual['ordre']);
            self::assertSame($row['card_group'], (string) $actual['card_group']);
            self::assertSame($row['hint'], (string) $actual['hint']);
        }

        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 47')->fetchColumn());
    }

    public function testHealthyOrderIsPreservedAndRotatedRowsAreAppended(): void
    {
        $pdo = $this->v46Pdo($this->newDbPath());
        $formId = $this->insertForm($pdo, 'mixte-' . uniqid());

        $healthy = $this->insertLegacyField($pdo, $formId, [
            'required' => 1,
            'ordre' => 5,
            'card_group' => 'Général',
        ]);
        $rotated = $this->insertLegacyField($pdo, $formId, [
            'required' => 21,
            'ordre' => 0,
            'card_group' => 'Matériel attribué par défaut',
        ]);

        self::assertSame(47, apply_migration_v47($pdo, 46));

        $stmt = $pdo->prepare('SELECT required, ordre, card_group, hint FROM form_fields WHERE id = ?');
        $stmt->execute([$healthy]);
        $healthyRow = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($healthyRow);
        self::assertSame(1, (int) $healthyRow['required']);
        self::assertSame(5, (int) $healthyRow['ordre']);
        self::assertSame('Général', (string) $healthyRow['card_group']);

        $stmt->execute([$rotated]);
        $rotatedRow = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($rotatedRow);
        self::assertSame(0, (int) $rotatedRow['required']);
        self::assertSame(6, (int) $rotatedRow['ordre']);
        self::assertSame('Matériel attribué par défaut', (string) $rotatedRow['hint']);
        self::assertSame('Général', (string) $rotatedRow['card_group']);

        $duplicates = (int) $pdo->query(
            'SELECT COUNT(*) FROM (SELECT ordre FROM form_fields WHERE form_id = '
            . $pdo->quote($formId) . ' GROUP BY ordre HAVING COUNT(*) > 1)'
        )->fetchColumn();
        self::assertSame(0, $duplicates);
    }

    public function testAmbiguousRotationWithNumericOrderBlocks(): void
    {
        $pdo = $this->v46Pdo($this->newDbPath());
        $fieldId = $this->insertLegacyField($pdo, $this->formId($pdo), [
            'required' => 21,
            'ordre' => 5,
            'card_group' => 'Matériel attribué par défaut',
        ]);

        try {
            apply_migration_v47($pdo, 46);
            self::fail('Une rotation ambiguë (ordre numérique positif) doit rester bloquante.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('v47 blocked', $exception->getMessage());
            self::assertStringContainsString($fieldId, $exception->getMessage());
        }

        self::assertSame(46, (int) $pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn());
        $stmt = $pdo->prepare('SELECT required, ordre, card_group, hint FROM form_fields WHERE id = ?');
        $stmt->execute([$fieldId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame(21, (int) $row['required']);
        self::assertSame(5, (int) $row['ordre']);
        self::assertSame('Matériel attribué par défaut', (string) $row['card_group']);
    }

    public function testDocumentedHintInCardGroupBlocksEvenWithValidRequired(): void
    {
        $pdo = $this->v46Pdo($this->newDbPath());
        $fieldId = \generate_uuid();
        $pdo->prepare(
            'INSERT INTO form_fields (id, form_id, label, field_name, required, ordre, card_group, hint) '
            . 'VALUES (?, ?, ?, ?, 1, 0, ?, \'\')'
        )->execute([
            $fieldId,
            $this->formId($pdo),
            'Champ legacy',
            'champ_legacy',
            'Matériel attribué par défaut',
        ]);

        try {
            apply_migration_v47($pdo, 46);
            self::fail('La v47 doit bloquer un hint documenté retrouvé dans card_group.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('card_group/hint rotation', $exception->getMessage());
            self::assertStringContainsString($fieldId, $exception->getMessage());
        }
    }

    public function testLegacyRequiredWithoutCardGroupRotationStillBlocks(): void
    {
        $pdo = $this->v46Pdo($this->newDbPath());
        $fieldId = \generate_uuid();
        $pdo->exec('PRAGMA ignore_check_constraints = ON');
        $pdo->prepare(
            'INSERT INTO form_fields (id, form_id, label, field_name, required, ordre, card_group, hint) '
            . 'VALUES (?, ?, ?, ?, 21, 5, \'Général\', \'\')'
        )->execute([$fieldId, $this->formId($pdo), 'Champ legacy', 'champ_legacy']);
        $pdo->exec('PRAGMA ignore_check_constraints = OFF');

        try {
            apply_migration_v47($pdo, 46);
            self::fail('La v47 doit bloquer un required legacy hors 0/1.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('form_fields.required', $exception->getMessage());
            self::assertStringContainsString($fieldId, $exception->getMessage());
        }

        self::assertSame(21, (int) $pdo->query('SELECT required FROM form_fields WHERE id = ' . $pdo->quote($fieldId))->fetchColumn());
    }

    /**
     * Preuve que le rebuild v47 mappe par NOM de colonne : la table source a
     * un ordre physique permuté (required/ordre/card_group/hint déplacés).
     * Un `INSERT ... SELECT *` aurait déplacé ces valeurs et fait échouer les
     * CHECK ; la liste explicite les conserve à leur colonne.
     */
    public function testV47RebuildMapsColumnsByNameWhenPhysicalOrderDiffers(): void
    {
        $pdo = $this->v46Pdo($this->newDbPath());

        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('BEGIN IMMEDIATE');
        $pdo->exec(
            'CREATE TABLE form_fields_shuffled (id TEXT PRIMARY KEY NOT NULL, form_id TEXT NOT NULL, '
            . "label TEXT NOT NULL, field_type TEXT NOT NULL DEFAULT 'text', field_name TEXT NOT NULL, options TEXT, "
            . "required INTEGER NOT NULL DEFAULT 0 CHECK (required IN (0,1)), ordre INTEGER DEFAULT 0, "
            . "card_group TEXT DEFAULT 'Général', hint TEXT DEFAULT '', filled_by TEXT NOT NULL DEFAULT 'demandeur', "
            . "validator_step TEXT DEFAULT '', visibility TEXT NOT NULL DEFAULT 'all', `condition` TEXT DEFAULT '', "
            . 'FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE)'
        );
        $columns = 'id, form_id, label, field_type, field_name, options, hint, required, ordre, '
            . 'card_group, filled_by, validator_step, visibility, `condition`';
        $pdo->exec("INSERT INTO form_fields_shuffled ({$columns}) SELECT {$columns} FROM form_fields");
        $pdo->exec('DROP TABLE form_fields');
        $pdo->exec('ALTER TABLE form_fields_shuffled RENAME TO form_fields');
        $pdo->exec('COMMIT');
        $pdo->exec('PRAGMA foreign_keys = ON');

        $formId = $this->insertForm($pdo, 'shuffle-' . uniqid());
        $fieldId = \generate_uuid();
        $pdo->prepare(
            'INSERT INTO form_fields (id, form_id, label, field_type, field_name, hint, required, ordre, card_group) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$fieldId, $formId, 'Champ permuté', 'text', 'champ_permute', 'Hint permuté', 1, 6, 'Groupe permuté']);

        self::assertSame(47, apply_migration_v47($pdo, 46));

        $stmt = $pdo->prepare('SELECT hint, required, ordre, card_group FROM form_fields WHERE id = ?');
        $stmt->execute([$fieldId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('Hint permuté', (string) $row['hint']);
        self::assertSame(1, (int) $row['required']);
        self::assertSame(6, (int) $row['ordre']);
        self::assertSame('Groupe permuté', (string) $row['card_group']);
    }

    public function testMigrationIsIdempotent(): void
    {
        $pdo = $this->migratedPdo($this->newDbPath());

        self::assertSame(47, apply_migration_v47($pdo, 47));
        db_migrate($pdo);

        self::assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM schema_version WHERE version = 47')->fetchColumn());
        self::assertSame(48, (int) $pdo->query('SELECT MAX(version) FROM schema_version')->fetchColumn());
    }

    public function testRecipientAddressContract(): void
    {
        foreach (['validator@test.com', 'a.b+c@exemple.invalid', '{{owner}}', '{{owner_email}}', '{{field_name}}'] as $valid) {
            self::assertTrue(RecipientAddress::isValid($valid), $valid);
        }
        foreach (['', 'foo@', 'not-an-email', 'foo bar@test.com', '{{}}', '{{bad token}}'] as $invalid) {
            self::assertFalse(RecipientAddress::isValid($invalid), $invalid);
        }

        self::assertSame(
            'dreets-bfc.rh@dreets.gouv.fr',
            RecipientAddress::knownRepair('dreets-bfc.rh@', 'Validation RH')
        );
        self::assertNull(RecipientAddress::knownRepair('dreets-bfc.rh@', 'Autre étape'));
        self::assertNull(RecipientAddress::knownRepair('unknown@', 'Validation RH'));
    }

    public function testOrderValueContract(): void
    {
        self::assertSame(0, OrderValue::assertValid(0));
        self::assertSame(12, OrderValue::assertValid('12'));

        try {
            OrderValue::assertValid(0, 'steps.ordre', 1);
            self::fail('steps.ordre doit être strictement positif.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }

        foreach ([-1, '-1', '1.5', '', 'abc', null] as $invalid) {
            try {
                OrderValue::assertValid($invalid);
                self::fail('OrderValue doit refuser une valeur invalide.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}