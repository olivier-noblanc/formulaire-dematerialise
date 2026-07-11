<?php
declare(strict_types=1);
/**
 * Bug 08 — Dates au format ISO dans plusieurs pages (P2)
 *
 * Symptôme historique : dates `2024-01-15 10:30:00` affichées au lieu de
 * `15/01/2024 à 10:30` dans plusieurs pages : validate.php, admin_access.php,
 * dashboard (lib/render_dashboard.php), my_validations.php.
 *
 * Cause : `\App\Core\App::html()->escape($row['xxx_at'])` affichait la valeur brute ISO de la DB
 *         au lieu de `date('d/m/Y à H:i', strtotime(...))`.
 *
 * Test minimal (version code-source) : pour chaque fichier concerné,
 * vérifier que les emplacements sensibles utilisent bien
 * `date('d/m/Y ...)` (et non `\App\Core\App::html()->escape($row['..._at'])` sans formatage).
 *
 * Fichiers vérifiés :
 *   - validate.php (autour de « Tâche validée le »)
 *   - admin_access.php (autour de `$request['requested_at']` et `$admin['added_at']`)
 *   - lib/render_dashboard.php (autour de `$submitted` et `$date`)
 *   - my_validations.php (autour de « Délai de traitement » et autres dates)
 *
 * Fichier : tests/regression/Bug08_NoIsoDatesTest.php
 *
 * @package tests\regression
 */

/**
 * Vérifie qu'un fichier source utilise bien date('d/m/Y...) pour un pattern
 * d'usage donné, et NON un \App\Core\App::html()->escape($row[...]) brut.
 *
 * @param string $file         Chemin absolu du fichier à inspecter
 * @param string $needle       Sous-chaîne caractéristique (pour trouver la zone)
 * @param string $good_pattern Regex à chercher (présence attendue)
 * @param string $bad_pattern  Regex à chercher (présence interdite, optionnel)
 * @param string $description  Description humaine de la vérification
 * @return array{ok:bool, msg:string}
 */
function bug08_check_date_format(string $file, string $needle, string $good_pattern, string $bad_pattern, string $description): array {
    if (!is_file($file)) {
        return ['ok' => false, 'msg' => "Fichier introuvable : $file"];
    }
    $src = file_get_contents($file);
    if ($src === false) {
        return ['ok' => false, 'msg' => "Impossible de lire : $file"];
    }

    // Localiser la zone d'intérêt (400 caractères autour de $needle)
    $pos = strpos($src, $needle);
    if ($pos === false) {
        // Le needle n'est pas trouvé — on ne peut pas valider cette zone
        return ['ok' => false, 'msg' => "Marqueur « $needle » introuvable dans $file — la structure de la page a peut-être changé"];
    }
    $zone = substr($src, max(0, $pos - 200), 600);

    // Vérifier la présence du bon pattern dans la zone
    if (!preg_match($good_pattern, $zone)) {
        return ['ok' => false, 'msg' => "$description : pattern attendu `$good_pattern` non trouvé dans la zone de « $needle »"];
    }

    // Vérifier l'absence du pattern interdit dans la zone (si fourni)
    if ($bad_pattern !== '' && preg_match($bad_pattern, $zone)) {
        return ['ok' => false, 'msg' => "$description : pattern interdit `$bad_pattern` trouvé dans la zone de « $needle »"];
    }

    return ['ok' => true, 'msg' => ''];
}

/**
 * Lance le test de non-régression Bug 08.
 *
 * @return bool True si succès, false si échec.
 */
