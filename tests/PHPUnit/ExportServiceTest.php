<?php
declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;
use App\Export\ExportService;
use App\Core\Database;
use App\Auth\AuthService;

final class ExportServiceTest extends TestCase
{
    private Database $db;
    private AuthService $auth;

    protected function setUp(): void
    {
        $this->db = \App\Core\App::getInstance()->get(Database::class);
        $this->auth = \App\Core\App::getInstance()->get(AuthService::class);
    }

    // ── Constructor / DI ───────────────────────────────────────

    public function testServiceCanBeInstantiated(): void
    {
        $service = new ExportService($this->db, $this->auth);
        self::assertInstanceOf(ExportService::class, $service);
    }

    public function testServiceIsRegisteredInContainer(): void
    {
        $app = \App\Core\App::getInstance();
        self::assertTrue($app->has(ExportService::class));
        $service = $app->get(ExportService::class);
        self::assertInstanceOf(ExportService::class, $service);
    }

    public function testAppExportReturnsExportService(): void
    {
        $service = \App\Core\App::export();
        self::assertInstanceOf(ExportService::class, $service);
    }

    public function testAppExportReturnsSameInstance(): void
    {
        $service1 = \App\Core\App::export();
        $service2 = \App\Core\App::export();
        self::assertSame($service1, $service2);
    }

    public function testConstructorRequiresDatabaseAndAuth(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        $params = $constructor->getParameters();
        self::assertCount(3, $params);
        self::assertSame('database', $params[0]->getName());
        self::assertSame('authService', $params[1]->getName());
        self::assertSame('submissionRepository', $params[2]->getName());
    }

    public function testConstructorParamTypes(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();
        self::assertSame(Database::class, $params[0]->getType()->getName());
        self::assertSame(AuthService::class, $params[1]->getType()->getName());
    }

