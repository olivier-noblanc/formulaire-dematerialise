<?php
/**
 * test_http.php — Tests automatisés de type "Selenium" via HTTP réel
 * 
 * Chaque requête est exécutée dans un processus PHP séparé,
 * avec les variables $_SERVER positionnées pour simuler le mode test.
 * Le serveur PHP built-in gère les requêtes HTTP réelles.
 * 
 * Usage: php -c /path/to/php.ini test_http.php
 */

// ── CONFIG ─────────────────────────────────────────────────────
$BASE   = __DIR__;
$PHP    = '/home/z/php/php';
$INI    = '/home/z/php/php.ini';
$PORT   = 8765;
$SERVER = "http://localhost:$PORT";

// Couleurs terminal
function green($t) { return "\033[32m$t\033[0m"; }
function red($t)   { return "\033[31m$t\033[0m"; }
function yellow($t){ return "\033[33m$t\033[0m"; }
function bold($t)  { return "\033[1m$t\033[0m"; }

$passed = 0;
$failed = 0;
$errors = [];

function assert_test(string $name, bool $condition, string $msg = ''): void {
    global $passed, $failed, $errors;
    if ($condition) {
        echo green("  ✓ $name") . "\n";
        $passed++;
    } else {
        echo red("  ✗ $name") . ($msg ? " — $msg" : '') . "\n";
        $failed++;
        $errors[] = "$name" . ($msg ? ": $msg" : '');
    }
}

/**
 * Exécute une requête HTTP réelle via curl vers le serveur PHP de test
 * Relance automatiquement le serveur si nécessaire
 */
function http_request(string $method, string $path, array $get = [], array $post = [], string $test_user = 'test.agent'): array {
    global $SERVER, $PORT, $PHP, $INI, $BASE;
    
    // Vérifier que le serveur tourne
    $check = @curl_init("$SERVER/test_api.php?action=stats");
    if ($check) {
        curl_setopt($check, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($check, CURLOPT_TIMEOUT, 2);
        curl_setopt($check, CURLOPT_HTTPHEADER, ['X-Test-Mode: 1', 'X-Test-User: test.agent']);
        $test = @curl_exec($check);
        curl_close($check);
    }
    
    if (empty($test)) {
        // Relancer le serveur
        shell_exec("kill $(lsof -t -i:$PORT 2>/dev/null) 2>/dev/null");
        sleep(1);
        shell_exec("cd $BASE && $PHP -c $INI -S localhost:$PORT -t . > /tmp/php_server.log 2>&1 &");
        sleep(2);
    }
    
    // Construire l'URL
    $url = "$SERVER/$path";
    if (!empty($get)) {
        $url .= '?' . http_build_query($get);
    }
    
    // Cookie jar unique par utilisateur test pour isoler les sessions
    $cookie_file = "/tmp/wf_test_cookies_" . preg_replace('/[^a-z0-9]/', '_', $test_user) . ".txt";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-Test-Mode: 1',
        "X-Test-User: $test_user",
    ]);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Ne pas suivre les redirects
    
    if ($method === 'POST' && !empty($post)) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        return ['http_code' => 0, 'json' => null, 'body' => '', 'error' => $curl_error];
    }
    
    $json = null;
    if (is_string($response)) {
        // Essayer de décoder le JSON directement
        $json = json_decode($response, true);
        // Si le JSON est invalide, chercher le premier { dans la réponse
        // (au cas où des warnings PHP précèdent le JSON)
        if ($json === null && preg_match('/\{.*\}/s', $response, $matches)) {
            $json = json_decode($matches[0], true);
        }
    }
    
    return [
        'http_code' => $http_code,
        'json'      => $json,
        'body'      => $response,
    ];
}

/**
 * Appel simplifié à l'API de test
 */
function api(string $action, array $params = [], string $test_user = 'test.agent'): array {
    return http_request('GET', 'test_api.php', array_merge(['action' => $action], $params), [], $test_user);
}

