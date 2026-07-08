<?php
// changelog.php — Affiche le journal des modifications parsé depuis CHANGELOG.md
// ITER1-A (Lead Designer) : refonte UX pour M. Robert (70 ans, non technicien).
//   - Titre clair « Journal des mises à jour — CircuitDémat » en haut de page.
//   - Encadré explicatif : le résumé en français courant est en haut, le détail
//     technique en bas (dans un <details> fermé par défaut).
//   - Section « En résumé » prédominante (polices ≥ 16px, fond Marianne).
//   - Badges version colorés en bleu républicain pour les versions récentes.
//   - t_jargon() appliqué sur les textes visibles (titre, sous-titre, intro).
require_once dirname(__DIR__) . '/helpers.php';
use App\Core\App;

/**
 * Parse le fichier CHANGELOG.md et retourne un tableau structuré
 *
 * @return list<array<string, mixed>>
 */
function parse_changelog(string $filepath): array {
    if (!file_exists($filepath)) {
        return [];
    }

    $content = (string)file_get_contents($filepath);
    $lines   = explode("\n", $content);
    $versions = [];
    $current_version = null;
    $current_section = null;

    foreach ($lines as $line) {
        $trimmed = trim($line);

        // Titre principal (# ) — on l'ignore
        if (preg_match('/^# (?!#)/', $trimmed)) {
            continue;
        }

        // Version : ## [x.y.z] — date
        // Quand on rencontre une nouvelle version, on sauvegarde la précédente.
        // S4-CHANGELOG : modificateur 'u' (UTF-8) ajouté pour matcher correctement
        // l'em-dash « — » (U+2014, 3 octets en UTF-8) comme UN seul caractère —
        // sans 'u', la classe [—\-] matchait n'importe quel octet de la séquence,
        // laissant des octets orphelins (\x80\x94) en tête de la date, ce que
        // htmlspecialchars() transformait en chaîne vide → date invisible en HTML.
        if (preg_match('/^## \[(\d+\.\d+\.\d+)\]\s*[—\-]\s*(.+)$/u', $trimmed, $m)) {
            // Sauvegarder la version précédente si elle existe
            // (on utilise ## comme délimiteur naturel, pas --- qui peut apparaître dans le contenu)
            if ($current_version !== null) {
                $versions[] = $current_version;
            }
            $current_version = [
                'version'  => $m[1],
                'date'     => trim($m[2]),
                'summary'  => '',   // S4-CHANGELOG : résumé exécutif optionnel (vide si absent)
                'sections' => [],
            ];
            $current_section = null;
            continue;
        }

        // S4-CHANGELOG — Résumé exécutif optionnel (ligne _Résumé : <phrase>._)
        // Placée juste après le header de version, avant les sections ###.
        // Compréhensible par un non-technicien (M. Robert, 70 ans).
        // Regex en UTF-8 (modificateur u) — le "é" est matché littéralement.
        if ($current_version !== null && preg_match('/^_Résumé\s*:\s*(.+?)_\s*$/u', $trimmed, $m)) {
            $current_version['summary'] = trim($m[1]);
            continue;
        }

        // Section : ### Titre
        if ($current_version !== null && preg_match('/^### (.+)$/', $trimmed, $m)) {
            $current_section = trim($m[1]);
            $current_version['sections'][$current_section] = [];
            continue;
        }

        // NOTE : on ne traite plus "---" comme séparateur de version.
        // Bug v5.25.2 (découvert par l'utilisateur) : le parser s'arrêtait au 1er "---"
        // rencontré dans le contenu (utilisé comme séparateur horizontal markdown),
        // et n'affichait que les versions jusqu'à 2.5.0.
        // Le délimiteur naturel est le ## [x.y.z] ci-dessus.

        // Ligne vide — on passe
        if ($trimmed === '') {
            continue;
        }

        // Élément de liste : - texte
        if ($current_version !== null && $current_section !== null && preg_match('/^- (.+)$/', $trimmed, $m)) {
            $current_version['sections'][$current_section][] = $m[1];
        }
    }

    // Dernière version (si pas de --- à la fin)
    if ($current_version !== null) {
        $versions[] = $current_version;
    }

    return $versions;
}

/**
 * Convertit le markdown inline en HTML (gras, code, liens)
 */
function inline_md(string $text): string {
    // **bold** → <strong>
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    // `code` → <code>
    $text = preg_replace('/`(.+?)`/', '<code>$1</code>', $text);
    return $text;
}

/**
 * Retourne une classe CSS et une icone selon le nom de la section
 *
 * @return array<string, mixed>
 */
