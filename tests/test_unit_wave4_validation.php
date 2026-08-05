<?php
/**
 * tests/test_unit_wave4_validation.php — Section 12.1-12.9 : Wave 4 — validate_input() (9 règles)
 *
 * Module thématique extrait de test_unit.php (refactor P-TESTS).
 * Dépendances : test_bootstrap.php (test), tests/test_unit_helpers.php (helpers shared).
 */

declare(strict_types=1);

/**
 * Section 12.1-12.9 : Wave 4 — validate_input() (9 règles)
 */
function run_tests_unit_wave4_validation(): void {
echo "── 12. Tests Wave 4 — Audit remediation (A-18) ──\n";

// ───────────────────────────────────────────────────────────────
// 12.1 — validate_input() — uuid rule
// ───────────────────────────────────────────────────────────────
test('validate_input() uuid valide retourne la valeur en minuscules', function() {
    $uuid = 'AAAA0000-0000-4000-8000-000000000000';
    $result = validate_input($uuid, 'uuid');
    return $result === strtolower($uuid) ? true : "Got: $result";
});

test('validate_input() uuid v4 standard accepté', function() {
    $uuid = '12345678-1234-4abc-9def-1234567890ab';
    $result = validate_input($uuid, 'uuid');
    return $result === $uuid ? true : "Got: $result";
});

test('validate_input() uuid invalide lance InvalidArgumentException', function() {
    try {
        validate_input('not-a-uuid', 'uuid');
        return 'Aucune exception levée';
    } catch (\InvalidArgumentException $e) {
        return true;
    }
});

test('validate_input() uuid avec mauvaise version (3x au lieu de 4x) lance exception', function() {
    try {
        validate_input('12345678-1234-3abc-9def-1234567890ab', 'uuid');
        return 'Aucune exception levée';
    } catch (\InvalidArgumentException $e) {
        return true;
    }
});

// ───────────────────────────────────────────────────────────────
// 12.2 — validate_input() — email rule
// ───────────────────────────────────────────────────────────────
test('validate_input() email valide accepté', function() {
    $result = validate_input('user@example.com', 'email');
    return $result === 'user@example.com' ? true : "Got: $result";
});

test('validate_input() email met en minuscules', function() {
    $result = validate_input('USER@EXAMPLE.COM', 'email');
    return $result === 'user@example.com' ? true : "Got: $result";
});

test('validate_input() email invalide lance exception', function() {
    try {
        validate_input('not-an-email', 'email');
        return 'Aucune exception levée';
    } catch (\InvalidArgumentException $e) {
        return true;
    }
});

test('validate_input() email avec max_length court rejette (troncature rend invalide)', function() {
    // Le code tronque puis valide — un email tronqué à 20 chars n'est plus valide
    $long = str_repeat('a', 50) . '@example.com';
    try {
        validate_input($long, 'email', ['max_length' => 20]);
        return 'Aucune exception levée (email tronqué devrait être invalide)';
    } catch (\InvalidArgumentException $e) {
        return true;
    }
});

test('validate_input() email avec max_length suffisant accepte', function() {
    $email = 'user@example.com';  // 16 chars
    $result = validate_input($email, 'email', ['max_length' => 100]);
    return $result === $email ? true : "Got: $result";
});

// ───────────────────────────────────────────────────────────────
// 12.3 — validate_input() — slug rule
// ───────────────────────────────────────────────────────────────
test('validate_input() slug valide accepté', function() {
    $result = validate_input('onboarding_v2', 'slug');
    return $result === 'onboarding_v2' ? true : "Got: $result";
});

test('validate_input() slug avec chiffres et underscore accepté', function() {
    $result = validate_input('form_123', 'slug');
    return $result === 'form_123' ? true : "Got: $result";
});

test('validate_input() slug avec caractères spéciaux lance exception', function() {
    // v5.27.1 : les tirets sont maintenant acceptés (bug acces-si en prod).
    // On teste avec un vrai caractère interdit : @
    try {
        validate_input('onboarding@v2', 'slug');
        return 'Aucune exception levée pour @';
    } catch (\InvalidArgumentException $e) {
        return true;
    }
});

test('validate_input() slug avec tiret est accepté (bug v5.27.1 acces-si)', function() {
    // Bug v5.27.0/v5.27.1 : en prod, form.php?f=acces-si levait erreur 400
    // car la regex slug n'acceptait que [a-z0-9_]. Corrigé pour accepter les tirets.
    try {
        $result = validate_input('acces-si', 'slug');
        return $result === 'acces-si' ? true : 'Résultat inattendu: ' . $result;
    } catch (\InvalidArgumentException $e) {
        return 'Exception levée alors que les tirets doivent être acceptés: ' . $e->getMessage();
    }
});

test('validate_input() slug avec espace lance exception', function() {
    try {
        validate_input('onboarding v2', 'slug');
        return 'Aucune exception levée';
    } catch (\InvalidArgumentException $e) {
        return true;
    }
});

// ───────────────────────────────────────────────────────────────
// 12.4 — validate_input() — action rule
// ───────────────────────────────────────────────────────────────
test('validate_input() action valide accepté', function() {
    $result = validate_input('save_settings', 'action');
    return $result === 'save_settings' ? true : "Got: $result";
});

test('validate_input() action avec chiffres accepté', function() {
    $result = validate_input('step2_save', 'action');
    return $result === 'step2_save' ? true : "Got: $result";
});

test('validate_input() action avec espace lance exception', function() {
    try {
        validate_input('save settings', 'action');
        return 'Aucune exception levée';
    } catch (\InvalidArgumentException $e) {
        return true;
    }
});

test('validate_input() action avec caractère spécial lance exception', function() {
    try {
        validate_input('save-settings!', 'action');
        return 'Aucune exception levée';
    } catch (\InvalidArgumentException $e) {
        return true;
    }
});

// ───────────────────────────────────────────────────────────────
// 12.5 — validate_input() — status rule
// ───────────────────────────────────────────────────────────────
test('validate_input() status en_cours accepté', function() {
    $result = validate_input('en_cours', 'status');
    return $result === 'en_cours' ? true : "Got: $result";
});

test('validate_input() status valide accepté', function() {
    $result = validate_input('valide', 'status');
    return $result === 'valide' ? true : "Got: $result";
});

test('validate_input() status refuse accepté', function() {
    $result = validate_input('refuse', 'status');
    return $result === 'refuse' ? true : "Got: $result";
});

test('validate_input() status inconnu lance exception', function() {
    try {
        validate_input('unknown', 'status');
        return 'Aucune exception levée';
    } catch (\InvalidArgumentException $e) {
        return true;
    }
});

test('validate_input() status avec allowed_values personnalisé', function() {
    $result = validate_input('tous', 'status', ['allowed_values' => ['tous', 'complet']]);
    return $result === 'tous' ? true : "Got: $result";
});

// ───────────────────────────────────────────────────────────────
// 12.6 — validate_input() — alpha_num rule
// ───────────────────────────────────────────────────────────────
test('validate_input() alpha_num texte simple accepté', function() {
    $result = validate_input('Hello World 123', 'alpha_num');
    return $result === 'Hello World 123' ? true : "Got: $result";
});

test('validate_input() alpha_num avec accents accepté', function() {
    $result = validate_input('Café résumé', 'alpha_num');
    return $result === 'Café résumé' ? true : "Got: $result";
});

test('validate_input() alpha_num avec chevrons lance exception', function() {
    try {
        validate_input('<script>', 'alpha_num');
        return 'Aucune exception levée';
    } catch (\InvalidArgumentException $e) {
        return true;
    }
});

test('validate_input() alpha_num avec max_length tronque', function() {
    $result = validate_input('abcdefghijklmnop', 'alpha_num', ['max_length' => 5]);
    return $result === 'abcde' ? true : "Got: $result";
});

// ───────────────────────────────────────────────────────────────
// 12.7 — validate_input() — int rule
// ───────────────────────────────────────────────────────────────
test('validate_input() int valide retourne un entier', function() {
    $result = validate_input('42', 'int');
    return $result === 42 ? true : "Got: $result (type: " . gettype($result) . ")";
});

test('validate_input() int négatif accepté', function() {
    $result = validate_input('-5', 'int');
    return $result === -5 ? true : "Got: $result";
});

test('validate_input() int non numérique lance exception', function() {
    try {
        validate_input('abc', 'int');
        return 'Aucune exception levée';
    } catch (\InvalidArgumentException $e) {
        return true;
    }
});

test('validate_input() int avec option min lance exception si trop petit', function() {
    try {
        validate_input('5', 'int', ['min' => 10]);
        return 'Aucune exception levée';
    } catch (\InvalidArgumentException $e) {
        return true;
    }
});

test('validate_input() int avec option max lance exception si trop grand', function() {
    try {
        validate_input('100', 'int', ['max' => 50]);
        return 'Aucune exception levée';
    } catch (\InvalidArgumentException $e) {
        return true;
    }
});

test('validate_input() int avec min et max valides accepté', function() {
    $result = validate_input('25', 'int', ['min' => 10, 'max' => 50]);
    return $result === 25 ? true : "Got: $result";
});

// ───────────────────────────────────────────────────────────────
// 12.8 — validate_input() — date rule
// ───────────────────────────────────────────────────────────────
test('validate_input() date YYYY-MM-DD valide accepté', function() {
    $result = validate_input('2026-12-31', 'date');
    return $result === '2026-12-31' ? true : "Got: $result";
});

test('validate_input() date avec format DD/MM/YYYY lance exception', function() {
    try {
        validate_input('31/12/2026', 'date');
        return 'Aucune exception levée';
    } catch (\InvalidArgumentException $e) {
        return true;
    }
});

test('validate_input() date avec texte lance exception', function() {
    try {
        validate_input('not-a-date', 'date');
        return 'Aucune exception levée';
    } catch (\InvalidArgumentException $e) {
        return true;
    }
});

// ───────────────────────────────────────────────────────────────
// 12.9 — validate_input() — token rule
// ───────────────────────────────────────────────────────────────
test('validate_input() token 64 hex valide accepté', function() {
    $token = str_repeat('a', 64);
    $result = validate_input($token, 'token');
    return $result === $token ? true : "Got: $result";
});

test('validate_input() token trop court lance exception', function() {
    try {
        validate_input(str_repeat('a', 32), 'token');
        return 'Aucune exception levée';
    } catch (\InvalidArgumentException $e) {
        return true;
    }
});

test('validate_input() token avec caractères non-hex lance exception', function() {
    try {
        validate_input(str_repeat('z', 64), 'token');
        return 'Aucune exception levée';
    } catch (\InvalidArgumentException $e) {
        return true;
    }
});

test('validate_input() token avec majuscules lance exception (hex minuscules)', function() {
    try {
        validate_input(str_repeat('A', 64), 'token');
        return 'Aucune exception levée';
    } catch (\InvalidArgumentException $e) {
        return true;
    }
});

}
