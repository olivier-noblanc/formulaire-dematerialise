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
        $this->assertInstanceOf(ExportService::class, $service);
    }

    public function testServiceIsRegisteredInContainer(): void
    {
        $app = \App\Core\App::getInstance();
        $this->assertTrue($app->has(ExportService::class));
        $service = $app->get(ExportService::class);
        $this->assertInstanceOf(ExportService::class, $service);
    }

    public function testAppExportReturnsExportService(): void
    {
        $service = \App\Core\App::export();
        $this->assertInstanceOf(ExportService::class, $service);
    }

    public function testAppExportReturnsSameInstance(): void
    {
        $service1 = \App\Core\App::export();
        $service2 = \App\Core\App::export();
        $this->assertSame($service1, $service2);
    }

    public function testConstructorRequiresDatabaseAndAuth(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
        $params = $constructor->getParameters();
        $this->assertCount(2, $params);
        $this->assertSame('database', $params[0]->getName());
        $this->assertSame('authService', $params[1]->getName());
    }

    public function testConstructorParamTypes(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $constructor = $reflection->getConstructor();
        $params = $constructor->getParameters();
        $this->assertSame(Database::class, $params[0]->getType()->getName());
        $this->assertSame(AuthService::class, $params[1]->getType()->getName());
    }

    public function testClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $this->assertTrue($reflection->isFinal());
    }

    public function testClassNamespace(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $this->assertSame('App\Export', $reflection->getNamespaceName());
    }

    public function testServiceUsesInjectedDatabase(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionProperty($service, 'database');
        $reflection->setAccessible(true);
        $this->assertSame($this->db, $reflection->getValue($service));
    }

    public function testServiceUsesInjectedAuth(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionProperty($service, 'authService');
        $reflection->setAccessible(true);
        $this->assertSame($this->auth, $reflection->getValue($service));
    }

    // ── transformValue() ──────────────────────────────────────

    public function testTransformValueOneReturnsOui(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $this->assertSame('Oui', $service->transformValue('1'));
    }

    public function testTransformValueZeroReturnsNon(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $this->assertSame('Non', $service->transformValue('0'));
    }

    public function testTransformValueStringPassthrough(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $this->assertSame('hello world', $service->transformValue('hello world'));
    }

    public function testTransformValueIntegerPassthrough(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $this->assertSame(42, $service->transformValue(42));
    }

    public function testTransformValueNullPassthrough(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $this->assertNull($service->transformValue(null));
    }

    public function testTransformValueEmptyStringPassthrough(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $this->assertSame('', $service->transformValue(''));
    }

    public function testTransformValueArrayToJson(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $result = $service->transformValue(['a', 'b', 'c']);
        $this->assertSame('["a","b","c"]', $result);
    }

    public function testTransformValueAssociativeArrayToJson(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $result = $service->transformValue(['key' => 'value']);
        $this->assertSame('{"key":"value"}', $result);
    }

    public function testTransformValueNestedArrayToJson(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $result = $service->transformValue(['outer' => ['inner' => 'val']]);
        $this->assertSame('{"outer":{"inner":"val"}}', $result);
    }

    public function testTransformValueFormulaInjectionEquals(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $this->assertSame("'=SUM(A1:A10)", $service->transformValue('=SUM(A1:A10)'));
    }

    public function testTransformValueFormulaInjectionPlus(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $this->assertSame("'+cmd|'/C calc'!A0", $service->transformValue("+cmd|'/C calc'!A0"));
    }

    public function testTransformValueFormulaInjectionMinus(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $this->assertSame("'-cmd|'/C calc'!A0", $service->transformValue("-cmd|'/C calc'!A0"));
    }

    public function testTransformValueFormulaInjectionAt(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $this->assertSame("'@SUM(1+1)", $service->transformValue('@SUM(1+1)'));
    }

    public function testTransformValueNoInjectionForRegularString(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $this->assertSame('Jean Dupont', $service->transformValue('Jean Dupont'));
    }

    public function testTransformValueNoInjectionForNumberAsString(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $this->assertSame('12345', $service->transformValue('12345'));
    }

    public function testTransformValueStringContainingEqualsNotAtStart(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $this->assertSame('a=b', $service->transformValue('a=b'));
    }

    public function testTransformValueBooleanFalsePassthrough(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $this->assertFalse($service->transformValue(false));
    }

    public function testTransformValueBooleanTruePassthrough(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $this->assertTrue($service->transformValue(true));
    }

    public function testTransformValueZeroIntegerNotConverted(): void
    {
        // Integer 0 is NOT '0' string, so it should not convert to Non
        $service = new ExportService($this->db, $this->auth);
        $this->assertSame(0, $service->transformValue(0));
    }

    public function testTransformValueOneIntegerNotConverted(): void
    {
        // Integer 1 is NOT '1' string, so it should not convert to Oui
        $service = new ExportService($this->db, $this->auth);
        $this->assertSame(1, $service->transformValue(1));
    }

    public function testTransformValueEmptyArray(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $this->assertSame('[]', $service->transformValue([]));
    }

    // ── buildWhereClause() ────────────────────────────────────

    public function testBuildWhereClauseDefaultReturns1Eq1(): void
    {
        $service = new ExportService($this->db, $this->auth);
        [$where, $params] = $service->buildWhereClause([]);
        $this->assertSame('1=1', $where);
        $this->assertSame([], $params);
    }

    public function testBuildWhereClauseEmptyOptions(): void
    {
        $service = new ExportService($this->db, $this->auth);
        [$where, $params] = $service->buildWhereClause(['form_id' => '', 'status' => '']);
        $this->assertSame('1=1', $where);
        $this->assertSame([], $params);
    }

    public function testBuildWhereClauseFormIdOnly(): void
    {
        $service = new ExportService($this->db, $this->auth);
        [$where, $params] = $service->buildWhereClause(['form_id' => 'abc-123']);
        $this->assertSame('1=1 AND s.form_id = ?', $where);
        $this->assertSame(['abc-123'], $params);
    }

    public function testBuildWhereClauseStatusOnly(): void
    {
        $service = new ExportService($this->db, $this->auth);
        [$where, $params] = $service->buildWhereClause(['status' => 'validated']);
        $this->assertSame('1=1 AND s.status = ?', $where);
        $this->assertSame(['validated'], $params);
    }

    public function testBuildWhereClauseBothFilters(): void
    {
        $service = new ExportService($this->db, $this->auth);
        [$where, $params] = $service->buildWhereClause(['form_id' => 'abc', 'status' => 'pending']);
        $this->assertSame('1=1 AND s.form_id = ? AND s.status = ?', $where);
        $this->assertSame(['abc', 'pending'], $params);
    }

    public function testBuildWhereClauseIgnoresUnknownKeys(): void
    {
        $service = new ExportService($this->db, $this->auth);
        [$where, $params] = $service->buildWhereClause(['unknown' => 'value']);
        $this->assertSame('1=1', $where);
        $this->assertSame([], $params);
    }

    // ── generateCsvString() ────────────────────────────────────

    public function testGenerateCsvStringEmptyDatabase(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $csv = $service->generateCsvString();
        $this->assertIsString($csv);
        // Should contain BOM + at least header row
        $this->assertNotEmpty($csv);
    }

    public function testGenerateCsvStringContainsBom(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $csv = $service->generateCsvString();
        // UTF-8 BOM: EF BB BF
        $this->assertStringStartsWith(chr(0xEF) . chr(0xBB) . chr(0xBF), $csv);
    }

    public function testGenerateCsvStringContainsHeaderRow(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $csv = $service->generateCsvString();
        // Remove BOM for easier parsing
        $withoutBom = substr($csv, 3);
        $lines = explode("\n", $withoutBom);
        $this->assertNotEmpty($lines);
        // First line should contain fixed headers
        $this->assertStringContainsString('ID', $lines[0]);
        $this->assertStringContainsString('Formulaire', $lines[0]);
        $this->assertStringContainsString('Agent', $lines[0]);
        $this->assertStringContainsString('Statut', $lines[0]);
        $this->assertStringContainsString('Soumis le', $lines[0]);
        $this->assertStringContainsString('Clôturé le', $lines[0]);
    }

    public function testGenerateCsvStringUsesSemicolonDelimiter(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $csv = $service->generateCsvString();
        $this->assertStringContainsString(';', $csv);
    }

    public function testGenerateCsvStringWithFormIdFilter(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $csv = $service->generateCsvString(['form_id' => 'nonexistent-form-id']);
        // Should still produce valid CSV (just empty)
        $this->assertIsString($csv);
        $this->assertNotEmpty($csv);
    }

    public function testGenerateCsvStringWithStatusFilter(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $csv = $service->generateCsvString(['status' => 'validated']);
        $this->assertIsString($csv);
        $this->assertNotEmpty($csv);
    }

    public function testGenerateCsvStringWithBothFilters(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $csv = $service->generateCsvString(['form_id' => 'nonexistent', 'status' => 'pending']);
        $this->assertIsString($csv);
        $this->assertNotEmpty($csv);
    }

    public function testGenerateCsvStringWithSubmissionData(): void
    {
        $pdo = $this->db->getPdo();
        // Insert a test form if not exists
        $formId = 'test-export-form-' . uniqid();
        $pdo->exec("INSERT INTO forms (id, label, slug, description)
                     VALUES ('$formId', 'Test Export Form', 'test-export', 'Test')");

        // Insert a test submission
        $subId = 'test-sub-' . uniqid();
        $data = json_encode(['nom' => 'Dupont', 'prenom' => 'Jean', 'check_ok' => '1', 'check_no' => '0']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status)
                        VALUES (?, ?, ?, ?, datetime('now'), 'pending')")
            ->execute([$subId, $formId, $data, 'agent@test.com']);

        try {
            $service = new ExportService($this->db, $this->auth);
            $csv = $service->generateCsvString(['form_id' => $formId]);
            $withoutBom = substr($csv, 3);
            $lines = array_filter(explode("\n", $withoutBom), fn($l) => trim($l) !== '');

            // At least header + 1 data row
            $this->assertGreaterThanOrEqual(2, count($lines));

            // Header row contains fixed columns
            $this->assertStringContainsString('ID', $lines[0]);
            $this->assertStringContainsString('Formulaire', $lines[0]);

            // Data row contains our values
            $dataRow = $lines[1];
            $this->assertStringContainsString('Dupont', $dataRow);
            $this->assertStringContainsString('Jean', $dataRow);
            // Boolean conversion: '1' → Oui, '0' → Non
            $this->assertStringContainsString('Oui', $dataRow);
            $this->assertStringContainsString('Non', $dataRow);
        } finally {
            $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
            $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
        }
    }

    public function testGenerateCsvStringExcludesValidationsKey(): void
    {
        $pdo = $this->db->getPdo();
        $formId = 'test-export-form2-' . uniqid();
        $pdo->exec("INSERT INTO forms (id, label, slug, description)
                     VALUES ('$formId', 'Test Export Form 2', 'test-export2', 'Test')");

        $subId = 'test-sub2-' . uniqid();
        $data = json_encode(['nom' => 'Test', 'validations' => ['some' => 'data']]);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status)
                        VALUES (?, ?, ?, ?, datetime('now'), 'pending')")
            ->execute([$subId, $formId, $data, 'agent@test.com']);

        try {
            $service = new ExportService($this->db, $this->auth);
            $csv = $service->generateCsvString(['form_id' => $formId]);
            $withoutBom = substr($csv, 3);
            $lines = array_filter(explode("\n", $withoutBom), fn($l) => trim($l) !== '');

            // Header should NOT contain 'validations'
            $this->assertStringNotContainsString('validations', $lines[0]);
            // But should contain 'nom'
            $this->assertStringContainsString('nom', $lines[0]);
        } finally {
            $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
            $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
        }
    }

    public function testGenerateCsvStringArrayValuesJsonEncoded(): void
    {
        $pdo = $this->db->getPdo();
        $formId = 'test-export-form3-' . uniqid();
        $pdo->exec("INSERT INTO forms (id, label, slug, description)
                     VALUES ('$formId', 'Test Export Form 3', 'test-export3', 'Test')");

        $subId = 'test-sub3-' . uniqid();
        $data = json_encode(['tags' => ['tag1', 'tag2'], 'nom' => 'Test']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status)
                        VALUES (?, ?, ?, ?, datetime('now'), 'pending')")
            ->execute([$subId, $formId, $data, 'agent@test.com']);

        try {
            $service = new ExportService($this->db, $this->auth);
            $csv = $service->generateCsvString(['form_id' => $formId]);
            $withoutBom = substr($csv, 3);
            $lines = array_filter(explode("\n", $withoutBom), fn($l) => trim($l) !== '');

            // Data row should contain JSON-encoded array
            $this->assertGreaterThanOrEqual(2, count($lines));
            $this->assertStringContainsString('tag1', $lines[1]);
            $this->assertStringContainsString('tag2', $lines[1]);
        } finally {
            $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
            $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
        }
    }

    public function testGenerateCsvStringFormulaInjectionNeutralized(): void
    {
        $pdo = $this->db->getPdo();
        $formId = 'test-export-form4-' . uniqid();
        $pdo->exec("INSERT INTO forms (id, label, slug, description)
                     VALUES ('$formId', 'Test Export Form 4', 'test-export4', 'Test')");

        $subId = 'test-sub4-' . uniqid();
        $data = json_encode(['field' => '=SUM(A1:A10)']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status)
                        VALUES (?, ?, ?, ?, datetime('now'), 'pending')")
            ->execute([$subId, $formId, $data, 'agent@test.com']);

        try {
            $service = new ExportService($this->db, $this->auth);
            $csv = $service->generateCsvString(['form_id' => $formId]);
            // The formula should be prefixed with apostrophe
            $this->assertStringContainsString("'=SUM(A1:A10)", $csv);
        } finally {
            $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
            $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
        }
    }

    public function testGenerateCsvStringOrderBySubmittedAtDesc(): void
    {
        $pdo = $this->db->getPdo();
        $formId = 'test-export-form5-' . uniqid();
        $pdo->exec("INSERT INTO forms (id, label, slug, description)
                     VALUES ('$formId', 'Test Export Form 5', 'test-export5', 'Test')");

        $subId1 = 'test-sub5a-' . uniqid();
        $subId2 = 'test-sub5b-' . uniqid();
        $data1 = json_encode(['nom' => 'First']);
        $data2 = json_encode(['nom' => 'Second']);

        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status)
                        VALUES (?, ?, ?, ?, '2025-01-01 10:00:00', 'pending')")
            ->execute([$subId1, $formId, $data1, 'agent@test.com']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status)
                        VALUES (?, ?, ?, ?, '2025-01-02 10:00:00', 'pending')")
            ->execute([$subId2, $formId, $data2, 'agent@test.com']);

        try {
            $service = new ExportService($this->db, $this->auth);
            $csv = $service->generateCsvString(['form_id' => $formId]);
            $withoutBom = substr($csv, 3);
            $lines = array_filter(explode("\n", $withoutBom), fn($l) => trim($l) !== '');
            $this->assertGreaterThanOrEqual(3, count($lines));

            // Second submission (newer) should come first due to DESC order
            $this->assertStringContainsString('Second', $lines[1], 'Newer submission should be first');
            $this->assertStringContainsString('First', $lines[2], 'Older submission should be second');
        } finally {
            $pdo->prepare("DELETE FROM submissions WHERE id IN (?, ?)")->execute([$subId1, $subId2]);
            $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
        }
    }

    public function testGenerateCsvStringMultipleSubmissionsDifferentKeys(): void
    {
        $pdo = $this->db->getPdo();
        $formId = 'test-export-form6-' . uniqid();
        $pdo->exec("INSERT INTO forms (id, label, slug, description)
                     VALUES ('$formId', 'Test Export Form 6', 'test-export6', 'Test')");

        $subId1 = 'test-sub6a-' . uniqid();
        $subId2 = 'test-sub6b-' . uniqid();
        $data1 = json_encode(['nom' => 'Alice', 'tel' => '0102030405']);
        $data2 = json_encode(['nom' => 'Bob', 'email' => 'bob@test.com']);

        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status)
                        VALUES (?, ?, ?, ?, datetime('now'), 'pending')")
            ->execute([$subId1, $formId, $data1, 'agent@test.com']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status)
                        VALUES (?, ?, ?, ?, datetime('now'), 'pending')")
            ->execute([$subId2, $formId, $data2, 'agent@test.com']);

        try {
            $service = new ExportService($this->db, $this->auth);
            $csv = $service->generateCsvString(['form_id' => $formId]);
            $withoutBom = substr($csv, 3);
            $lines = array_filter(explode("\n", $withoutBom), fn($l) => trim($l) !== '');

            // Header should contain ALL keys from both submissions
            $this->assertStringContainsString('nom', $lines[0]);
            $this->assertStringContainsString('tel', $lines[0]);
            $this->assertStringContainsString('email', $lines[0]);
        } finally {
            $pdo->prepare("DELETE FROM submissions WHERE id IN (?, ?)")->execute([$subId1, $subId2]);
            $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
        }
    }

    public function testGenerateCsvStringMissingKeyInSubmission(): void
    {
        $pdo = $this->db->getPdo();
        $formId = 'test-export-form7-' . uniqid();
        $pdo->exec("INSERT INTO forms (id, label, slug, description)
                     VALUES ('$formId', 'Test Export Form 7', 'test-export7', 'Test')");

        $subId1 = 'test-sub7a-' . uniqid();
        $subId2 = 'test-sub7b-' . uniqid();
        // Sub1 has 'nom', sub2 has 'prenom' but not 'nom'
        $data1 = json_encode(['nom' => 'Alice']);
        $data2 = json_encode(['prenom' => 'Bob']);

        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status)
                        VALUES (?, ?, ?, ?, datetime('now'), 'pending')")
            ->execute([$subId1, $formId, $data1, 'agent@test.com']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status)
                        VALUES (?, ?, ?, ?, datetime('now'), 'pending')")
            ->execute([$subId2, $formId, $data2, 'agent@test.com']);

        try {
            $service = new ExportService($this->db, $this->auth);
            $csv = $service->generateCsvString(['form_id' => $formId]);
            $withoutBom = substr($csv, 3);
            $lines = array_filter(explode("\n", $withoutBom), fn($l) => trim($l) !== '');

            // Both keys in header
            $this->assertStringContainsString('nom', $lines[0]);
            $this->assertStringContainsString('prenom', $lines[0]);
        } finally {
            $pdo->prepare("DELETE FROM submissions WHERE id IN (?, ?)")->execute([$subId1, $subId2]);
            $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
        }
    }

    public function testGenerateCsvStringClosedAtEmptyString(): void
    {
        $pdo = $this->db->getPdo();
        $formId = 'test-export-form8-' . uniqid();
        $pdo->exec("INSERT INTO forms (id, label, slug, description)
                     VALUES ('$formId', 'Test Export Form 8', 'test-export8', 'Test')");

        $subId = 'test-sub8-' . uniqid();
        $data = json_encode(['nom' => 'Test']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, closed_at, status)
                        VALUES (?, ?, ?, ?, datetime('now'), NULL, 'pending')")
            ->execute([$subId, $formId, $data, 'agent@test.com']);

        try {
            $service = new ExportService($this->db, $this->auth);
            $csv = $service->generateCsvString(['form_id' => $formId]);
            $this->assertIsString($csv);
            $this->assertNotEmpty($csv);
        } finally {
            $pdo->prepare("DELETE FROM submissions WHERE id = ?")->execute([$subId]);
            $pdo->prepare("DELETE FROM forms WHERE id = ?")->execute([$formId]);
        }
    }

    // ── Method signatures / reflection ────────────────────────

    public function testExportCsvMethodExists(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $this->assertTrue(method_exists($service, 'exportCsv'));
    }

    public function testExportCsvMethodIsPublic(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionMethod($service, 'exportCsv');
        $this->assertTrue($reflection->isPublic());
    }

    public function testTransformValueMethodExists(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $this->assertTrue(method_exists($service, 'transformValue'));
    }

    public function testTransformValueMethodIsPublic(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionMethod($service, 'transformValue');
        $this->assertTrue($reflection->isPublic());
    }

    public function testBuildWhereClauseMethodExists(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $this->assertTrue(method_exists($service, 'buildWhereClause'));
    }

    public function testBuildWhereClauseMethodIsPublic(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionMethod($service, 'buildWhereClause');
        $this->assertTrue($reflection->isPublic());
    }

    public function testGenerateCsvStringMethodExists(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $this->assertTrue(method_exists($service, 'generateCsvString'));
    }

    public function testGenerateCsvStringMethodIsPublic(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionMethod($service, 'generateCsvString');
        $this->assertTrue($reflection->isPublic());
    }

    public function testExportCsvReturnTypeIsVoid(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionMethod($service, 'exportCsv');
        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('void', $returnType->getName());
    }

    public function testGenerateCsvStringReturnTypeIsString(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionMethod($service, 'generateCsvString');
        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('string', $returnType->getName());
    }

    public function testTransformValueReturnTypeIsMixed(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionMethod($service, 'transformValue');
        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('mixed', $returnType->getName());
    }

    public function testBuildWhereClauseReturnTypeIsArray(): void
    {
        $service = new ExportService($this->db, $this->auth);
        $reflection = new \ReflectionMethod($service, 'buildWhereClause');
        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('array', $returnType->getName());
    }

    public function testClassHasDbProperty(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $this->assertTrue($reflection->hasProperty('database'));
    }

    public function testClassHasAuthProperty(): void
    {
        $reflection = new \ReflectionClass(ExportService::class);
        $this->assertTrue($reflection->hasProperty('authService'));
    }
}