function section_style(string $section): array {
    $lower = mb_strtolower($section);
    if (strpos($lower, 'sécurité') !== false)   return ['icon' => '🔒', 'cls' => 'section-security'];
    if (strpos($lower, 'correction') !== false)  return ['icon' => '🔧', 'cls' => 'section-fix'];
    if (strpos($lower, 'fonctionnalité') !== false) return ['icon' => '✨', 'cls' => 'section-feature'];
    if (strpos($lower, 'majeure') !== false)     return ['icon' => '🚀', 'cls' => 'section-major'];
    if (strpos($lower, 'ux') !== false || strpos($lower, 'accessibilité') !== false) return ['icon' => '🎨', 'cls' => 'section-ux'];
    if (strpos($lower, 'nettoyage') !== false)   return ['icon' => '🧹', 'cls' => 'section-cleanup'];
    if (strpos($lower, 'initial') !== false)     return ['icon' => '📌', 'cls' => 'section-initial'];
    return ['icon' => '📄', 'cls' => 'section-default'];
}

$changelog = parse_changelog(dirname(__DIR__) . '/CHANGELOG.md');
?>
<?php
$page_css = '';
ob_start();
?>
  <h1>📋 Journal des mises à jour — CircuitDémat</h1>
  <div class="current-version">Version actuelle : v<?= h(App::cache()->getLatestVersion()) ?></div>

  <?php if (empty($changelog)): ?>
    <div class="empty-changelog">
      <div class="empty-icon">📝</div>
      <p>Aucun journal de modifications disponible.</p>
    </div>
  <?php else: ?>
    <!-- ITER1-A — Encadré explicatif : M. Robert comprend en 5 s ce que contient la page. -->
    <div class="changelog-explain" role="note" aria-label="À propos de cette page">
      <p>
        <span aria-hidden="true">ℹ️</span>
        <strong><?= h(App::html()->tJargon('Cette page liste les évolutions de l\'application.')) ?></strong>
        <?= h(App::html()->tJargon('Le résumé en français courant est en haut. Le détail technique est en bas, réservé aux experts.')) ?>
      </p>
    </div>

    <!-- S4-CHANGELOG — Section "En résumé" : vue exécutive pour les non-techniciens.
         ITER1-A : section rendue PRÉÉMINENTE (polices ≥ 16px, fond Marianne, bordure
         bleu républicain, titre H2 proéminent). M. Robert comprend en 5 s les
         évolutions récentes sans lire le détail technique.
         Affichée uniquement si au moins une version a un résumé. -->
    <?php
    $has_summaries = false;
    foreach ($changelog as $v) {
        if (!empty($v['summary'])) { $has_summaries = true; break; }
    }
    ?>
    <?php if ($has_summaries): ?>
    <section class="changelog-summary" aria-label="En résumé">
      <h2><span aria-hidden="true">📌</span> En résumé</h2>
      <p class="summary-intro"><?= h(App::html()->tJargon('Vue simplifiée — le détail technique suit plus bas, réservé aux experts.')) ?></p>
      <ul class="summary-list">
        <?php
        // ITER1-A — Couleur des badges version :
        //   - versions récentes (avec summary en français courant) → bleu républicain
        //   - versions historiques sans summary → gris discret
        foreach ($changelog as $v):
            $has_summary = !empty($v['summary']);
            $badge_cls = $has_summary ? 'version-recent' : 'version-old';
        ?>
          <li>
            <span class="summary-version <?= $badge_cls ?>">v<?= h($v['version']) ?></span>
            <span class="summary-date"><?= h($v['date']) ?></span>
            <?php if ($has_summary): ?>
              <span class="summary-text"><?= h($v['summary']) ?></span>
            <?php else: ?>
              <span class="summary-text summary-empty">— Pas de résumé pour cette ancienne version</span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <!-- ITER1-A — Détail technique masqué par défaut dans un <details>.
         M. Robert lit « En résumé » en haut. Le détail section par section,
         avec jargon technique, est réservé aux experts (admin, équipe IT). -->
    <details class="technical-details">
      <summary><span aria-hidden="true">🔧</span> Voir le détail technique (réservé aux experts)</summary>
      <div class="details-body">
        <!-- Navigation rapide entre versions -->
        <div class="version-nav">
          <?php foreach ($changelog as $i => $v): ?>
            <a href="#v-<?= h($v['version']) ?>" <?= $i === 0 ? 'class="active"' : '' ?>>v<?= h($v['version']) ?></a>
          <?php endforeach; ?>
        </div>

        <?php foreach ($changelog as $v): ?>
        <div class="version-card" id="v-<?= h($v['version']) ?>">
          <div class="version-header">
            <h2>v<?= h($v['version']) ?></h2>
            <span class="version-date"><?= h($v['date']) ?></span>
          </div>

          <?php foreach ($v['sections'] as $section_name => $items):
              $style = section_style($section_name);
          ?>
            <div class="section-block <?= h($style['cls']) ?>">
              <div class="section-header">
                <span class="section-icon"><?= $style['icon'] ?></span>
                <?= h($section_name) ?>
              </div>
              <ul class="section-items">
                <?php foreach ($items as $item): ?>
                  <li><?= inline_md(h($item)) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </details>
  <?php endif; ?>
<?php
$content = (string)ob_get_clean();
echo render_page('Journal des modifications', 'changelog', $page_css, $content);