// ── CLEANUP ────────────────────────────────────────────────────
shell_exec("kill $(lsof -t -i:$PORT 2>/dev/null) 2>/dev/null");
sleep(1);
shell_exec("rm -f /tmp/wf_test_cookies_*.txt");
shell_exec("rm -f $BASE/db/workflow_test.db");

// Démarrer le serveur
shell_exec("cd $BASE && $PHP -c $INI -S localhost:$PORT -t . > /tmp/php_server.log 2>&1 &");
sleep(2);

// Vérifier que le serveur répond
$check = @file_get_contents("$SERVER/test_api.php?action=stats", false, stream_context_create([
    'http' => ['method' => 'GET', 'header' => "X-Test-Mode: 1\r\nX-Test-User: test.agent\r\n", 'timeout' => 3]
]));
if ($check === false) {
    echo red("ERREUR: Le serveur PHP n'a pas pu démarrer sur le port $PORT\n");
    exit(1);
}

echo bold("\n═══════════════════════════════════════════════════════════════\n");
echo bold("  TESTS HTTP AUTOMATISÉS — Mode X-Test-Mode (Serveur PHP réel)\n");
echo bold("═══════════════════════════════════════════════════════════════\n\n");

// ── PHASE 0 : Vérification du serveur ──────────────────────────
echo bold("Phase 0 : Vérification du serveur et du mode test\n");

$r = api('stats');
$stats = $r['json'] ?? [];
assert_test('Serveur PHP actif', $r['http_code'] === 200, 'Code: ' . $r['http_code']);
assert_test('Mode test activé', ($stats['test_mode'] ?? false) === true);
assert_test('DB test initialisée (2+ formulaires)', ($stats['forms'] ?? 0) >= 2, ($stats['forms'] ?? 0) . ' formulaires');

// ── PHASE 1 : Configuration du workflow ────────────────────────
echo "\n" . bold("Phase 1 : Configuration du workflow (destinataires + admin)\n");

// Récupérer les étapes du onboarding (form_id=2)
$r = api('steps', ['form_id' => 2]);
$steps = $r['json'] ?? [];
assert_test('Onboarding a 4 étapes', count($steps) === 4, count($steps) . ' étapes trouvées');

// Ajouter des destinataires aux étapes
$recipient_map = [
    'Responsable direct'     => 'resp.direct@dreets.gouv.fr',
    'Service informatique'   => 'it.service@dreets.gouv.fr',
    'Ressources humaines'    => 'rh.service@dreets.gouv.fr',
    'Logistique'             => 'logistique@dreets.gouv.fr',
];

foreach ($steps as $step) {
    $email = $recipient_map[$step['label']] ?? null;
    if ($email) {
        $r = api('add_recipient', ['step_id' => $step['id'], 'email' => $email]);
        assert_test("Destinataire ajouté : {$step['label']}", ($r['json']['ok'] ?? false) === true);
    }
}

// Ajouter admin test
$r = api('add_admin', ['email' => 'test.agent@dreets.gouv.fr']);
assert_test('Admin test.agent ajouté', ($r['json']['ok'] ?? false) === true);

// ── PHASE 2 : Agent remplit le formulaire onboarding ───────────
echo "\n" . bold("Phase 2 : Agent remplit le formulaire onboarding\n");

// GET — Récupérer les métadonnées du formulaire
$r = http_request('GET', 'form.php', ['f' => 'onboarding'], [], 'test.agent');
$form_json = $r['json'] ?? [];
assert_test('Formulaire onboarding accessible (GET)', ($form_json['form']['slug'] ?? '') === 'onboarding');
assert_test('Formulaire a 21 champs', count($form_json['fields'] ?? []) === 21, count($form_json['fields'] ?? []) . ' champs');
assert_test('CSRF token fourni', !empty($form_json['csrf_token']));
assert_test('Utilisateur identifié', ($form_json['submitted_by'] ?? '') === 'test.agent@dreets.gouv.fr');