    public function testClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        self::assertTrue($reflection->isFinal());
    }

    public function testClassNamespace(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        self::assertSame('App\Export', $reflection->getNamespaceName());
    }

    public function testServiceUsesInjectedDatabase(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionProperty($service, 'database');
        self::assertSame($this->db, $reflection->getValue($service));
    }

    public function testServiceUsesInjectedAuth(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionProperty($service, 'authService');
        self::assertSame($this->auth, $reflection->getValue($service));
    }

    // ── transformValue() ──────────────────────────────────────

    public function testTransformValueOneReturnsOui(): void
    {
        $service = new ExportService($this->db, $this->auth);
        self::assertSame('Oui', $service->transformValue('1'));
    }

    public function testTransformValueZeroReturnsNon(): void
    {
        $service = new ExportService($this->db, $this->auth);
        self::assertSame('Non', $service->transformValue('0'));
    }

    public function testTransformValueStringPassthrough(): void
    {
        $service = new ExportService($this->db, $this->auth);
        self::assertSame('hello world', $service->transformValue('hello world'));
    }

    public function testTransformValueIntegerPassthrough(): void
    {
        $service = new ExportService($this->db, $this->auth);
        self::assertSame(42, $service->transformValue(42));
    }

    public function testTransformValueNullPassthrough(): void
    {
        $service = new ExportService($this->db, $this->auth);
        self::assertNull($service->transformValue(null));
    }

    public function testTransformValueEmptyStringPassthrough(): void
    {
        $service = new ExportService($this->db, $this->auth);
        self::assertSame('', $service->transformValue(''));
    }

    public function testTransformValueArrayToJson(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $result = $service->transformValue(['a', 'b', 'c']);
        self::assertSame('["a","b","c"]', $result);
    }

    public function testTransformValueAssociativeArrayToJson(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $result = $service->transformValue(['key' => 'value']);
        self::assertSame('{"key":"value"}', $result);
    }

    public function testTransformValueNestedArrayToJson(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $result = $service->transformValue(['outer' => ['inner' => 'val']]);
        self::assertSame('{"outer":{"inner":"val"}}', $result);
    }

    public function testTransformValueFormulaInjectionEquals(): void
    {
        $service = new ExportService($this->db, $this->auth);
        self::assertSame("'=SUM(A1:A10)", $service->transformValue('=SUM(A1:A10)'));
    }

    public function testTransformValueFormulaInjectionPlus(): void
    {
        $service = new ExportService($this->db, $this->auth);
        self::assertSame("'+cmd|'/C calc'!A0", $service->transformValue("+cmd|'/C calc'!A0"));
    }

    public function testTransformValueFormulaInjectionMinus(): void
    {
        $service = new ExportService($this->db, $this->auth);
        self::assertSame("'-cmd|'/C calc'!A0", $service->transformValue("-cmd|'/C calc'!A0"));
    }

    public function testTransformValueFormulaInjectionAt(): void
    {
        $service = new ExportService($this->db, $this->auth);
        self::assertSame("'@SUM(1+1)", $service->transformValue('@SUM(1+1)'));
    }

    public function testTransformValueNoInjectionForRegularString(): void
    {
        $service = new ExportService($this->db, $this->auth);
        self::assertSame('Jean Dupont', $service->transformValue('Jean Dupont'));
    }

    public function testTransformValueNoInjectionForNumberAsString(): void
    {
        $service = new ExportService($this->db, $this->auth);
        self::assertSame('12345', $service->transformValue('12345'));
    }

    public function testTransformValueStringContainingEqualsNotAtStart(): void
    {
        $service = new ExportService($this->db, $this->auth);
        self::assertSame('a=b', $service->transformValue('a=b'));
    }

    public function testTransformValueBooleanFalsePassthrough(): void
    {
        $service = new ExportService($this->db, $this->auth);
        self::assertFalse($service->transformValue(false));
    }

    public function testTransformValueBooleanTruePassthrough(): void
    {
        $service = new ExportService($this->db, $this->auth);
        self::assertTrue($service->transformValue(true));
    }

    public function testTransformValueZeroIntegerNotConverted(): void
    {
        // Integer 0 is NOT '0' string, so it should not convert to Non
        $service = new ExportService($this->db, $this->auth);
        self::assertSame(0, $service->transformValue(0));
    }

    public function testTransformValueOneIntegerNotConverted(): void
    {
        // Integer 1 is NOT '1' string, so it should not convert to Oui
        $service = new ExportService($this->db, $this->auth);
        self::assertSame(1, $service->transformValue(1));
    }

    public function testTransformValueEmptyArray(): void
    {
        $service = new ExportService($this->db, $this->auth);
        self::assertSame('[]', $service->transformValue([]));
    }

    // ── buildWhereClause() ────────────────────────────────────

    public function testBuildWhereClauseDefaultReturns1Eq1(): void
    {
        $service = new ExportService($this->db, $this->auth);
        [$where, $params] = $service->buildWhereClause([]);
        self::assertSame('1=1', $where);
        self::assertSame([], $params);
    }

    public function testBuildWhereClauseEmptyOptions(): void
    {
        $service = new ExportService($this->db, $this->auth);
        [$where, $params] = $service->buildWhereClause(['form_id' => '', 'status' => '']);
        self::assertSame('1=1', $where);
        self::assertSame([], $params);
    }

    public function testBuildWhereClauseFormIdOnly(): void
    {
        $service = new ExportService($this->db, $this->auth);
        [$where, $params] = $service->buildWhereClause(['form_id' => 'abc-123']);
        self::assertSame('1=1 AND s.form_id = ?', $where);
        self::assertSame(['abc-123'], $params);
    }

    public function testBuildWhereClauseStatusOnly(): void
    {
        $service = new ExportService($this->db, $this->auth);
        [$where, $params] = $service->buildWhereClause(['status' => 'validated']);
        self::assertSame('1=1 AND s.status = ?', $where);
        self::assertSame(['validated'], $params);
    }

    public function testBuildWhereClauseBothFilters(): void
    {
        $service = new ExportService($this->db, $this->auth);
        [$where, $params] = $service->buildWhereClause(['form_id' => 'abc', 'status' => 'pending']);
        self::assertSame('1=1 AND s.form_id = ? AND s.status = ?', $where);
        self::assertSame(['abc', 'pending'], $params);
    }

    public function testBuildWhereClauseIgnoresUnknownKeys(): void
    {
        $service = new ExportService($this->db, $this->auth);
        [$where, $params] = $service->buildWhereClause(['unknown' => 'value']);
        self::assertSame('1=1', $where);
        self::assertSame([], $params);
    }

    // ── generateCsvString() ────────────────────────────────────

    public function testGenerateCsvStringEmptyDatabase(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $csv = $service->generateCsvString();
        self::assertIsString($csv);
        // Should contain BOM + at least header row
        self::assertNotEmpty($csv);
    }

    public function testGenerateCsvStringContainsBom(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $csv = $service->generateCsvString();
        // UTF-8 BOM: EF BB BF
        self::assertStringStartsWith(chr(0xEF) . chr(0xBB) . chr(0xBF), $csv);
    }

    public function testGenerateCsvStringContainsHeaderRow(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $csv = $service->generateCsvString();
        // Remove BOM for easier parsing
        $withoutBom = substr($csv, 3);
        $lines = explode("\n", $withoutBom);
        self::assertNotEmpty($lines);
        // First line should contain fixed headers
        self::assertStringContainsString('ID', $lines[0]);
        self::assertStringContainsString('Formulaire', $lines[0]);
        self::assertStringContainsString('Agent', $lines[0]);
        self::assertStringContainsString('Statut', $lines[0]);
        self::assertStringContainsString('Soumis le', $lines[0]);
        self::assertStringContainsString('Clôturé le', $lines[0]);
    }

    public function testGenerateCsvStringUsesSemicolonDelimiter(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $csv = $service->generateCsvString();
        self::assertStringContainsString(';', $csv);
    }

    public function testGenerateCsvStringWithFormIdFilter(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $csv = $service->generateCsvString(['form_id' => 'nonexistent-form-id']);
        // Should still produce valid CSV (just empty)
        self::assertIsString($csv);
        self::assertNotEmpty($csv);
    }

    public function testGenerateCsvStringWithStatusFilter(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $csv = $service->generateCsvString(['status' => 'validated']);
        self::assertIsString($csv);
        self::assertNotEmpty($csv);
    }

    public function testGenerateCsvStringWithBothFilters(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $csv = $service->generateCsvString(['form_id' => 'nonexistent', 'status' => 'pending']);
        self::assertIsString($csv);
        self::assertNotEmpty($csv);
    }

    public function testGenerateCsvStringWithSubmissionData(): void
    {
        $pdo = $this->db->getPdo();
        // Insert a test form if not exists
        $formId = 'test-export-form-' . uniqid();
        $slug = 'test-export-' . uniqid();
        $pdo->exec("INSERT INTO forms (id, label, slug, description)
                     VALUES ('$formId', 'Test Export Form', '$slug', 'Test')");

        // Insert a test submission
        $subId = 'test-sub-' . uniqid();
        $data = json_encode(['nom' => 'Dupont', 'prenom' => 'Jean', 'check_ok' => '1', 'check_no' => '0']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status)
                        VALUES (?, ?, ?, ?, datetime('now'), 'en_cours')")
            ->execute([$subId, $formId, $data, 'agent_' . uniqid() . '@test.com']);

        try {
            $service = new ExportService($this->db, $this->auth);
            $csv = $service->generateCsvString(['form_id' => $formId]);
            $withoutBom = substr($csv, 3);
            $lines = array_filter(explode("\n", $withoutBom), fn($l) => trim($l) !== '');

            // At least header + 1 data row
            self::assertGreaterThanOrEqual(2, count($lines));

            // Header row contains fixed columns
            self::assertStringContainsString('ID', $lines[0]);
            self::assertStringContainsString('Formulaire', $lines[0]);

            // Data row contains our values
            $dataRow = $lines[1];
            self::assertStringContainsString('Dupont', $dataRow);
            self::assertStringContainsString('Jean', $dataRow);
            // Boolean conversion: '1' → Oui, '0' → Non
            self::assertStringContainsString('Oui', $dataRow);
            self::assertStringContainsString('Non', $dataRow);
        } finally {
            $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
            $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
        }
    }

    public function testGenerateCsvStringExcludesValidationsKey(): void
    {
        $pdo = $this->db->getPdo();
        $formId = 'test-export-form2-' . uniqid();
        $slug = 'test-export2-' . uniqid();
        $pdo->exec("INSERT INTO forms (id, label, slug, description)
                     VALUES ('$formId', 'Test Export Form 2', '$slug', 'Test')");

        $subId = 'test-sub2-' . uniqid();
        $data = json_encode(['nom' => 'Test', 'validations' => ['some' => 'data']]);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status)
                        VALUES (?, ?, ?, ?, datetime('now'), 'en_cours')")
            ->execute([$subId, $formId, $data, 'agent_' . uniqid() . '@test.com']);

        try {
            $service = new ExportService($this->db, $this->auth);
            $csv = $service->generateCsvString(['form_id' => $formId]);
            $withoutBom = substr($csv, 3);
            $lines = array_filter(explode("\n", $withoutBom), fn($l) => trim($l) !== '');

            // Header should NOT contain 'validations'
            self::assertStringNotContainsString('validations', $lines[0]);
            // But should contain 'nom'
            self::assertStringContainsString('nom', $lines[0]);
        } finally {
            $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
            $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
        }
    }

    public function testGenerateCsvStringArrayValuesJsonEncoded(): void
    {
        $pdo = $this->db->getPdo();
        $formId = 'test-export-form3-' . uniqid();
        $slug = 'test-export3-' . uniqid();
        $pdo->exec("INSERT INTO forms (id, label, slug, description)
                     VALUES ('$formId', 'Test Export Form 3', '$slug', 'Test')");

        $subId = 'test-sub3-' . uniqid();
        $data = json_encode(['tags' => ['tag1', 'tag2'], 'nom' => 'Test']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status)
                        VALUES (?, ?, ?, ?, datetime('now'), 'en_cours')")
            ->execute([$subId, $formId, $data, 'agent_' . uniqid() . '@test.com']);

        try {
            $service = new ExportService($this->db, $this->auth);
            $csv = $service->generateCsvString(['form_id' => $formId]);
            $withoutBom = substr($csv, 3);
            $lines = array_filter(explode("\n", $withoutBom), fn($l) => trim($l) !== '');

            // Data row should contain JSON-encoded array
            self::assertGreaterThanOrEqual(2, count($lines));
            self::assertStringContainsString('tag1', $lines[1]);
            self::assertStringContainsString('tag2', $lines[1]);
        } finally {
            $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
            $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
        }
    }

    public function testGenerateCsvStringFormulaInjectionNeutralized(): void
    {
        $pdo = $this->db->getPdo();
        $formId = 'test-export-form4-' . uniqid();
        $slug = 'test-export4-' . uniqid();
        $pdo->exec("INSERT INTO forms (id, label, slug, description)
                     VALUES ('$formId', 'Test Export Form 4', '$slug', 'Test')");

        $subId = 'test-sub4-' . uniqid();
        $data = json_encode(['field' => '=SUM(A1:A10)']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status)
                        VALUES (?, ?, ?, ?, datetime('now'), 'en_cours')")
            ->execute([$subId, $formId, $data, 'agent_' . uniqid() . '@test.com']);

        try {
            $service = new ExportService($this->db, $this->auth);
            $csv = $service->generateCsvString(['form_id' => $formId]);
            // The formula should be prefixed with apostrophe
            self::assertStringContainsString("'=SUM(A1:A10)", $csv);
        } finally {
            $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
            $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
        }
    }

    public function testGenerateCsvStringOrderBySubmittedAtDesc(): void
    {
        $pdo = $this->db->getPdo();
        $formId = 'test-export-form5-' . uniqid();
        $slug = 'test-export5-' . uniqid();
        $pdo->exec("INSERT INTO forms (id, label, slug, description)
                     VALUES ('$formId', 'Test Export Form 5', '$slug', 'Test')");

        $subId1 = 'test-sub5a-' . uniqid();
        $subId2 = 'test-sub5b-' . uniqid();
        $data1 = json_encode(['nom' => 'First']);
        $data2 = json_encode(['nom' => 'Second']);

        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status)
                        VALUES (?, ?, ?, ?, '2025-01-01 10:00:00', 'en_cours')")
            ->execute([$subId1, $formId, $data1, 'agent1@test.com']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status)
                        VALUES (?, ?, ?, ?, '2025-01-02 10:00:00', 'en_cours')")
            ->execute([$subId2, $formId, $data2, 'agent2@test.com']);

        try {
            $service = new ExportService($this->db, $this->auth);
            $csv = $service->generateCsvString(['form_id' => $formId]);
            $withoutBom = substr($csv, 3);
            $lines = array_filter(explode("\n", $withoutBom), fn($l) => trim($l) !== '');
            self::assertGreaterThanOrEqual(3, count($lines));

            // Second submission (newer) should come first due to DESC order
            self::assertStringContainsString('Second', $lines[1], 'Newer submission should be first');
            self::assertStringContainsString('First', $lines[2], 'Older submission should be second');
        } finally {
            $pdo->prepare("DELETE FROM submissions WHERE id IN (?, ?)")->execute([$subId1, $subId2]);
            $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
        }
    }

    public function testGenerateCsvStringMultipleSubmissionsDifferentKeys(): void
    {
        $pdo = $this->db->getPdo();
        $formId = 'test-export-form6-' . uniqid();
        $slug = 'test-export6-' . uniqid();
        $pdo->exec("INSERT INTO forms (id, label, slug, description)
                     VALUES ('$formId', 'Test Export Form 6', '$slug', 'Test')");

        $subId1 = 'test-sub6a-' . uniqid();
        $subId2 = 'test-sub6b-' . uniqid();
        $data1 = json_encode(['nom' => 'Alice', 'tel' => '0102030405']);
        $data2 = json_encode(['nom' => 'Bob', 'email' => 'bob@test.com']);

        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status)
                        VALUES (?, ?, ?, ?, datetime('now'), 'en_cours')")
            ->execute([$subId1, $formId, $data1, 'agent_' . uniqid() . '@test.com']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status)
                        VALUES (?, ?, ?, ?, datetime('now'), 'en_cours')")
            ->execute([$subId2, $formId, $data2, 'agent_' . uniqid() . '@test.com']);

        try {
            $service = new ExportService($this->db, $this->auth);
            $csv = $service->generateCsvString(['form_id' => $formId]);
            $withoutBom = substr($csv, 3);
            $lines = array_filter(explode("\n", $withoutBom), fn($l) => trim($l) !== '');

            // Header should contain ALL keys from both submissions
            self::assertStringContainsString('nom', $lines[0]);
            self::assertStringContainsString('tel', $lines[0]);
            self::assertStringContainsString('email', $lines[0]);
        } finally {
            $pdo->prepare("DELETE FROM submissions WHERE id IN (?, ?)")->execute([$subId1, $subId2]);
            $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
        }
    }

    public function testGenerateCsvStringMissingKeyInSubmission(): void
    {
        $pdo = $this->db->getPdo();
        $formId = 'test-export-form7-' . uniqid();
        $slug = 'test-export7-' . uniqid();
        $pdo->exec("INSERT INTO forms (id, label, slug, description)
                     VALUES ('$formId', 'Test Export Form 7', '$slug', 'Test')");

        $subId1 = 'test-sub7a-' . uniqid();
        $subId2 = 'test-sub7b-' . uniqid();
        // Sub1 has 'nom', sub2 has 'prenom' but not 'nom'
        $data1 = json_encode(['nom' => 'Alice']);
        $data2 = json_encode(['prenom' => 'Bob']);

        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status)
                        VALUES (?, ?, ?, ?, datetime('now'), 'en_cours')")
            ->execute([$subId1, $formId, $data1, 'agent_' . uniqid() . '@test.com']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status)
                        VALUES (?, ?, ?, ?, datetime('now'), 'en_cours')")
            ->execute([$subId2, $formId, $data2, 'agent_' . uniqid() . '@test.com']);

        try {
            $service = new ExportService($this->db, $this->auth);
            $csv = $service->generateCsvString(['form_id' => $formId]);
            $withoutBom = substr($csv, 3);
            $lines = array_filter(explode("\n", $withoutBom), fn($l) => trim($l) !== '');

            // Both keys in header
            self::assertStringContainsString('nom', $lines[0]);
            self::assertStringContainsString('prenom', $lines[0]);
        } finally {
            $pdo->prepare("DELETE FROM submissions WHERE id IN (?, ?)")->execute([$subId1, $subId2]);
            $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
        }
    }

    public function testGenerateCsvStringClosedAtEmptyString(): void
    {
        $pdo = $this->db->getPdo();
        $formId = 'test-export-form8-' . uniqid();
        $slug = 'test-export8-' . uniqid();
        $pdo->exec("INSERT INTO forms (id, label, slug, description)
                     VALUES ('$formId', 'Test Export Form 8', '$slug', 'Test')");

        $subId = 'test-sub8-' . uniqid();
        $data = json_encode(['nom' => 'Test']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, closed_at, status)
                        VALUES (?, ?, ?, ?, datetime('now'), NULL, 'en_cours')")
            ->execute([$subId, $formId, $data, 'agent_' . uniqid() . '@test.com']);

        try {
            $service = new ExportService($this->db, $this->auth);
            $csv = $service->generateCsvString(['form_id' => $formId]);
            self::assertIsString($csv);
            self::assertNotEmpty($csv);
        } finally {
            $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
            $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
        }
    }

    // ── Method signatures / reflection ────────────────────────

    public function testExportCsvMethodExists(): void
    {
        $service = new ExportService($this->db, $this->auth);
        self::assertTrue(method_exists($service, 'exportCsv'));
    }

    public function testExportCsvMethodIsPublic(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionMethod($service, 'exportCsv');
        self::assertTrue($reflection->isPublic());
    }

    public function testTransformValueMethodExists(): void
    {
        $service = new ExportService($this->db, $this->auth);
        self::assertTrue(method_exists($service, 'transformValue'));
    }

    public function testTransformValueMethodIsPublic(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionMethod($service, 'transformValue');
        self::assertTrue($reflection->isPublic());
    }

    public function testBuildWhereClauseMethodExists(): void
    {
        $service = new ExportService($this->db, $this->auth);
        self::assertTrue(method_exists($service, 'buildWhereClause'));
    }

    public function testBuildWhereClauseMethodIsPublic(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionMethod($service, 'buildWhereClause');
        self::assertTrue($reflection->isPublic());
    }

    public function testGenerateCsvStringMethodExists(): void
    {
        $service = new ExportService($this->db, $this->auth);
        self::assertTrue(method_exists($service, 'generateCsvString'));
    }

    public function testGenerateCsvStringMethodIsPublic(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionMethod($service, 'generateCsvString');
        self::assertTrue($reflection->isPublic());
    }

    public function testExportCsvReturnTypeIsVoid(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionMethod($service, 'exportCsv');
        $returnType = $reflection->getReturnType();
        self::assertNotNull($returnType);
        self::assertSame('void', $returnType->getName());
    }

    public function testGenerateCsvStringReturnTypeIsString(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionMethod($service, 'generateCsvString');
        $returnType = $reflection->getReturnType();
        self::assertNotNull($returnType);
        self::assertSame('string', $returnType->getName());
    }

    public function testTransformValueReturnTypeIsMixed(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionMethod($service, 'transformValue');
        $returnType = $reflection->getReturnType();
        self::assertNotNull($returnType);
        self::assertSame('mixed', $returnType->getName());
    }

    public function testBuildWhereClauseReturnTypeIsArray(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionMethod($service, 'buildWhereClause');
        $returnType = $reflection->getReturnType();
        self::assertNotNull($returnType);
        self::assertSame('array', $returnType->getName());
    }

    public function testClassHasDbProperty(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        self::assertTrue($reflection->hasProperty('database'));
    }

    public function testClassHasAuthProperty(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        self::assertTrue($reflection->hasProperty('authService'));
    }
}
