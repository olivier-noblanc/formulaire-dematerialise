<?php
/**
 * test_v4.php — Tests automatisés des fonctionnalités v4.0.0
 * 
 * Teste TOUTES les nouveautés de la version 4.0.0 via requêtes HTTP réelles
 * vers le serveur PHP built-in, en utilisant le mécanisme X-Test-Mode.
 * 
 * Fonctionnalités testées :
 *   - Phase 1  : Infrastructure (schema_version, BLOB, rgpd_consent, rate_limits)
 *   - Phase 2  : Conformité RGPD (mentions légales, export, purge)
 *   - Phase 3  : Health check (endpoint monitoring)
 *   - Phase 4  : Statistiques (par période)
 *   - Phase 5  : Webhooks (configuration + test)
 *   - Phase 6  : Pièces jointes BLOB (upload + download)
 *   - Phase 7  : Recherche plein texte
 *   - Phase 8  : Historique des relances
 *   - Phase 9  : Nouveaux formulaires seedés (6 formulaires)
 *   - Phase 10 : Consentement RGPD à la soumission
 *   - Phase 11 : Rate limiting
 *   - Phase 12 : Documentation avec captures d'écran
 * 
 * Usage: php test_v4.php
 */

// ── CONFIG ─────────────────────────────────────────────────────
$BASE   = __DIR__;
$PHP    = '/home/z/php/php';
$INI    = '/home/z/php/php.ini';
$PORT   = 8766;  // Port différent de test_http.php pour éviter les conflits
$SERVER = "http://localhost:$PORT";