// POST — Soumettre le formulaire
$submission1_data = [
    'nom' => 'Dupont',
    'prenom' => 'Jean',
    'date_naissance' => '1990-05-15',
    'date_prise_poste' => '2026-07-01',
    'corps_grade' => 'Inspecteur du travail',
    'type_arrivee' => 'Mutation',
    'affectation' => 'Service Emploi',
    'quotite' => '100%',
    'type_poste' => 'Fixe',
    'it_double_ecran' => '1',
    'log_batiment_bureau' => 'Bat A 105',
];

$r = http_request('POST', 'form.php', ['f' => 'onboarding'], $submission1_data, 'test.agent');
$sub_json = $r['json'] ?? [];
assert_test('Soumission réussie', ($sub_json['success'] ?? false) === true);
assert_test('Submission ID > 0', ($sub_json['submission_id'] ?? 0) > 0, 'ID: ' . ($sub_json['submission_id'] ?? 'null'));
assert_test('Mails interceptés (pas de SMTP)', ($sub_json['mails_count'] ?? 0) >= 1, ($sub_json['mails_count'] ?? 0) . ' mails');

$submission1_id = $sub_json['submission_id'] ?? 0;
$tokens1 = $sub_json['tokens'] ?? [];

assert_test('Token généré pour étape 1', count($tokens1) >= 1, count($tokens1) . ' tokens');
if (!empty($tokens1)) {
    assert_test('Étape 1 = Responsable direct', $tokens1[0]['step_label'] === 'Responsable direct');
    assert_test('Email validateur correct', $tokens1[0]['email'] === 'resp.direct@dreets.gouv.fr');
}

// ── PHASE 3 : Validation étape 1 (Responsable direct) ──────────
echo "\n" . bold("Phase 3 : Validation étape 1 — Responsable direct\n");

if (!empty($tokens1)) {
    $token1 = $tokens1[0]['token'];
    
    // GET — Vérifier que le token est valide
    $r = http_request('GET', 'validate.php', ['token' => $token1], [], 'resp.direct');
    $val_json = $r['json'] ?? [];
    assert_test('Token valide (GET)', ($val_json['result'] ?? '') === 'ok', 'Résultat: ' . ($val_json['result'] ?? 'null'));
    assert_test('Étape = Responsable direct', ($val_json['step_label'] ?? '') === 'Responsable direct');
    assert_test('CSRF token présent pour validation', !empty($val_json['csrf_token']));
    
    // POST — Valider le token
    $r = http_request('POST', 'validate.php', [], [
        'token'   => $token1,
        'action'  => 'valider',
        'comment' => 'Approuvé par le responsable',
    ], 'resp.direct');
    $val_result = $r['json'] ?? [];
    assert_test('Validation étape 1 réussie', ($val_result['result']['status'] ?? '') === 'ok');
    
    // Vérifier que l'étape 2 est déclenchée
    $r = api('tokens', ['submission_id' => $submission1_id]);
    $all_tokens = $r['json'] ?? [];
    $step2_tokens = array_filter($all_tokens, fn($t) => $t['ordre'] == 2);
    assert_test('Étape 2 (IT) déclenchée', count($step2_tokens) >= 1, count($step2_tokens) . ' tokens');
    
    // Vérifier le statut de la soumission
    $r = api('submission', ['submission_id' => $submission1_id]);
    assert_test('Soumission toujours en_cours', ($r['json']['status'] ?? '') === 'en_cours');
}

// ── PHASE 4 : Refus étape 2 ────────────────────────────────────
echo "\n" . bold("Phase 4 : Refus étape 2 — Service informatique\n");

$step2_tokens = array_filter($all_tokens ?? [], fn($t) => $t['ordre'] == 2);
if (!empty($step2_tokens)) {
    $token2 = array_values($step2_tokens)[0]['token'];
    
    $r = http_request('POST', 'validate.php', [], [
        'token'   => $token2,
        'action'  => 'refuser',
        'comment' => 'Matériel non disponible',
    ], 'it.service');
    $ref_result = $r['json'] ?? [];
    assert_test('Refus étape 2 enregistré', ($ref_result['result']['status'] ?? '') === 'ok');
    
    // Vérifier que la soumission est refusée
    $r = api('submission', ['submission_id' => $submission1_id]);
    assert_test('Soumission marquée refusée', ($r['json']['status'] ?? '') === 'refuse');
    assert_test('Date de clôture présente', !empty($r['json']['closed_at']));
} else {
    assert_test('Refus étape 2 (prérequis)', false, 'Pas de token étape 2 disponible');
}

