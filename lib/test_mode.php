<?php
declare(strict_types=1);

/**
 * Test mode utilities.
 *
 * get_test_mails() / reset_test_mails() — capture des emails en TEST_MODE
 * test_json_response() — sortie JSON pour tests E2E (exit)
 *
 * @package lib
 */

// ── TEST MODE UTILITIES ──────────────────────────────────────

/**
 * Récupère les mails interceptés en mode test
 * @return array<string, mixed>
 */
function get_test_mails(): array {
    return $GLOBALS['_test_mails'] ?? [];
}

/**
 * Réinitialise la file d'attente des mails test
 */
function reset_test_mails(): void {
    $GLOBALS['_test_mails'] = [];
}

/**
 * Réponse JSON pour le mode test (à appeler dans les pages au lieu de die/redirect)
 * En mode test, les pages doivent appeler test_json_response() avant tout die()/exit()/header('Location:')
 * pour renvoyer un JSON structuré exploitable par les tests.
 * @param array<string, mixed> $data
 */
function test_json_response(array $data): void {
    /** @phpstan-ignore-next-line booleanNot.alwaysFalse */
    if (!TEST_MODE) return;
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['_test_mode' => true], $data), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
