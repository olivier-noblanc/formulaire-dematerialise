<?php

declare(strict_types=1);

namespace App\Tests;

use App\Auth\AuthService;
use App\Core\App;
use App\Core\Database;
use App\Export\ExportService;
use PHPUnit\Framework\TestCase;

/**
 * Tests ciblés sur les 9 mutants Infection échappés sur ExportService.
 *
 * Référence : worklog.md → section `infection-mutants` (mutant #10 + 8 autres
 * identifiés dans /tmp/artifacts/infection/infection.log).
 *
 * Tous les mutants portent sur la boucle de pagination CSV :
 *
 *   $batch_size = 500;          ← mutants #1 (DecrementInteger), #2 (IncrementInteger)
 *   $offset = 0;                ← mutant #3 (DecrementInteger)
 *   do {                         ← mutant #4 (DoWhile → While)
 *       ...
 *       $line = [...];           ← mutant #5 (ArrayItemRemoval)
 *       ... $row['closed_at'] ?? '' ...  ← mutant #6 (Coalesce)
 *       $offset += $batch_size;  ← mutants #7 (Assignment), #8 (PlusEqual → -=)
 *   } while (count($rows) === $batch_size);  ← mutant #9 (Identical === → !==)
 *
 * Le test pivot « insérer > 500 soumissions, vérifier toutes présentes et
 * uniques » tue 4 mutants (#2, #7, #8, #9). Les autres nécessitent des
 * tests spécifiques (boundary 500, exactement 1 soumission, parsing de
 * colonnes). Mutants #1 (batch_size=500→499) et #6 (Coalesce sur closed_at)
 * ne sont PAS observables via le contenu du CSV — tests documentent pourquoi.
 *
 * @package App\Tests
 */
final class ExportServiceMutationTest extends TestCase
{
    private Database $db;
    private AuthService $auth;

    /** @var list<string> IDs de soumissions créées pour cleanup tearDown */
    private array $createdSubmissionIds = [];

    /** @var list<string> IDs de forms créés pour cleanup tearDown */
    private array $createdFormIds = [];

    protected function setUp(): void
    {
        $this->db = App::getInstance()->get(Database::class);
        $this->auth = App::getInstance()->get(AuthService::class);
    }

    protected function tearDown(): void
    {
        $pdo = $this->db->getPdo();
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($this->createdSubmissionIds as $id) {
            try { $pdo->prepare('DELETE FROM submissions WHERE id = ?')->execute([$id]); } catch (\Throwable) {}
        }
        foreach ($this->createdFormIds as $id) {
            try { $pdo->prepare('DELETE FROM forms WHERE id = ?')->execute([$id]); } catch (\Throwable) {}
        }
        $this->createdSubmissionIds = [];
        $this->createdFormIds = [];
    }

    // ── Mutant #9 (Identical === → !==, Timed Out) + #2 + #7 + #8 ──

    /**
     * Mutant #9 : `count($rows) === $batch_size` → `count($rows) !== $batch_size`.
     * La condition de continuation devient inversée. Avec 501 soumissions :
     *  - Original : batch 1 = 500 rows, 500 === 500 → continue, batch 2 = 1, stop. Total = 501.
     *  - Muté     : batch 1 = 500 rows, 500 !== 500 → stop. Total = 500. MANQUE 1 ligne.
     *
     * Mutant #2 (batch_size = 501) : 501 !== 501 → stop immédiat après batch 1 = 500 rows.
     *
     * Mutant #7 (Assignment $offset = $batch_size au lieu de +=) : offset reste à 500,
     * boucle infinie → timeout.
     *
     * Mutant #8 (PlusEqual → -=) : offset devient négatif, SQLite traite OFFSET négatif
     * comme 0, retourne encore batch 1, boucle infinie → timeout.
     *
     * Ce test insère 501 soumissions et vérifie que TOUTES sont présentes (et sans
     * doublon). Tue les mutants #2, #7, #8, #9.
     */
    public function testGenerateCsvStringExportsAllRowsWhenMoreThanBatchSize(): void
    {
        $pdo = $this->db->getPdo();
        [$formId, $insertedIds] = $this->insertSubmissions(501);

        $service = new ExportService($this->auth);
        $csv = $service->generateCsvString(['form_id' => $formId]);

        // Vérifie que CHAQUE id inséré est présent dans le CSV
        foreach ($insertedIds as $id) {
            self::assertStringContainsString(
                $id,
                $csv,
                'Mutants #2/#7/#8/#9: la soumission ' . $id . ' doit être présente dans l\'export.'
            );
        }

        // Vérifie qu'aucun id n'est dupliqué (compte d'occurrences == 1)
        $withoutBom = substr($csv, 3);
        $lines = array_filter(explode("\n", $withoutBom), fn ($l) => trim($l) !== '');
        $dataLines = array_slice($lines, 1); // skip header
        $firstId = $insertedIds[0];
        $firstIdOccurrences = 0;
        foreach ($dataLines as $line) {
            if (str_contains($line, $firstId)) {
                $firstIdOccurrences++;
            }
        }
        self::assertSame(
            1,
            $firstIdOccurrences,
            'Mutants #7/#8: aucune ligne ne doit être dupliquée (premier id doit apparaître exactement 1 fois).'
        );

        // Le compte total de lignes doit être >= 501 données + 1 header
        self::assertGreaterThanOrEqual(
            501,
            count($dataLines),
            'Mutants #2/#9: le CSV doit contenir au moins 501 lignes de données.'
        );
    }