// ── PHASE 5 : Workflow complet de bout en bout ─────────────────
echo "\n" . bold("Phase 5 : Workflow complet — Soumission validée de bout en bout\n");

$r = http_request('POST', 'form.php', ['f' => 'onboarding'], [
    'nom' => 'Martin',
    'prenom' => 'Marie',
    'date_naissance' => '1988-03-22',
    'date_prise_poste' => '2026-08-01',
    'corps_grade' => 'Ingénieur',
    'type_arrivee' => 'Primo-recrutement',
    'affectation' => 'Service RH',
    'quotite' => '100%',
    'type_poste' => 'Portable',
    'log_batiment_bureau' => 'Bat B 210',
], 'marie.martin');
$sub2_json = $r['json'] ?? [];
$submission2_id = $sub2_json['submission_id'] ?? 0;
assert_test('2ème soumission réussie', ($sub2_json['success'] ?? false) === true);

if ($submission2_id > 0) {
    // Valider chaque étape séquentiellement
    for ($step_ordre = 1; $step_ordre <= 4; $step_ordre++) {
        $r = api('tokens', ['submission_id' => $submission2_id]);
        $current_tokens = $r['json'] ?? [];
        $pending = array_filter($current_tokens, fn($t) => $t['done_at'] === null);
        
        if (empty($pending)) {
            // Tous les tokens sont traités, le workflow est peut-être terminé
            break;
        }
        
        $pending_token = array_values($pending)[0];
        $r = http_request('POST', 'validate.php', [], [
            'token'   => $pending_token['token'],
            'action'  => 'valider',
            'comment' => "Validé - {$pending_token['step_label']}",
        ], 'validator');
        
        $step_label = $pending_token['step_label'];
        $status = ($r['json']['result']['status'] ?? 'unknown');
        assert_test("Étape $step_ordre validée ($step_label)", $status === 'ok', "Status: $status");
    }
    
    // Vérifier que la soumission est validée
    $r = api('submission', ['submission_id' => $submission2_id]);
    assert_test('Soumission validée de bout en bout', ($r['json']['status'] ?? '') === 'valide');
    assert_test('Date de clôture renseignée', !empty($r['json']['closed_at']));
}

// ── PHASE 6 : Token déjà traité / invalide ─────────────────────
echo "\n" . bold("Phase 6 : Gestion des tokens déjà traités / invalides\n");

if (!empty($tokens1)) {
    $r = http_request('POST', 'validate.php', [], [
        'token'  => $tokens1[0]['token'],
        'action' => 'valider',
    ], 'resp.direct');
    assert_test('Token déjà traité = already_done', ($r['json']['result']['status'] ?? '') === 'already_done');
}

$r = http_request('POST', 'validate.php', [], [
    'token'  => 'fake_invalid_token_12345',
    'action' => 'valider',
], 'test.agent');
assert_test('Token invalide rejeté', ($r['json']['result']['status'] ?? '') === 'invalid');

$r = http_request('GET', 'validate.php', ['token' => 'fake_invalid_token_12345'], [], 'test.agent');
assert_test('GET token invalide = invalid', ($r['json']['result'] ?? '') === 'invalid');

// ── PHASE 7 : Champs obligatoires manquants ────────────────────
echo "\n" . bold("Phase 7 : Validation des champs obligatoires\n");