function run_bug08_test(): bool {
    $root = dirname(__DIR__, 2);
    $failures = [];
    $successes = [];

    // ── 1. validate.php — « Tâche validée le » doit utiliser date('d/m/Y à H:i', ...) ──
    $r = bug08_check_date_format(
        $root . '/pages/validate.php',
        'Tâche validée le',
        '/date\s*\(\s*[\'"]d\/m\/Y à H:i[\'"]\s*,\s*strtotime/',
        '/h\s*\(\s*\$data\[\'done_at\'\]\s*\)/',
        'validate.php : Tâche validée le'
    );
    if ($r['ok']) $successes[] = 'validate.php (Tâche validée le)';
    else $failures[] = $r['msg'];

    // ── 2. admin_access.php — requested_at doit utiliser date('d/m/Y à H:i', ...) ──
    $r = bug08_check_date_format(
        $root . '/pages/admin_access.php',
        "demandé l'accès admin le",
        '/date\s*\(\s*[\'"]d\/m\/Y à H:i[\'"]\s*,\s*strtotime/',
        '',
        'pages/admin_access.php : demande d\'accès (requested_at)'
    );
    if ($r['ok']) $successes[] = 'admin_access.php (requested_at)';
    else $failures[] = $r['msg'];

    // ── 3. admin_access.php — added_at doit utiliser date('d/m/Y à H:i', ...) ──
    // Bug historique spécifique : ligne 222 affichait `\App\Core\App::html()->escape($admin['added_at'])` brut.
    $r = bug08_check_date_format(
        $root . '/pages/admin_access.php',
        'Date d\'ajout',
        '/date\s*\(\s*[\'"]d\/m\/Y à H:i[\'"]\s*,\s*strtotime/',
        '/h\s*\(\s*\$admin\[\'added_at\'\]\s*\)\s*\?>/',
        'admin_access.php : Date d\'ajout (added_at)'
    );
    if ($r['ok']) $successes[] = 'admin_access.php (added_at)';
    else $failures[] = $r['msg'];

    // ── 4. lib/render_dashboard.php — $submitted doit utiliser date('d/m/Y', ...) ──
    $r = bug08_check_date_format(
        $root . '/lib/render_dashboard.php',
        '$submitted_ts',
        '/date\s*\(\s*[\'"]d\/m\/Y[\'"]/',
        '',
        'render_dashboard.php : submitted_at'
    );
    if ($r['ok']) $successes[] = 'render_dashboard.php (submitted_at)';
    else $failures[] = $r['msg'];

    // ── 5. lib/render_dashboard.php — $val_date_ts doit utiliser date('d/m/Y à H:i', ...) ──
    $r = bug08_check_date_format(
        $root . '/lib/render_dashboard.php',
        '$val_date_ts',
        '/date\s*\(\s*[\'"]d\/m\/Y à H:i[\'"]\s*,\s*\$val_date_ts/',
        '',
        'render_dashboard.php : val_date_ts'
    );
    if ($r['ok']) $successes[] = 'render_dashboard.php (val_date_ts)';
    else $failures[] = $r['msg'];

    // ── 6. my_validations.php — « Soumis le » doit utiliser date('d/m/Y à H:i', ...) ──
    $r = bug08_check_date_format(
        $root . '/pages/my_validations.php',
        'Soumis le',
        '/date\s*\(\s*[\'"]d\/m\/Y à H:i[\'"]\s*,\s*strtotime/',
        '',
        'my_validations.php : Soumis le'
    );
    if ($r['ok']) $successes[] = 'my_validations.php (Soumis le)';
    else $failures[] = $r['msg'];

    // ── 7. my_validations.php — « Délai de traitement » doit utiliser strtotime() + calcul diff ──
    // (pas un format date exact, mais on vérifie qu'il n'y a pas d'affichage ISO brut)
    $r = bug08_check_date_format(
        $root . '/pages/my_validations.php',
        'Délai de traitement',
        '/strtotime\s*\(\s*\$tk\[\'done_at\'\]\s*\)/',
        '',
        'my_validations.php : Délai de traitement'
    );
    if ($r['ok']) $successes[] = 'my_validations.php (Délai de traitement)';
    else $failures[] = $r['msg'];

    // ── 8. my_validations.php — « Traitée le » doit utiliser date('d/m/Y à H:i', strtotime($tk['done_at'])) ──
    $r = bug08_check_date_format(
        $root . '/pages/my_validations.php',
        'Traitée le',
        '/date\s*\(\s*[\'"]d\/m\/Y à H:i[\'"]\s*,\s*strtotime\s*\(\s*\$tk\[\'done_at\'\]\s*\)/',
        '',
        'my_validations.php : Traitée le'
    );
    if ($r['ok']) $successes[] = 'my_validations.php (Traitée le)';
    else $failures[] = $r['msg'];

    if (!empty($failures)) {
        echo "  ❌ Bug08 — " . count($failures) . " vérification(s) échouée(s) sur " . (count($failures) + count($successes)) . " :\n";
        foreach ($failures as $f) {
            echo "     • $f\n";
        }
        return false;
    }

    echo "  ✅ Bug08 — Toutes les dates sont au format français (d/m/Y…) sur les " . count($successes) . " emplacements vérifiés\n";
    return true;
}