    // ── Mutant #4 (DoWhile → While) ──

    /**
     * Mutant #4 : Infection DoWhile transforme `do { BODY } while (COND);`
     * en `while (COND) { BODY }`. La condition COND est vérifiée AVANT la
     * première exécution. Au premier passage, `$rows` n'est pas défini →
     * `count($rows) === 500` est false → body jamais exécuté → AUCUNE ligne
     * de données n'est écrite dans le CSV.
     *
     * Ce test insère 1 soumission et vérifie qu'elle apparaît dans le CSV.
     * Sur code muté, 0 ligne de données → assertion échoue.
     */
    public function testGenerateCsvStringExecutesLoopBodyAtLeastOnce(): void
    {
        $pdo = $this->db->getPdo();
        [$formId, $insertedIds] = $this->insertSubmissions(1);

        $service = new ExportService($this->auth);
        $csv = $service->generateCsvString(['form_id' => $formId]);

        self::assertStringContainsString(
            $insertedIds[0],
            $csv,
            'Mutant #4 DoWhile: le corps de la boucle doit s\'exécuter au moins une fois (1 soumission doit être exportée).'
        );
    }

    // ── Mutant #3 (DecrementInteger sur $offset = 0 → $offset = -1) ──

    /**
     * Mutant #3 : `$offset = 0` → `$offset = -1`. SQLite traite OFFSET négatif
     * comme OFFSET 0. Mais après la première itération, `$offset += $batch_size`
     * donne -1 + 500 = 499 (au lieu de 500). La seconde itération devient
     * `LIMIT 500 OFFSET 499` → retourne la 500e ligne (en doublon) au lieu
     * de rien. Le CSV contient alors 501 lignes avec 1 doublon.
     *
     * Avec un nombre exact de soumissions égal à batch_size (500), on peut
     * détecter la duplication. Ce test insère 500 soumissions et vérifie
     * qu'aucune n'est dupliquée.
     */
    public function testGenerateCsvStringNoDuplicateRowsWhenExactlyBatchSize(): void
    {
        $pdo = $this->db->getPdo();
        [$formId, $insertedIds] = $this->insertSubmissions(500);

        $service = new ExportService($this->auth);
        $csv = $service->generateCsvString(['form_id' => $formId]);

        // Compte les occurrences d'un id unique inséré — doit être 1.
        // Sur code muté (offset=-1), la dernière soumission (500e) apparaîtrait 2 fois.
        $lastId = end($insertedIds);
        $withoutBom = substr($csv, 3);
        $lines = array_filter(explode("\n", $withoutBom), fn ($l) => trim($l) !== '');

        $occurrences = 0;
        foreach ($lines as $line) {
            if (str_contains($line, $lastId)) {
                $occurrences++;
            }
        }
        self::assertSame(
            1,
            $occurrences,
            'Mutant #3 DecrementInteger: aucune ligne ne doit être dupliquée (offset doit démarrer à 0, pas -1).'
        );

        // Vérifie aussi que toutes les 500 soumissions sont présentes
        foreach ($insertedIds as $id) {
            self::assertStringContainsString($id, $csv, 'Chaque soumission doit être exportée (exactly batch_size).');
        }
    }

    // ── Mutant #9 (variante boundary) ──

