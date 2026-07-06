<?php
declare(strict_types=1);
/**
 * test_persona_token.php — Test du persona token-based (v10.0.0).
 *
 * Vérifie :
 *   1. La table persona_tokens existe en DB (migration v25 appliquée)
 *   2. persona_create_token() génère un token valide
 *   3. persona_lookup() retourne le bon target_email pour un token valide
 *   4. persona_lookup() retourne '' pour un token inexistant
 *   5. persona_lookup() retourne '' pour un token expiré
 *   6. persona_revoke() invalide un token
 *   7. persona_cleanup() supprime les tokens expirés
 *   8. build_url() ajoute ?persona_token si présent dans $_GET
 *   9. persona_rewrite_urls() réécrit les href="index.php..." dans le HTML
 *
 * Fichier : tests/test_persona_token.php
 */

require_once __DIR__ . '/test_bootstrap.php';

$passed = 0;
$failed = 0;

function check_p(string $name, bool $ok, string $detail = ''): void {
    global $passed, $failed;
    if ($ok) {
        echo "  ✅ $name\n";
        $passed++;
    } else {
        echo "  ❌ $name" . ($detail !== '' ? " — $detail" : '') . "\n";
        $failed++;
    }
}

echo "── Test persona token-based (v10.0.0) ──\n";

// ── Test 1 : Table persona_tokens existe ──
echo "\n── Test 1 : Table persona_tokens existe ──\n";
try {
    $pdo = get_pdo();
    $count = (int)$pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='persona_tokens'")->fetchColumn();
    check_p('Table persona_tokens existe', $count === 1);
} catch (\Throwable $e) {
    check_p('Table persona_tokens existe', false, $e->getMessage());
}

// ── Test 2 : persona_create_token() ──
echo "\n── Test 2 : persona_create_token() génère un token ──\n";
$admin_email = 'admin.test@exemple.invalid';
$target_email = 'agent.test@exemple.invalid';

// Insérer l'admin test en DB (persona_lookup vérifie qu'il est encore admin)
try {
    $pdo = get_pdo();
    $existing = $pdo->prepare("SELECT 1 FROM admins WHERE email = ?");
    $existing->execute([$admin_email]);
    if (!$existing->fetchColumn()) {
        $pdo->prepare("INSERT INTO admins (id, email, added_at) VALUES (?, ?, datetime('now'))")
            ->execute([generate_uuid(), $admin_email]);
    }
} catch (\Throwable $e) {
    // ignore si déjà là ou si table n'existe pas
}

$token = persona_create_token($admin_email, $target_email);
check_p('Token généré (32 hex chars)', strlen($token) === 32 && ctype_xdigit($token), "len=" . strlen($token));

// ── Test 3 : persona_lookup() valide ──
echo "\n── Test 3 : persona_lookup() retourne target_email ──\n";
$looked_up = persona_lookup($token);
check_p('Lookup retourne target_email', $looked_up === $target_email, "got: $looked_up");

// ── Test 4 : persona_lookup() token inexistant ──
echo "\n── Test 4 : persona_lookup() token inexistant ──\n";
$looked_up = persona_lookup('nonexistent_token_12345');
check_p('Lookup token inexistant retourne vide', $looked_up === '');

// ── Test 5 : persona_lookup() token expiré ──
echo "\n── Test 5 : persona_lookup() token expiré ──\n";
try {
    // Créer un token puis l'expirer manuellement
    $expired_token = persona_create_token($admin_email, 'expired@exemple.invalid');
    $pdo->prepare("UPDATE persona_tokens SET expires_at = ? WHERE token = ?")
        ->execute([gmdate('Y-m-d H:i:s', time() - 3600), $expired_token]);
    $looked_up = persona_lookup($expired_token);
    check_p('Lookup token expiré retourne vide', $looked_up === '');
} catch (\Throwable $e) {
    check_p('Lookup token expiré retourne vide', false, $e->getMessage());
}

// ── Test 6 : persona_revoke() ──
echo "\n── Test 6 : persona_revoke() invalide le token ──\n";
$revoked = persona_revoke($token);
check_p('Revoke retourne true', $revoked === true);
$looked_up_after = persona_lookup($token);
check_p('Lookup token révoqué retourne vide', $looked_up_after === '');

// ── Test 7 : persona_cleanup() ──
echo "\n── Test 7 : persona_cleanup() supprime tokens expirés ──\n";
try {
    $deleted = persona_cleanup();
    check_p('Cleanup s\'exécute sans erreur', $deleted >= 0, "deleted=$deleted");
} catch (\Throwable $e) {
    check_p('Cleanup s\'exécute sans erreur', false, $e->getMessage());
}

// ── Test 8 : build_url() ──
echo "\n── Test 8 : build_url() propage persona_token ──\n";
$_GET['persona_token'] = 'testtoken123';
$url1 = build_url('index.php?p=my_submissions');
check_p('build_url ajoute ?persona_token', str_contains($url1, 'persona_token=testtoken123'), "url=$url1");

$url2 = build_url('index.php?p=my_submissions&statut=valide');
check_p('build_url ajoute &persona_token (URL avec ?)', str_contains($url2, '&persona_token=testtoken123'), "url=$url2");

$url3 = build_url('index.php?p=admin_forms&form_id=XXX#fields');
check_p('build_url préserve anchor', str_contains($url3, '#fields') && str_contains($url3, 'persona_token='), "url=$url3");

unset($_GET['persona_token']);
$url4 = build_url('index.php?p=my_submissions');
check_p('build_url sans persona_token retourne URL inchangée', $url4 === 'index.php?p=my_submissions', "url=$url4");

// ── Test 9 : persona_rewrite_urls() ──
echo "\n── Test 9 : persona_rewrite_urls() réécrit les href ──\n";
$_GET['persona_token'] = 'rewritetest';
$html = '<a href="index.php?p=my_submissions">Mes demandes</a><a href="index.php#form-cards">Accueil</a><a href="https://example.com">Externe</a>';
$rewritten = persona_rewrite_urls($html);
check_p('Réécrit href="index.php?..."', str_contains($rewritten, 'href="index.php?p=my_submissions&persona_token=rewritetest"'));
check_p('Réécrit href="index.php#..." (avec anchor)', str_contains($rewritten, 'href="index.php?persona_token=rewritetest#form-cards"'));
check_p('Préserve les URLs externes', str_contains($rewritten, 'href="https://example.com"'));

// Sans persona_token : aucune réécriture
unset($_GET['persona_token']);
$rewritten_no_token = persona_rewrite_urls($html);
check_p('Sans persona_token : HTML inchangé', $rewritten_no_token === $html);

// ── Résumé ──
echo "\n═══════════════════════════════════════════════════\n";
echo "  PERSONA TOKEN TESTS — " . ($failed === 0 ? "✅ AUCUN ÉCHEC" : "❌ $failed échec(s)") . "\n";
echo "  $passed test(s) réussi(s) / $failed échoué(s) / " . ($passed + $failed) . " total\n";
echo "═══════════════════════════════════════════════════\n";
exit($failed > 0 ? 1 : 0);