$r = http_request('POST', 'form.php', ['f' => 'onboarding'], [
    'nom'    => '',
    'prenom' => '',
], 'test.agent');
$err_json = $r['json'] ?? [];
assert_test('Erreurs de validation détectées', !empty($err_json['field_errors']));
assert_test('Erreur sur nom (obligatoire)', isset($err_json['field_errors']['nom']));
assert_test('Erreur sur prenom (obligatoire)', isset($err_json['field_errors']['prenom']));

// ── PHASE 8 : Annulation de soumission ─────────────────────────
echo "\n" . bold("Phase 8 : Annulation de soumission\n");

// Ajouter destinataires au outboarding
$r = api('forms');
$forms = $r['json'] ?? [];
$ob_form = null;
foreach ($forms as $f) {
    if ($f['slug'] === 'outboarding') { $ob_form = $f; break; }
}

if ($ob_form) {
    $ob_steps = api('steps', ['form_id' => $ob_form['id']])['json'] ?? [];
    $ob_recipients = [
        'Responsable direct'     => 'resp.direct@dreets.gouv.fr',
        'Service informatique'   => 'it.service@dreets.gouv.fr',
        'Ressources humaines'    => 'rh.service@dreets.gouv.fr',
        'Logistique'             => 'logistique@dreets.gouv.fr',
    ];
    foreach ($ob_steps as $step) {
        if (empty($step['recipients']) || $step['recipients'] === null) {
            $email = $ob_recipients[$step['label']] ?? null;
            if ($email) api('add_recipient', ['step_id' => $step['id'], 'email' => $email]);
        }
    }
}

$r = http_request('POST', 'form.php', ['f' => 'outboarding'], [
    'nom' => 'Leroy',
    'prenom' => 'Pierre',
    'date_depart' => '2026-09-30',
    'motif_depart' => 'Retraite',
    'affectation' => 'Service Direction',
], 'pierre.leroy');
$sub3_json = $r['json'] ?? [];
$sub3_id = $sub3_json['submission_id'] ?? 0;
assert_test('Soumission outboarding réussie', ($sub3_json['success'] ?? false) === true);

if ($sub3_id > 0) {
    $r = http_request('POST', 'dashboard.php', [], [
        'action' => 'cancel_submission',
        'submission_id' => $sub3_id,
    ], 'test.agent');
    $cancel_result = $r['json'] ?? [];
    assert_test('Annulation réussie', ($cancel_result['result']['success'] ?? false) === true, ($cancel_result['result']['message'] ?? ''));
    
    $r = api('submission', ['submission_id' => $sub3_id]);
    assert_test('Soumission annulée = refuse', ($r['json']['status'] ?? '') === 'refuse');
}

// ── PHASE 9 : Régénération de token ────────────────────────────
echo "\n" . bold("Phase 9 : Régénération de token (admin)\n");

$r = http_request('POST', 'form.php', ['f' => 'onboarding'], [
    'nom' => 'Bernard',
    'prenom' => 'Sophie',
    'date_naissance' => '1992-11-08',
    'date_prise_poste' => '2026-10-01',
    'corps_grade' => 'Secrétaire administratif',
    'type_arrivee' => 'Stage',
    'affectation' => 'Service Accueil',
    'quotite' => '80%',
    'type_poste' => 'Fixe',
    'log_batiment_bureau' => 'Bat C 301',
], 'sophie.bernard');
$sub4_json = $r['json'] ?? [];
$sub4_id = $sub4_json['submission_id'] ?? 0;

if ($sub4_id > 0) {
    $r = api('tokens', ['submission_id' => $sub4_id]);
    $sub4_tokens = $r['json'] ?? [];
    
    if (!empty($sub4_tokens)) {
        $old_token_id = $sub4_tokens[0]['id'];
        $old_token = $sub4_tokens[0]['token'];
        
        $r = http_request('POST', 'dashboard.php', [], [
            'action' => 'regenerate_token',
            'token_id' => $old_token_id,
        ], 'test.agent');
        $regen_result = $r['json'] ?? [];
        assert_test('Régénération token réussie', ($regen_result['result']['success'] ?? false) === true, ($regen_result['result']['message'] ?? ''));
        
        // Vérifier que l'ancien token est invalidé
        $r = http_request('POST', 'validate.php', [], [
            'token'  => $old_token,
            'action' => 'valider',
        ], 'resp.direct');
        assert_test('Ancien token invalidé', ($r['json']['result']['status'] ?? '') === 'already_done');
        
        // Vérifier qu'un nouveau token existe
        $r = api('tokens', ['submission_id' => $sub4_id]);
        $new_pending = array_filter($r['json'] ?? [], fn($t) => $t['done_at'] === null);
        assert_test('Nouveau token créé', count($new_pending) >= 1, count($new_pending) . ' tokens en attente');
    }
}