    /**
     * Mutant #9 (variante) : avec exactement 500 soumissions, le code original
     * exécute 2 itérations (batch 1 = 500 rows → continue, batch 2 = 0 rows → stop).
     * Le mutant `!==` ferait : batch 1 = 500, 500 !== 500 → false → stop. Total = 500.
     * Pas de boucle infinie ici (count === batch_size n'est true qu'une fois).
     *
     * Mais avec exactement 499 soumissions, le mutant provoque une boucle
     * infinie (499 !== 500 → true → continue, offset grandit, 0 rows à
     * chaque fois → timeout).
     *
     * Ce test insère 499 soumissions et vérifie qu'elles sont toutes présentes.
     * Sur code muté, boucle infinie → timeout → test échoue.
     */
    public function testGenerateCsvStringExportsAllRowsWhenLessThanBatchSize(): void
    {
        [$formId, $insertedIds] = $this->insertSubmissions(499);

        $service = new ExportService($this->auth);
        $csv = $service->generateCsvString(['form_id' => $formId]);

        foreach ($insertedIds as $id) {
            self::assertStringContainsString(
                $id,
                $csv,
                'Mutant #9 (variante < batch_size): la soumission ' . $id . ' doit être exportée.'
            );
        }

        // Le CSV doit contenir exactement 499 lignes de données + 1 header
        $withoutBom = substr($csv, 3);
        $lines = array_filter(explode("\n", $withoutBom), fn ($l) => trim($l) !== '');
        self::assertCount(
            500,
            $lines,
            'Mutant #9: le CSV doit contenir 499 lignes de données + 1 header (pas de boucle infinie).'
        );
    }

    // ── Mutant #5 (ArrayItemRemoval sur $line) ──

    /**
     * Mutant #5 : ArrayItemRemoval retire un élément du tableau `$line` (les
     * 6 colonnes fixes). Sur code muté, la ligne de données a 5 colonnes
     * au lieu de 6 (avant les colonnes dynamiques) → décalage des colonnes.
     *
     * Ce test parse le CSV et vérifie que chaque colonne fixe contient la
     * valeur attendue (position + valeur). Sur code muté, le décalage fait
     * que les assertions sur les positions échouent.
     */
    public function testGenerateCsvStringContainsAllSixFixedColumnsInDataRow(): void
    {
        $pdo = $this->db->getPdo();
        $formId = 'form-mutant5-' . uniqid();
        $slug = 'mut5-' . uniqid();
        $pdo->exec("INSERT INTO forms (id, label, slug, description) VALUES ('$formId', 'Mutant5 Form', '$slug', 'Test')");
        $this->createdFormIds[] = $formId;

        $subId = 'sub-mutant5-' . uniqid();
        $data = json_encode(['nom' => 'AliceMutant5']);
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, ?, ?, '2025-06-15 12:30:00', 'en_cours')")
            ->execute([$subId, $formId, $data, 'agent.mutant5@test.com']);
        $this->createdSubmissionIds[] = $subId;

        $service = new ExportService($this->auth);
        $csv = $service->generateCsvString(['form_id' => $formId]);

        // Strip BOM + parse les lignes non vides
        $withoutBom = substr($csv, 3);
        $lines = array_filter(explode("\n", $withoutBom), fn ($l) => trim($l) !== '');

        self::assertGreaterThanOrEqual(2, count($lines), 'header + 1 data row attendus');

        $header = str_getcsv($lines[0], ';', '"', '\\');
        $dataRow = str_getcsv($lines[1], ';', '"', '\\');

        // Le data row doit avoir AU MOINS 6 colonnes fixes (peut avoir +1 pour 'nom')
        self::assertGreaterThanOrEqual(
            7,
            count($dataRow),
            'Mutant #5 ArrayItemRemoval: la ligne de données doit contenir les 6 colonnes fixes + les colonnes dynamiques (nom).'
        );

        // Vérifie position par position (les 6 colonnes fixes)
        self::assertSame($subId, $dataRow[0], 'Colonne 0 (ID) doit contenir l\'id de la soumission');
        self::assertSame('Mutant5 Form', $dataRow[1], 'Colonne 1 (Formulaire) doit contenir le label du form');
        self::assertSame('agent.mutant5@test.com', $dataRow[2], 'Colonne 2 (Agent) doit contenir submitted_by');
        self::assertSame('en_cours', $dataRow[3], 'Colonne 3 (Statut) doit contenir le status');
        self::assertSame('2025-06-15 12:30:00', $dataRow[4], 'Colonne 4 (Soumis le) doit contenir submitted_at');
        self::assertSame('', $dataRow[5], 'Colonne 5 (Clôturé le) doit être vide car closed_at IS NULL');