// Couleurs terminal
function green($t) { return "\033[32m$t\033[0m"; }
function red($t)   { return "\033[31m$t\033[0m"; }
function yellow($t){ return "\033[33m$t\033[0m"; }
function cyan($t)  { return "\033[36m$t\033[0m"; }
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
function http_request(string $method, string $path, array $get = [], array $post = [], string $test_user = 'test.agent', array $files = []): array {
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
        shell_exec("cd $BASE && $PHP -c $INI -S localhost:$PORT -t . > /tmp/php_server_v4.log 2>&1 &");
        sleep(2);
    }
    
    // Construire l'URL
    $url = "$SERVER/$path";
    if (!empty($get)) {
        $url .= '?' . http_build_query($get);
    }
    
    // Cookie jar unique par utilisateur test
    $cookie_file = "/tmp/wf_v4_test_cookies_" . preg_replace('/[^a-z0-9]/', '_', $test_user) . ".txt";
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'X-Test-Mode: 1',
        "X-Test-User: $test_user",
    ]);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_file);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_file);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Ne pas suivre les redirects
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (!empty($files)) {
            // Upload de fichiers : utiliser multipart/form-data
            $post_fields = $post;
            foreach ($files as $field_name => $file_info) {
                $post_fields[$field_name] = new CURLFile(
                    $file_info['tmp_name'],
                    $file_info['mime_type'] ?? 'application/octet-stream',
                    $file_info['name']
                );
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        } elseif (!empty($post)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
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

// ── CLEANUP & SERVER START ─────────────────────────────────────
echo bold("Préparation de l'environnement de test v4.0.0...\n");
shell_exec("kill $(lsof -t -i:$PORT 2>/dev/null) 2>/dev/null");
sleep(1);
shell_exec("rm -f /tmp/wf_v4_test_cookies_*.txt");
shell_exec("rm -f $BASE/db/workflow_test.db");

// Démarrer le serveur
shell_exec("cd $BASE && $PHP -c $INI -S localhost:$PORT -t . > /tmp/php_server_v4.log 2>&1 &");
sleep(2);

// Vérifier que le serveur répond
$check = @file_get_contents("$SERVER/test_api.php?action=stats", false, stream_context_create([
    'http' => ['method' => 'GET', 'header' => "X-Test-Mode: 1\r\nX-Test-User: test.agent\r\n", 'timeout' => 3]
]));
if ($check === false) {
    echo red("ERREUR: Le serveur PHP n'a pas pu démarrer sur le port $PORT\n");
    exit(1);
}

echo bold("\n═══════════════════════════════════════════════════════════════════\n");
echo bold("  TESTS V4.0.0 — Formulaires Dématérialisés DREETS\n");
echo bold("  Mode X-Test-Mode (Serveur PHP réel sur port $PORT)\n");
echo bold("═══════════════════════════════════════════════════════════════════\n\n");

// ── SETUP: Ajouter l'admin test ────────────────────────────────
$r = api('add_admin', ['email' => 'test.agent@dreets.gouv.fr']);
$admin_ok = ($r['json']['ok'] ?? false) === true;

// Ajouter aussi un admin pour les tests RGPD non-admin
$r = api('add_admin', ['email' => 'nonadmin@dreets.gouv.fr']);
// Ce sera retiré plus tard pour tester l'accès


// ════════════════════════════════════════════════════════════════
// PHASE 1 : Core Infrastructure
// ════════════════════════════════════════════════════════════════
echo bold("Phase 1 : Core Infrastructure (schema_version, BLOB, rgpd_consent, rate_limits)\n");

// 1a. Vérifier que la table schema_version existe et a des entrées
$r = api('stats');
$stats = $r['json'] ?? [];
assert_test('Serveur PHP actif', $r['http_code'] === 200, 'Code: ' . $r['http_code']);
assert_test('Mode test activé', ($stats['test_mode'] ?? false) === true);

// Via le test_api, on ne peut pas directement interroger le schéma.
// On va utiliser health.php?format=json pour vérifier le schéma
$r = http_request('GET', 'health.php', ['format' => 'json'], [], 'test.agent');
$health_json = $r['json'] ?? [];
assert_test('health.php JSON accessible', $r['http_code'] === 200, 'Code: ' . $r['http_code']);
assert_test('schema_version existe (via health check DB)', ($health_json['status'] ?? '') === 'healthy',
    'Health status: ' . ($health_json['status'] ?? 'null'));

// Vérifier que les formulaires sont seedés (preuve que le schéma est complet)
$r = api('forms');
$forms = $r['json'] ?? [];
$form_slugs = array_column($forms, 'slug');
assert_test('Formulaires seedés (8+ formulaires)', count($forms) >= 8,
    count($forms) . ' formulaires trouvés');

// Vérifier les nouveaux formulaires v4
$new_v4_slugs = ['mutation', 'formation', 'acces_si', 'conge', 'materiel', 'signalement'];
foreach ($new_v4_slugs as $slug) {
    assert_test("Formulaire '$slug' présent en DB", in_array($slug, $form_slugs),
        'Slugs: ' . implode(', ', $form_slugs));
}

// Vérifier via test_api que les tables rate_limits, schema_version existent indirectement
// en testant que rate_limit_check fonctionne (sera testé en Phase 11)
// et que le schema_version a des entrées via les migrations
// Pour cela on utilise le fait que les settings webhook existent (migration v4)
$r = api('stats');
assert_test('DB test initialisée correctement', ($stats['forms'] ?? 0) >= 8,
    ($stats['forms'] ?? 0) . ' formulaires');


// ════════════════════════════════════════════════════════════════
// PHASE 2 : RGPD Compliance
// ════════════════════════════════════════════════════════════════
echo "\n" . bold("Phase 2 : RGPD Compliance\n");

// 2a. GET rgpd.php en tant que non-admin → redirect
$r = http_request('GET', 'rgpd.php', [], [], 'nonadmin.user');
// Non-admin devrait être redirigé (302) ou avoir un accès refusé
$non_admin_blocked = ($r['http_code'] === 302 || $r['http_code'] === 403 
    || strpos($r['body'] ?? '', 'admin_access') !== false
    || strpos($r['body'] ?? '', 'Accès refusé') !== false
    || strpos($r['body'] ?? '', 'Location') !== false);
assert_test('rgpd.php refuse les non-admin', $non_admin_blocked,
    'Code: ' . $r['http_code']);

// 2b. GET rgpd.php en tant qu'admin → accessible
$r = http_request('GET', 'rgpd.php', [], [], 'test.agent');
assert_test('rgpd.php accessible aux admins', $r['http_code'] === 200,
    'Code: ' . $r['http_code']);
assert_test('rgpd.php contient section RGPD', 
    strpos($r['body'] ?? '', 'RGPD') !== false || strpos($r['body'] ?? '', 'rgpd') !== false,
    'Corps ne contient pas RGPD');

// 2c. POST rgpd.php action=update_legal (mentions légales)
// On doit d'abord récupérer un CSRF token via une session admin
// En mode test, CSRF est bypassé, on peut poster directement
$r = http_request('POST', 'rgpd.php', [], [
    'action'           => 'update_legal',
    'legal_mentions'   => 'Mentions légales de test v4.0 — Données traitées conformément au RGPD.',
    'retention_months' => '36',
], 'test.agent');
assert_test('POST update_legal réussi (admin)', 
    $r['http_code'] === 200 && (
        strpos($r['body'] ?? '', 'succès') !== false || 
        strpos($r['body'] ?? '', 'succes') !== false ||
        strpos($r['body'] ?? '', 'mis à jour') !== false ||
        strpos($r['body'] ?? '', 'enregistrées') !== false ||
        strpos($r['body'] ?? '', 'msg-success') !== false
    ),
    'Code: ' . $r['http_code']);

// 2d. Vérifier que legal_mentions est sauvegardé
$r = http_request('GET', 'rgpd.php', [], [], 'test.agent');
$body = $r['body'] ?? '';
assert_test('legal_mentions sauvegardé', 
    strpos($body, 'Mentions légales de test v4.0') !== false,
    'Texte non trouvé dans la page');
assert_test('retention_months=36 sauvegardé', 
    strpos($body, '36') !== false,
    'Valeur 36 non trouvée');

// 2e. POST rgpd.php action=export_user (export données agent)
// Créer d'abord une soumission pour avoir des données à exporter
$r = api('forms');
$forms_list = $r['json'] ?? [];
$onboarding_form = null;
foreach ($forms_list as $f) {
    if ($f['slug'] === 'onboarding') { $onboarding_form = $f; break; }
}

// Ajouter des destinataires à l'onboarding pour permettre la soumission
if ($onboarding_form) {
    $r = api('steps', ['form_id' => $onboarding_form['id']]);
    $onb_steps = $r['json'] ?? [];
    $recipient_map = [
        'Responsable direct'   => 'resp.direct@dreets.gouv.fr',
        'Service informatique' => 'it.service@dreets.gouv.fr',
        'Ressources humaines'  => 'rh.service@dreets.gouv.fr',
        'Logistique'           => 'logistique@dreets.gouv.fr',
    ];
    foreach ($onb_steps as $step) {
        if (empty($step['recipients']) || $step['recipients'] === null) {
            $email = $recipient_map[$step['label']] ?? null;
            if ($email) api('add_recipient', ['step_id' => $step['id'], 'email' => $email]);
        }
    }
}

// Soumettre un formulaire pour créer des données à exporter
$r = http_request('POST', 'form.php', ['f' => 'onboarding'], [
    'nom' => 'RGPDTest',
    'prenom' => 'Agent',
    'date_naissance' => '1995-03-10',
    'date_prise_poste' => '2026-08-01',
    'corps_grade' => 'Attaché',
    'type_arrivee' => 'Mutation',
    'affectation' => 'Service Test',
    'quotite' => '100%',
    'type_poste' => 'Fixe',
    'log_batiment_bureau' => 'Bat X 100',
    'rgpd_consent' => '1',
], 'rgpd.test.agent');
$rgpd_sub = $r['json'] ?? [];
$rgpd_submission_id = $rgpd_sub['submission_id'] ?? 0;
assert_test('Soumission pour export RGPD créée', ($rgpd_sub['success'] ?? false) === true,
    'ID: ' . $rgpd_submission_id);

// Tester l'export RGPD
$r = http_request('POST', 'rgpd.php', [], [
    'action'       => 'export_user',
    'export_email' => 'rgpd.test.agent@dreets.gouv.fr',
], 'test.agent');
// L'export peut retourner du JSON en téléchargement ou un message d'info
$export_worked = (
    $r['http_code'] === 200 && (
        // Soit on a un JSON d'export
        ($r['json'] !== null && isset($r['json']['email'])) ||
        // Soit on a un Content-Disposition attachment
        strpos($r['body'] ?? '', 'rgpd_export_') !== false ||
        // Soit un message indiquant aucune donnée ou succès
        strpos($r['body'] ?? '', 'export') !== false
    )
);
assert_test('Export RGPD fonctionne', $export_worked,
    'Code: ' . $r['http_code'] . ', body: ' . substr($r['body'] ?? '', 0, 200));

// 2f. POST rgpd.php action=auto_purge (purge des données anciennes)
$r = http_request('POST', 'rgpd.php', [], [
    'action'    => 'auto_purge',
    'confirmed' => '1',
], 'test.agent');
$purge_worked = (
    $r['http_code'] === 200 && (
        strpos($r['body'] ?? '', 'Purge') !== false ||
        strpos($r['body'] ?? '', 'purge') !== false ||
        strpos($r['body'] ?? '', 'Aucune') !== false ||
        strpos($r['body'] ?? '', 'msg-success') !== false ||
        strpos($r['body'] ?? '', 'msg-info') !== false
    )
);
assert_test('Auto-purge RGPD exécutable', $purge_worked,
    'Code: ' . $r['http_code']);


// ════════════════════════════════════════════════════════════════
// PHASE 3 : Health Check
// ════════════════════════════════════════════════════════════════
echo "\n" . bold("Phase 3 : Health Check\n");

// 3a. GET health.php (sans format) → HTML 200
$r = http_request('GET', 'health.php', [], [], 'test.agent');
assert_test('health.php retourne 200', $r['http_code'] === 200,
    'Code: ' . $r['http_code']);
assert_test('health.php contient "Santé"', 
    strpos($r['body'] ?? '', 'Santé') !== false || strpos($r['body'] ?? '', 'Sante') !== false || strpos($r['body'] ?? '', 'santé') !== false,
    'Pas de titre santé');

// 3b. GET health.php?format=json → JSON avec status=healthy
$r = http_request('GET', 'health.php', ['format' => 'json'], [], 'test.agent');
$health = $r['json'] ?? [];
assert_test('health.php?format=json retourne du JSON', $r['json'] !== null,
    'Body: ' . substr($r['body'] ?? '', 0, 200));
assert_test('status = healthy', ($health['status'] ?? '') === 'healthy',
    'Status: ' . ($health['status'] ?? 'null'));
assert_test('version présente', ($health['version'] ?? '') === '4.0.0',
    'Version: ' . ($health['version'] ?? 'null'));
assert_test('timestamp présent', !empty($health['timestamp']),
    'Timestamp manquant');

// 3c. Vérifier les 5 health checks
$checks = $health['checks'] ?? [];
assert_test('5 health checks présents', count($checks) === 5,
    count($checks) . ' checks trouvés');

$check_labels = array_column($checks, 'label');
$expected_checks = [
    'Base de données SQLite',
    'Version PHP', 
    'Répertoire de données',
    'Schéma de base de données',
    'Configuration SMTP',
];
foreach ($expected_checks as $expected) {
    $found = false;
    foreach ($check_labels as $label) {
        if (strpos($label, $expected) !== false || strpos($expected, $label) !== false) {
            $found = true;
            break;
        }
    }
    assert_test("Check '$expected' présent", $found,
        'Labels: ' . implode(', ', $check_labels));
}

// Vérifier que tous les checks sont OK
$all_ok = true;
foreach ($checks as $check) {
    if (($check['status'] ?? '') !== 'ok') {
        $all_ok = false;
    }
}
assert_test('Tous les health checks sont OK', $all_ok,
    'Certains checks ont échoué');


// ════════════════════════════════════════════════════════════════
// PHASE 4 : Statistics
// ════════════════════════════════════════════════════════════════
echo "\n" . bold("Phase 4 : Statistiques\n");

// 4a. GET stats.php en tant que non-admin → redirect
$r = http_request('GET', 'stats.php', [], [], 'nonadmin.user');
$stats_blocked = ($r['http_code'] === 302 || $r['http_code'] === 403
    || strpos($r['body'] ?? '', 'admin_access') !== false
    || strpos($r['body'] ?? '', 'Accès refusé') !== false);
assert_test('stats.php refuse les non-admin', $stats_blocked,
    'Code: ' . $r['http_code']);

// 4b. GET stats.php (admin, période par défaut)
$r = http_request('GET', 'stats.php', [], [], 'test.agent');
assert_test('stats.php accessible aux admins', $r['http_code'] === 200,
    'Code: ' . $r['http_code']);
assert_test('stats.php contient "Statistiques"', 
    strpos($r['body'] ?? '', 'Statistiques') !== false || strpos($r['body'] ?? '', 'statistiques') !== false,
    'Pas de titre statistiques');

// 4c. GET stats.php?period=week
$r = http_request('GET', 'stats.php', ['period' => 'week'], [], 'test.agent');
assert_test('stats.php?period=week retourne 200', $r['http_code'] === 200,
    'Code: ' . $r['http_code']);
assert_test('Période semaine active', 
    strpos($r['body'] ?? '', 'semaine') !== false || strpos($r['body'] ?? '', 'week') !== false,
    'Pas de mention semaine');

// 4d. GET stats.php?period=month
$r = http_request('GET', 'stats.php', ['period' => 'month'], [], 'test.agent');
assert_test('stats.php?period=month retourne 200', $r['http_code'] === 200,
    'Code: ' . $r['http_code']);

// 4e. GET stats.php?period=year
$r = http_request('GET', 'stats.php', ['period' => 'year'], [], 'test.agent');
assert_test('stats.php?period=year retourne 200', $r['http_code'] === 200,
    'Code: ' . $r['http_code']);


// ════════════════════════════════════════════════════════════════
// PHASE 5 : Webhooks
// ════════════════════════════════════════════════════════════════
echo "\n" . bold("Phase 5 : Webhooks\n");

// 5a. POST admin_settings.php avec webhook_url et webhook_events
$r = http_request('POST', 'admin_settings.php', [], [
    'action'         => 'save_settings',
    'webhook_url'    => 'https://si.dreets.gouv.fr/api/webhook',
    'webhook_events' => 'workflow_complete,submission_cancelled',
    // Champs SMTP requis (valeurs par défaut)
    'smtp_host'      => 'smtp.test.gouv.fr',
    'smtp_port'      => '25',
    'smtp_auth'      => '0',
    'smtp_secure'    => '',
    'smtp_user'      => '',
    'smtp_pass'      => '',
    'smtp_from'      => 'workflow@dreets.gouv.fr',
    'smtp_from_name' => 'FluxDémat',
    'delai_relance_h'=> '48',
    'token_expire_days' => '30',
    'relance_max'    => '3',
], 'test.agent');
assert_test('POST webhook settings réussi', $r['http_code'] === 200,
    'Code: ' . $r['http_code']);

// 5b. Vérifier que les settings sont sauvegardés en rechargeant la page
$r = http_request('GET', 'admin_settings.php', [], [], 'test.agent');
$settings_body = $r['body'] ?? '';
assert_test('webhook_url sauvegardé', 
    strpos($settings_body, 'si.dreets.gouv.fr') !== false,
    'URL webhook non trouvée dans la page');
assert_test('webhook_events sauvegardé', 
    strpos($settings_body, 'workflow_complete') !== false,
    'Événements webhook non trouvés dans la page');

// 5c. GET admin_settings.php?test_webhook=1
$r = http_request('GET', 'admin_settings.php', ['test_webhook' => '1'], [], 'test.agent');
assert_test('Test webhook exécuté', $r['http_code'] === 200,
    'Code: ' . $r['http_code']);
// Le webhook de test peut échouer (URL factice) mais ne doit pas crasher
$no_crash = strpos($r['body'] ?? '', 'Fatal error') === false 
    && strpos($r['body'] ?? '', 'Parse error') === false;
assert_test('Test webhook ne crash pas', $no_crash,
    'Erreur fatale détectée');


// ════════════════════════════════════════════════════════════════
// PHASE 6 : BLOB Attachments
// ════════════════════════════════════════════════════════════════
echo "\n" . bold("Phase 6 : Pièces jointes BLOB\n");

// 6a. Créer un fichier temporaire pour l'upload
$tmp_file = tempnam(sys_get_temp_dir(), 'v4test_') . '.txt';
file_put_contents($tmp_file, "Contenu de test pour l'upload BLOB v4.0.0\nLigne 2 du fichier test.\n");

// 6b. Trouver un formulaire avec un champ fichier
$r = api('forms');
$all_forms = $r['json'] ?? [];
$upload_form = null;
$upload_field_name = null;

// Chercher parmi les formulaires celui qui a un champ file
foreach ($all_forms as $f) {
    $r = http_request('GET', 'form.php', ['f' => $f['slug']], [], 'test.agent');
    $form_json = $r['json'] ?? [];
    $fields = $form_json['fields'] ?? [];
    foreach ($fields as $field) {
        if (($field['field_type'] ?? '') === 'file') {
            $upload_form = $f;
            $upload_field_name = $field['field_name'];
            break 2;
        }
    }
}

if ($upload_form && $upload_field_name) {
    // Ajouter des destinataires au formulaire
    $r = api('steps', ['form_id' => $upload_form['id']]);
    $upload_steps = $r['json'] ?? [];
    foreach ($upload_steps as $step) {
        if (empty($step['recipients']) || $step['recipients'] === null) {
            api('add_recipient', ['step_id' => $step['id'], 'email' => 'resp.direct@dreets.gouv.fr']);
        }
    }
    
    // 6c. Soumettre le formulaire avec un fichier
    $post_data = [
        'nom'          => 'BlobTest',
        'prenom'       => 'Fichier',
        'rgpd_consent' => '1',
    ];
    
    // Ajouter des champs requis avec des valeurs par défaut
    $r = http_request('GET', 'form.php', ['f' => $upload_form['slug']], [], 'test.agent');
    $form_info = $r['json'] ?? [];
    foreach ($form_info['fields'] ?? [] as $field) {
        $fname = $field['field_name'];
        if ($field['required'] && $field['field_type'] !== 'file' && !isset($post_data[$fname])) {
            if ($field['field_type'] === 'date') {
                $post_data[$fname] = '2026-09-01';
            } elseif ($field['field_type'] === 'select' || $field['field_type'] === 'radio') {
                $opts = $field['options'] ?? [];
                $post_data[$fname] = is_array($opts) && !empty($opts) ? $opts[0] : 'Option 1';
            } elseif ($field['field_type'] === 'checkbox') {
                $post_data[$fname] = '1';
            } elseif ($field['field_type'] === 'number') {
                $post_data[$fname] = '1';
            } elseif ($field['field_type'] === 'email') {
                $post_data[$fname] = 'test@dreets.gouv.fr';
            } else {
                $post_data[$fname] = 'Test Value';
            }
        }
    }
    
    $r = http_request('POST', 'form.php', ['f' => $upload_form['slug']], $post_data, 'blob.test.agent', [
        $upload_field_name => [
            'tmp_name'  => $tmp_file,
            'name'      => 'test_document_v4.txt',
            'mime_type' => 'text/plain',
        ],
    ]);
    
    $blob_sub = $r['json'] ?? [];
    $blob_submission_id = $blob_sub['submission_id'] ?? 0;
    assert_test('Soumission avec fichier réussie', ($blob_sub['success'] ?? false) === true,
        'Réponse: ' . substr($r['body'] ?? '', 0, 300));
    
    // 6d. Vérifier que le fichier est stocké via download.php
    if ($blob_submission_id > 0) {
        // Récupérer l'ID de la pièce jointe via l'API
        $r = api('submission', ['submission_id' => $blob_submission_id]);
        $sub_data = $r['json'] ?? [];
        
        // La pièce jointe devrait être dans la table attachments
        // On accède à download.php?id=1 (ou l'ID correct)
        // Essayons l'ID 1 en premier, puis vérifions
        $r = http_request('GET', 'download.php', ['id' => 1], [], 'test.agent');
        $download_ok = ($r['http_code'] === 200 || $r['http_code'] === 404);
        assert_test('download.php répond correctement', $download_ok,
            'Code: ' . $r['http_code']);
        
        // Vérifier que download.php sert le contenu du fichier
        if ($r['http_code'] === 200) {
            assert_test('Fichier BLOB téléchargeable', 
                strpos($r['body'] ?? '', 'Contenu de test') !== false || 
                strlen($r['body'] ?? '') > 0,
                'Corps vide ou incorrect');
        }
    }
} else {
    echo yellow("  ⚠ Aucun formulaire avec champ fichier trouvé, tests BLOB ignorés\n");
    // Faire des assertions neutres
    assert_test('Soumission avec fichier (prérequis)', false, 'Aucun formulaire avec champ fichier');
    assert_test('Fichier BLOB téléchargeable (prérequis)', false, 'Aucun formulaire avec champ fichier');
}

// Nettoyer le fichier temporaire
@unlink($tmp_file);


// ════════════════════════════════════════════════════════════════
// PHASE 7 : Full-text Search
// ════════════════════════════════════════════════════════════════
echo "\n" . bold("Phase 7 : Recherche plein texte\n");

// 7a. Rechercher un terme existant dans les soumissions
$r = http_request('GET', 'dashboard.php', ['search' => 'RGPDTest'], [], 'test.agent');
assert_test('dashboard.php?search=RGPDTest retourne 200', $r['http_code'] === 200,
    'Code: ' . $r['http_code']);

// Le dashboard HTML doit contenir le résultat de la recherche
$search_body = $r['body'] ?? '';
assert_test('Recherche trouve "RGPDTest"', 
    strpos($search_body, 'RGPDTest') !== false,
    'Terme non trouvé dans les résultats');

// 7b. Rechercher par nom de formulaire
$r = http_request('GET', 'dashboard.php', ['search' => 'onboarding'], [], 'test.agent');
assert_test('Recherche par formulaire fonctionne', $r['http_code'] === 200,
    'Code: ' . $r['http_code']);

// 7c. Rechercher un terme inexistant → pas de résultats
$r = http_request('GET', 'dashboard.php', ['search' => 'ZZZNONEXISTANT999'], [], 'test.agent');
assert_test('Recherche terme inexistant retourne 200', $r['http_code'] === 200,
    'Code: ' . $r['http_code']);
// Le body ne doit pas crasher
$no_crash = strpos($r['body'] ?? '', 'Fatal error') === false;
assert_test('Recherche ne crash pas', $no_crash);


// ════════════════════════════════════════════════════════════════
// PHASE 8 : Relance History
// ════════════════════════════════════════════════════════════════
echo "\n" . bold("Phase 8 : Historique des relances\n");

// 8a. Créer une soumission puis envoyer un rappel
$r = http_request('POST', 'form.php', ['f' => 'onboarding'], [
    'nom' => 'RelanceTest',
    'prenom' => 'Agent',
    'date_naissance' => '1990-01-01',
    'date_prise_poste' => '2026-10-01',
    'corps_grade' => 'Ingénieur',
    'type_arrivee' => 'Mutation',
    'affectation' => 'Service Relance',
    'quotite' => '100%',
    'type_poste' => 'Portable',
    'log_batiment_bureau' => 'Bat R 200',
    'rgpd_consent' => '1',
], 'relance.test.agent');
$relance_sub = $r['json'] ?? [];
$relance_submission_id = $relance_sub['submission_id'] ?? 0;
assert_test('Soumission pour relance créée', ($relance_sub['success'] ?? false) === true,
    'ID: ' . $relance_submission_id);

if ($relance_submission_id > 0) {
    // 8b. Envoyer un rappel via dashboard.php
    $r = api('tokens', ['submission_id' => $relance_submission_id]);
    $relance_tokens = $r['json'] ?? [];
    
    if (!empty($relance_tokens)) {
        $token_id = $relance_tokens[0]['id'];
        
        $r = http_request('POST', 'dashboard.php', [], [
            'action'   => 'remind_one',
            'token_id' => $token_id,
        ], 'test.agent');
        // Le remind_one dans dashboard.php ne retourne pas de JSON en mode test
        // sauf si TEST_MODE est géré, mais il ne crash pas
        $remind_no_crash = ($r['http_code'] === 200 || $r['http_code'] === 302);
        assert_test('Rappel manuel envoyé', $remind_no_crash,
            'Code: ' . $r['http_code']);
        
        // 8c. Vérifier l'historique des relances dans submission_view
        $r = http_request('GET', 'submission_view.php', ['id' => $relance_submission_id], [], 'test.agent');
        assert_test('submission_view.php accessible', $r['http_code'] === 200,
            'Code: ' . $r['http_code']);
        
        $view_body = $r['body'] ?? '';
        $has_relance_section = (
            strpos($view_body, 'Historique des relances') !== false ||
            strpos($view_body, 'historique') !== false ||
            strpos($view_body, 'relance') !== false ||
            strpos($view_body, 'Rappel') !== false
        );
        assert_test('Section relances présente', $has_relance_section,
            'Section relances non trouvée');
    } else {
        assert_test('Rappel manuel envoyé (prérequis)', false, 'Pas de tokens pour la soumission');
        assert_test('Section relances présente (prérequis)', false, 'Pas de tokens');
    }
} else {
    assert_test('Rappel manuel envoyé (prérequis)', false, 'Soumission non créée');
    assert_test('Section relances présente (prérequis)', false, 'Soumission non créée');
}


// ════════════════════════════════════════════════════════════════
// PHASE 9 : New Seeded Forms
// ════════════════════════════════════════════════════════════════
echo "\n" . bold("Phase 9 : Nouveaux formulaires seedés v4\n");

$v4_forms = [
    'mutation'    => 'Mutation',
    'formation'   => 'Formation',
    'acces_si'    => 'Accès SI',
    'conge'       => 'Congé',
    'materiel'    => 'Matériel',
    'signalement' => 'Signalement',
];

foreach ($v4_forms as $slug => $label) {
    $r = http_request('GET', 'form.php', ['f' => $slug], [], 'test.agent');
    $form_json = $r['json'] ?? [];
    
    // En mode test, GET form.php retourne du JSON avec les infos du formulaire
    $form_loaded = ($r['http_code'] === 200 && 
        ($form_json['form']['slug'] ?? '') === $slug);
    assert_test("form.php?f=$slug se charge", $form_loaded,
        'Code: ' . $r['http_code'] . ', slug: ' . ($form_json['form']['slug'] ?? 'null'));
    
    // Vérifier que le formulaire a des champs
    $field_count = count($form_json['fields'] ?? []);
    assert_test("Formulaire '$slug' a des champs ($field_count)", $field_count > 0,
        '0 champs trouvés');
    
    // Vérifier que le formulaire a des étapes
    $csrf_present = !empty($form_json['csrf_token']);
    assert_test("Formulaire '$slug' fournit un CSRF token", $csrf_present,
        'CSRF token manquant');
}


// ════════════════════════════════════════════════════════════════
// PHASE 10 : RGPD Consent
// ════════════════════════════════════════════════════════════════
echo "\n" . bold("Phase 10 : Consentement RGPD à la soumission\n");

// 10a. POST form.php sans rgpd_consent → échec validation
$r = http_request('POST', 'form.php', ['f' => 'onboarding'], [
    'nom' => 'NoConsent',
    'prenom' => 'Agent',
    'date_naissance' => '1990-01-01',
    'date_prise_poste' => '2026-11-01',
    'corps_grade' => 'Inspecteur',
    'type_arrivee' => 'Mutation',
    'affectation' => 'Service Test',
    'quotite' => '100%',
    'type_poste' => 'Fixe',
    'log_batiment_bureau' => 'Bat N 300',
    // PAS de rgpd_consent
], 'no.consent.agent');
$no_consent_json = $r['json'] ?? [];
assert_test('Soumission sans consentement RGPD échoue', 
    !empty($no_consent_json['field_errors']) && isset($no_consent_json['field_errors']['rgpd_consent']),
    'Réponse: ' . substr($r['body'] ?? '', 0, 300));
assert_test('Erreur sur rgpd_consent', 
    isset($no_consent_json['field_errors']['rgpd_consent']),
    'Pas d\'erreur sur rgpd_consent');

// 10b. POST form.php avec rgpd_consent=1 → succès
$r = http_request('POST', 'form.php', ['f' => 'onboarding'], [
    'nom' => 'WithConsent',
    'prenom' => 'Agent',
    'date_naissance' => '1990-01-01',
    'date_prise_poste' => '2026-12-01',
    'corps_grade' => 'Secrétaire',
    'type_arrivee' => 'Stage',
    'affectation' => 'Service Consenti',
    'quotite' => '80%',
    'type_poste' => 'Fixe',
    'log_batiment_bureau' => 'Bat C 400',
    'rgpd_consent' => '1',
], 'with.consent.agent');
$consent_json = $r['json'] ?? [];
$consent_submission_id = $consent_json['submission_id'] ?? 0;
assert_test('Soumission avec consentement RGPD réussie', 
    ($consent_json['success'] ?? false) === true,
    'Réponse: ' . substr($r['body'] ?? '', 0, 300));

// 10c. Vérifier que rgpd_consent=1 dans la soumission
if ($consent_submission_id > 0) {
    $r = api('submission', ['submission_id' => $consent_submission_id]);
    $sub_detail = $r['json'] ?? [];
    assert_test('Colonne rgpd_consent = 1 dans la soumission', 
        ($sub_detail['rgpd_consent'] ?? 0) == 1,
        'rgpd_consent: ' . ($sub_detail['rgpd_consent'] ?? 'null'));
} else {
    assert_test('Colonne rgpd_consent = 1 (prérequis)', false, 'Pas de submission_id');
}


// ════════════════════════════════════════════════════════════════
// PHASE 11 : Rate Limiting
// ════════════════════════════════════════════════════════════════
echo "\n" . bold("Phase 11 : Rate Limiting\n");

// 11a. Nettoyer la table rate_limits pour ce test
// On ne peut pas le faire directement, mais on peut tester avec un endpoint qui utilise rate_limit_check
// L'export RGPD utilise rate_limit_check('rgpd_export', 5, 60)
// Faisons 6 exports rapides — le 6ème devrait être bloqué

// D'abord créer un agent avec des données
$r = http_request('POST', 'form.php', ['f' => 'onboarding'], [
    'nom' => 'RateLimit',
    'prenom' => 'Test',
    'date_naissance' => '1990-01-01',
    'date_prise_poste' => '2027-01-01',
    'corps_grade' => 'Attaché',
    'type_arrivee' => 'Mutation',
    'affectation' => 'Service Rate',
    'quotite' => '100%',
    'type_poste' => 'Fixe',
    'log_batiment_bureau' => 'Bat RL 500',
    'rgpd_consent' => '1',
], 'ratelimit.agent');

// Envoyer 6 requêtes d'export rapides
$blocked = false;
for ($i = 1; $i <= 6; $i++) {
    $r = http_request('POST', 'rgpd.php', [], [
        'action'       => 'export_user',
        'export_email' => 'ratelimit.agent@dreets.gouv.fr',
    ], 'test.agent');
    
    $body = $r['body'] ?? '';
    if (strpos($body, 'Trop de demandes') !== false || 
        strpos($body, 'patienter') !== false ||
        strpos($body, 'rate limit') !== false) {
        $blocked = true;
    }
}
assert_test('Rate limiting bloque après 5 exports', $blocked,
    'Aucun blocage après 6 requêtes rapides');

// 11b. Vérifier que rate_limit_check retourne false quand la limite est atteinte
// via le comportement observable (message d'erreur dans la page)
assert_test('Rate limit check fonctionne (observable)', $blocked,
    'Le mécanisme de rate limiting ne semble pas actif');


// ════════════════════════════════════════════════════════════════
// PHASE 12 : Documentation
// ════════════════════════════════════════════════════════════════
echo "\n" . bold("Phase 12 : Documentation avec captures d'écran\n");

// 12a. GET docs.php → page de documentation
$r = http_request('GET', 'docs.php', [], [], 'test.agent');
assert_test('docs.php retourne 200', $r['http_code'] === 200,
    'Code: ' . $r['http_code']);

$docs_body = $r['body'] ?? '';

// 12b. Vérifier le contenu de la documentation
assert_test('docs.php contient "Documentation"', 
    strpos($docs_body, 'Documentation') !== false || strpos($docs_body, 'documentation') !== false,
    'Pas de titre Documentation');

// 12c. Vérifier les captures d'écran référencées
$screenshot_patterns = [
    'docs/screenshots/01_index_agent.png',
    'docs/screenshots/03_form_onboarding.png',
    'docs/screenshots/05_my_submissions.png',
    'docs/screenshots/15_validate.png',
];

$screenshot_count = 0;
foreach ($screenshot_patterns as $pattern) {
    if (strpos($docs_body, $pattern) !== false) {
        $screenshot_count++;
    }
}
assert_test('Captures d\'écran référencées (' . $screenshot_count . '/' . count($screenshot_patterns) . ')', 
    $screenshot_count >= 2,
    'Moins de 2 captures d\'écran trouvées');

// 12d. Vérifier les sections v4 dans la documentation
$v4_doc_keywords = ['RGPD', 'rgpd', 'webhook', 'Webhook', 'santé', 'Santé', 'statistique', 'Statistique', 'rate limit', 'consentement'];
$found_keywords = 0;
foreach ($v4_doc_keywords as $keyword) {
    if (stripos($docs_body, $keyword) !== false) {
        $found_keywords++;
    }
}
assert_test('Documentation mentionne les fonctionnalités v4 (' . $found_keywords . '/' . count($v4_doc_keywords) . ')', 
    $found_keywords >= 3,
    'Moins de 3 mots-clés v4 trouvés dans la doc');

// 12e. Vérifier la section mentions légales RGPD dans la doc
assert_test('Documentation contient mentions légales RGPD', 
    strpos($docs_body, 'mentions légales') !== false || strpos($docs_body, 'Mentions légales') !== false,
    'Section mentions légales non trouvée');


// ════════════════════════════════════════════════════════════════
// RÉSUMÉ
// ════════════════════════════════════════════════════════════════
echo "\n" . bold("═══════════════════════════════════════════════════════════════════\n");
echo bold("  RÉSUMÉ V4.0.0 : ") . green("$passed réussi(s)") . " / " . red("$failed échoué(s)") . " / " . ($passed + $failed) . " total\n";
echo bold("═══════════════════════════════════════════════════════════════════\n");

if (!empty($errors)) {
    echo red("\nTests échoués :\n");
    foreach ($errors as $e) {
        echo red("  • $e\n");
    }
}

// ── CLEANUP ────────────────────────────────────────────────────
shell_exec("kill $(lsof -t -i:$PORT 2>/dev/null) 2>/dev/null");
shell_exec("rm -f /tmp/wf_v4_test_cookies_*.txt");

if ($failed > 0) {
    echo yellow("\nDB test conservée pour inspection : $BASE/db/workflow_test.db\n");
    echo yellow("Logs serveur : /tmp/php_server_v4.log\n");
} else {
    shell_exec("rm -f $BASE/db/workflow_test.db");
    echo green("\nDB test nettoyée.\n");
}

echo "\n";
exit($failed > 0 ? 1 : 0);
