<?php
declare(strict_types=1);
/**
 * test_mail_escaping.php — Test unitaire du contenu des emails.
 *
 * Bug historique : build_mail_html() échappait $form_label avec h() AVANT de
 * le passer à render_email_template() qui fait déjà h($title). Résultat :
 * double-échappement → l'apostrophe ' devenait &#039; puis &amp;#039; →
 * affiché littéralement comme "&#039;" dans le mail reçu par l'utilisateur.
 *
 * Ce test vérifie que :
 *  1. build_mail_html() avec un form_label contenant une apostrophe produit
 *     un HTML qui contient &#039; (simple escape) et NON &amp;#039; (double).
 *  2. render_email_template() ne double-échappe pas son title.
 *  3. Aucun email produit ne contient &amp;# (signature du double-escape).
 *
 * Usage : php tests/test_mail_escaping.php
 */

require_once __DIR__ . '/test_bootstrap.php';
require_once __DIR__ . '/../helpers.php';

if (!defined('TEST_MODE')) define('TEST_MODE', true);

echo "── Test mail escaping (anti-double-escape) ──\n";

$tests_passed = 0;
$tests_failed = 0;

function check_mail(string $name, bool $ok, string $detail = ''): void {
    global $tests_passed, $tests_failed;
    if ($ok) {
        echo "  ✅ $name\n";
        $tests_passed++;
    } else {
        echo "  ❌ $name" . ($detail !== '' ? " — $detail" : '') . "\n";
        $tests_failed++;
    }
}

// ── Test 1 : build_mail_html avec apostrophe dans form_label ──
echo "\n── Test 1 : build_mail_html avec apostrophe dans form_label ──\n";

$submission = [
    'data' => json_encode(['nom' => "Testeur", 'prenom' => "Agent"]),
    'form_label' => "Demande d'accès SI",  // apostrophe dans le label
];

$html = \App\Core\App::mail()->buildMailHtml($submission, "Validation responsable", "abc123token");

// Vérifier que le HTML contient &#039; (simple escape, OK)
$has_simple_escape = strpos($html, '&#039;') !== false;
check_mail(
    "build_mail_html contient &#039; (simple escape de l'apostrophe)",
    $has_simple_escape,
    $has_simple_escape ? '' : 'Aucun &#039; trouvé — escape manquant ?'
);

// Vérifier que le HTML NE contient PAS &amp;#039; (double escape, BUG)
$has_double_escape = strpos($html, '&amp;#039;') !== false || strpos($html, '&amp;#') !== false;
check_mail(
    "build_mail_html NE contient PAS &amp;#039; (double-escape = BUG)",
    !$has_double_escape,
    $has_double_escape ? 'Double-escape détecté ! Le mail afficherait &#039; littéralement.' : ''
);

// Vérifier que le HTML contient le bon titre (avec apostrophe correctement échappée)
$has_correct_title = strpos($html, "Demande d&#039;accès SI — Action requise") !== false;
check_mail(
    "build_mail_html contient le titre 'Demande d&#039;accès SI — Action requise'",
    $has_correct_title,
    $has_correct_title ? '' : 'Titre non trouvé dans le HTML'
);

// ── Test 2 : render_email_template avec apostrophe dans title ──
echo "\n── Test 2 : render_email_template avec apostrophe dans title ──\n";

$html2 = \App\Core\App::mail()->renderEmailTemplate("Demande d'annulation", '<p>Corps</p>');
$has_simple_escape_2 = strpos($html2, "Demande d&#039;annulation") !== false;
check_mail(
    "render_email_template échappe l'apostrophe en &#039; (simple)",
    $has_simple_escape_2,
    $has_simple_escape_2 ? '' : 'Aucun &#039; trouvé'
);

$has_double_escape_2 = strpos($html2, '&amp;#039;') !== false;
check_mail(
    "render_email_template NE double-échappe PAS (pas de &amp;#039;)",
    !$has_double_escape_2,
    $has_double_escape_2 ? 'Double-escape détecté !' : ''
);

// ── Test 3 : build_mail_html avec autres caractères spéciaux ──
echo "\n── Test 3 : build_mail_html avec caractères spéciaux (é, à, ç, <, >, &) ──\n";

$submission2 = [
    'data' => json_encode(['description' => "Café & réunion <test>"]),
    'form_label' => "Formation à l'école",
];

$html3 = \App\Core\App::mail()->buildMailHtml($submission2, "Étape café", "token456");

// Vérifier que & dans les valeurs est échappé en &amp; (simple)
$has_amp_simple = strpos($html3, 'Café &amp; réunion') !== false;
check_mail(
    "Le & dans les valeurs est échappé en &amp; (simple)",
    $has_amp_simple,
    $has_amp_simple ? '' : '& non échappé'
);

// Vérifier qu'il n'y a pas de double-escape &amp;amp;
$has_double_amp = strpos($html3, '&amp;amp;') !== false;
check_mail(
    "NE contient PAS &amp;amp; (double-escape du &)",
    !$has_double_amp,
    $has_double_amp ? 'Double-escape de & détecté !' : ''
);

// Vérifier que < et > dans les valeurs sont échappés
$has_lt_escaped = strpos($html3, '&lt;test&gt;') !== false;
check_mail(
    "Les < et > sont échappés en &lt; et &gt;",
    $has_lt_escaped,
    $has_lt_escaped ? '' : '< > non échappés'
);

// ── Test 4 : rendu global — vérifier qu'aucun &amp;# n'apparaît ──
echo "\n── Test 4 : signature globale anti-double-escape ──\n";

// Le pattern &amp;# (amp suivi de #) est la signature d'un double-escape :
// &#039; → &amp;#039; (le & a été re-échappé en &amp;)
check_mail(
    "build_mail_html ne contient aucun '&amp;#' (signature double-escape)",
    strpos($html, '&amp;#') === false,
);
check_mail(
    "build_mail_html (caractères spéciaux) ne contient aucun '&amp;#'",
    strpos($html3, '&amp;#') === false,
);

// ── Résumé ──
echo "\n═══════════════════════════════════════════════════\n";
echo "  RÉSULTATS : $tests_passed réussi(s) / $tests_failed échoué(s) / " . ($tests_passed + $tests_failed) . " total\n";
echo "═══════════════════════════════════════════════════\n";
exit($tests_failed > 0 ? 1 : 0);