        // Le header doit avoir les 6 colonnes fixes + 'nom'
        self::assertSame('ID', $header[0]);
        self::assertSame('Formulaire', $header[1]);
        self::assertSame('Agent', $header[2]);
        self::assertSame('Statut', $header[3]);
        self::assertSame('Soumis le', $header[4]);
        self::assertSame('Clôturé le', $header[5]);
    }

    /**
     * Mutant #5 (variante) : avec closed_at set à une date, vérifie que la
     * colonne 5 contient bien cette date (et n'est pas shiftée par un
     * ArrayItemRemoval qui retirerait une colonne).
     */
    public function testGenerateCsvStringClosedAtColumnContainsDateWhenSet(): void
    {
        $pdo = $this->db->getPdo();
        $formId = 'form-mutant5b-' . uniqid();
        $slug = 'mut5b-' . uniqid();
        $pdo->exec("INSERT INTO forms (id, label, slug, description) VALUES ('$formId', 'Mutant5b Form', '$slug', 'Test')");
        $this->createdFormIds[] = $formId;

        $subId = 'sub-mutant5b-' . uniqid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, closed_at, status) VALUES (?, ?, '{}', ?, '2025-06-15 12:30:00', '2025-06-20 09:00:00', 'valide')")
            ->execute([$subId, $formId, 'agent.closed@test.com']);
        $this->createdSubmissionIds[] = $subId;

        $service = new ExportService($this->auth);
        $csv = $service->generateCsvString(['form_id' => $formId]);

        $withoutBom = substr($csv, 3);
        $lines = array_filter(explode("\n", $withoutBom), fn ($l) => trim($l) !== '');
        $dataRow = str_getcsv($lines[1], ';', '"', '\\');

        self::assertSame(
            '2025-06-20 09:00:00',
            $dataRow[5],
            'Mutant #5: closed_at doit être en colonne 5 (pas de shift par retrait d\'item).'
        );
        self::assertSame('valide', $dataRow[3], 'Statut en colonne 3.');
    }

    // ── Mutant #6 (Coalesce sur $row['closed_at'] ?? '') ──

    /**
     * Mutant #6 : Coalesce retire le `?? ''`. Si closed_at est NULL,
     * l'expression devient `$row['closed_at']` (NULL). Or fputcsv convertit
     * NULL en chaîne vide → output identique au code original.
     *
     * Ce mutant n'est PAS observable via le contenu du CSV. Ce test documente
     * ce fait et vérifie au moins que la colonne closed_at est bien vide quand
     * closed_at IS NULL (assertion de comportement, pas de kill du mutant).
     */
    public function testGenerateCsvStringHandlesNullClosedAtAsEmptyColumn(): void
    {
        $pdo = $this->db->getPdo();
        $formId = 'form-mutant6-' . uniqid();
        $slug = 'mut6-' . uniqid();
        $pdo->exec("INSERT INTO forms (id, label, slug, description) VALUES ('$formId', 'Mutant6 Form', '$slug', 'Test')");
        $this->createdFormIds[] = $formId;

        $subId = 'sub-mutant6-' . uniqid();
        $pdo->prepare("INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, closed_at, status) VALUES (?, ?, '{}', ?, ?, NULL, 'en_cours')")
            ->execute([$subId, $formId, 'agent.null@test.com', gmdate('Y-m-d H:i:s')]);
        $this->createdSubmissionIds[] = $subId;

        $service = new ExportService($this->auth);
        $csv = $service->generateCsvString(['form_id' => $formId]);

        $withoutBom = substr($csv, 3);
        $lines = array_filter(explode("\n", $withoutBom), fn ($l) => trim($l) !== '');
        $dataRow = str_getcsv($lines[1], ';', '"', '\\');

        self::assertSame(
            '',
            $dataRow[5],
            'Mutant #6 (non observable): closed_at NULL doit produire une colonne vide — comportement identique avec ou sans mutation (Coalesce → NULL → fputcsv → empty).'
        );
    }

    // ── Mutant #1 (DecrementInteger sur $batch_size = 500 → 499) ──

    /**
     * Mutant #1 : DecrementInteger transforme `$batch_size = 500` en 499.
     * Conséquence : la boucle fait des batches plus petits mais le total
     * exporté reste correct (chaque ligne est lue exactement une fois, l'offset
     * avance correctement par 499). Le CSV final est identique.
     *
     * Ce mutant n'est PAS observable via le contenu du CSV. Ce test documente
     * ce fait et sert de sanity check : avec 1000 soumissions, le CSV doit
     * contenir toutes les lignes, quelle que soit la valeur de batch_size.
     */
    public function testGenerateCsvStringExportsAllRowsWhenMultipleBatches(): void
    {
        [$formId, $insertedIds] = $this->insertSubmissions(1000);

        $service = new ExportService($this->auth);
        $csv = $service->generateCsvString(['form_id' => $formId]);

        // Vérifie qu'au moins les première et dernière lignes sont présentes
        self::assertStringContainsString($insertedIds[0], $csv, 'Première soumission présente');
        self::assertStringContainsString(end($insertedIds), $csv, 'Dernière soumission présente');

        // Compte total de lignes de données — doit être >= 1000
        $withoutBom = substr($csv, 3);
        $lines = array_filter(explode("\n", $withoutBom), fn ($l) => trim($l) !== '');
        self::assertGreaterThanOrEqual(
            1000,
            count($lines) - 1, // -1 pour le header
            'Mutant #1 (non observable): le CSV doit contenir les 1000 soumissions quelle que soit la valeur de batch_size.'
        );
    }

    // ── Mutant #7 (Assignment sur $offset) + #8 (PlusEqual → -=) ──

    /**
     * Mutants #7 et #8 : détaille explicitement le scénario de boucle infinie
     * quand l'offset n'avance pas correctement. Ce test complète le test pivot
     * (>500) en vérifiant explicitement que des IDs du 2e batch sont présents
     * ET uniques.
     */
    public function testGenerateCsvStringSecondBatchRowsArePresentAndUnique(): void
    {
        [$formId, $insertedIds] = $this->insertSubmissions(750);

        $service = new ExportService($this->auth);
        $csv = $service->generateCsvString(['form_id' => $formId]);

        // Le 501e id (premier du 2e batch) doit être présent exactement 1 fois
        $secondBatchFirstId = $insertedIds[500];
        $withoutBom = substr($csv, 3);
        $lines = array_filter(explode("\n", $withoutBom), fn ($l) => trim($l) !== '');

        $occurrences = 0;
        foreach ($lines as $line) {
            if (str_contains($line, $secondBatchFirstId)) {
                $occurrences++;
            }
        }
        self::assertSame(
            1,
            $occurrences,
            'Mutants #7/#8: le 501e id doit apparaître exactement 1 fois (pas de boucle infinie avec offset constant ou négatif).'
        );

        // Le 750e id (dernier du 2e batch partiel) doit aussi être présent
        self::assertStringContainsString(
            end($insertedIds),
            $csv,
            'Mutant #7/#8: la 750e soumission doit être présente (pas tronquée par une offset mal avancé).'
        );
    }

    /**
     * Mutant #4 (variante) + #3 (variante) : test sanity check additionnel.
     * Insère exactement 2 soumissions (largement sous batch_size), vérifie
     * que les 2 sont présentes et que la boucle do-while s'est exécutée.
     */
    public function testGenerateCsvStringWithTwoSubmissionsAllExported(): void
    {
        [$formId, $insertedIds] = $this->insertSubmissions(2);

        $service = new ExportService($this->auth);
        $csv = $service->generateCsvString(['form_id' => $formId]);

        foreach ($insertedIds as $id) {
            self::assertStringContainsString($id, $csv);
        }

        $withoutBom = substr($csv, 3);
        $lines = array_filter(explode("\n", $withoutBom), fn ($l) => trim($l) !== '');
        self::assertCount(
            3,
            $lines,
            'header + 2 lignes de données attendues (loop body s\'exécute au moins une fois).'
        );
    }

    // ── Helper ─────────────────────────────────────────────────────────

    /**
     * Insère N soumissions pour un form dédié et retourne [formId, [subId, ...]].
     * Les IDs sont deterministic-prefixés pour faciliter le debug et garantir
     * l'unicité dans le CSV (str_contains ne matchera pas un fragment d'un autre id).
     *
     * @return array{0: string, 1: list<string>}
     */
    private function insertSubmissions(int $count): array
    {
        $pdo = $this->db->getPdo();
        $formId = 'form-batch-' . uniqid();
        $slug = 'batch-' . uniqid();
        $pdo->exec("INSERT INTO forms (id, label, slug, description) VALUES ('$formId', 'Batch Form', '$slug', 'Test')");
        $this->createdFormIds[] = $formId;

        $insertedIds = [];
        $stmt = $pdo->prepare(
            "INSERT INTO submissions (id, form_id, data, submitted_by, submitted_at, status) VALUES (?, ?, '{}', ?, datetime('now'), 'en_cours')"
        );
        for ($i = 0; $i < $count; $i++) {
            // ID unique avec suffixe numérique pour qu'aucun ne soit un préfixe d'un autre
            $id = sprintf('sub-%s-%05d', $formId, $i);
            $stmt->execute([$id, $formId, 'agent' . $i . '@test.com']);
            $insertedIds[] = $id;
            $this->createdSubmissionIds[] = $id;
        }

        return [$formId, $insertedIds];
    }
}