// ── PHASE 10 : Contrôle d'accès admin ──────────────────────────
echo "\n" . bold("Phase 10 : Contrôle d'accès admin\n");

// Enlever les droits admin
api('remove_admin', ['email' => 'test.agent@dreets.gouv.fr']);

$r = http_request('GET', 'admin_forms.php', [], [], 'test.agent');
assert_test('Accès admin refusé si pas admin', ($r['json']['error'] ?? '') === 'Accès refusé');

// Re-ajouter admin
api('add_admin', ['email' => 'test.agent@dreets.gouv.fr']);

$r = http_request('GET', 'admin_forms.php', [], [], 'test.agent');
assert_test('Accès admin accordé si admin', $r['http_code'] === 200);

// ── PHASE 11 : Pages HTML (rendu normal) ───────────────────────
echo "\n" . bold("Phase 11 : Rendu des pages (HTML en mode non-test, JSON en mode test)\n");

// Index en mode test (renvoie le HTML mais ne doit pas planter)
$r = http_request('GET', 'index.php', [], [], 'test.agent');
assert_test('Index.php se charge', $r['http_code'] === 200, 'Code: ' . $r['http_code']);
assert_test('Index contient FluxDémat', strpos($r['body'] ?? '', 'FluxDémat') !== false);

$r = http_request('GET', 'my_submissions.php', [], [], 'marie.martin');
assert_test('Mes demandes se charge', $r['http_code'] === 200);
assert_test('Mes demandes mentionne Martin', strpos($r['body'] ?? '', 'Martin') !== false);

$r = http_request('GET', 'dashboard.php', [], [], 'test.agent');
assert_test('Dashboard se charge', $r['http_code'] === 200);

// ── PHASE 12 : Stats finales ───────────────────────────────────
echo "\n" . bold("Phase 12 : Vérification des stats finales\n");

$r = api('stats');
$stats = $r['json'] ?? [];
assert_test('Stats: 2+ formulaires', ($stats['forms'] ?? 0) >= 2);
assert_test('Stats: 3+ soumissions', ($stats['submissions'] ?? 0) >= 3, ($stats['submissions'] ?? 0) . ' soumissions');
assert_test('Stats: 1+ validée', ($stats['valide'] ?? 0) >= 1);
assert_test('Stats: 1+ refusée', ($stats['refuse'] ?? 0) >= 1);

// ── RÉSUMÉ ─────────────────────────────────────────────────────
echo "\n" . bold("═══════════════════════════════════════════════════════════════\n");
echo bold("  RÉSUMÉ : ") . green("$passed réussi(s)") . " / " . red("$failed échoué(s)") . " / " . ($passed + $failed) . " total\n";
echo bold("═══════════════════════════════════════════════════════════════\n");

if (!empty($errors)) {
    echo red("\nTests échoués :\n");
    foreach ($errors as $e) {
        echo red("  • $e\n");
    }
}

// Cleanup
shell_exec("kill $(lsof -t -i:$PORT 2>/dev/null) 2>/dev/null");
shell_exec("rm -f /tmp/wf_test_cookies.txt");

if ($failed > 0) {
    echo yellow("\nDB test conservée pour inspection : $BASE/db/workflow_test.db\n");
} else {
    shell_exec("rm -f $BASE/db/workflow_test.db");
    echo green("\nDB test nettoyée.\n");
}

exit($failed > 0 ? 1 : 0);
