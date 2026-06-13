<?php
// changelog.php — Affiche le journal des modifications parsé depuis CHANGELOG.md
require_once __DIR__ . '/helpers.php';

/**
 * Parse le fichier CHANGELOG.md et retourne un tableau structuré
 */
function parse_changelog(string $filepath): array {
    if (!file_exists($filepath)) {
        return [];
    }

    $content = file_get_contents($filepath);
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
        if (preg_match('/^## \[(\d+\.\d+\.\d+)\]\s*[—\-]\s*(.+)$/', $trimmed, $m)) {
            $current_version = [
                'version'  => $m[1],
                'date'     => trim($m[2]),
                'sections' => [],
            ];
            $current_section = null;
            continue;
        }

        // Section : ### Titre
        if ($current_version !== null && preg_match('/^### (.+)$/', $trimmed, $m)) {
            $current_section = trim($m[1]);
            $current_version['sections'][$current_section] = [];
            continue;
        }

        // Séparateur ---
        if ($trimmed === '---') {
            if ($current_version !== null) {
                $versions[] = $current_version;
                $current_version = null;
                $current_section = null;
            }
            continue;
        }

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

$changelog = parse_changelog(__DIR__ . '/CHANGELOG.md');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Journal des modifications — DREETS</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='15' fill='%23003189'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial'>D</text></svg>">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { scroll-behavior: smooth; }
    body { font-family: "Marianne", Arial, sans-serif; background: #f5f5fe; color: #1e1e1e; padding: 2rem 1rem; }
    .bandeau { background: #003189; color: #fff; padding: .75rem 2rem; font-size: .85rem; margin: -2rem -1rem 2rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .5rem; }
    .bandeau a { color: #b3c8f0; font-size: .8rem; text-decoration: none; }
    .bandeau a:hover { text-decoration: underline; }
    .container { max-width: 900px; margin: 0 auto; }

    h1 { font-size: 1.8rem; color: #003189; margin-bottom: .5rem; }
    .subtitle { font-size: .9rem; color: #666; margin-bottom: 2rem; }
    .current-version { display: inline-block; background: #003189; color: #fff; padding: .3rem .8rem; border-radius: 3px; font-size: .85rem; font-weight: bold; margin-bottom: 1.5rem; }

    /* Navigation entre versions */
    .version-nav { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 2rem; }
    .version-nav a { padding: .35rem .75rem; border: 1px solid #003189; border-radius: 3px; font-size: .8rem; text-decoration: none; color: #003189; transition: background .15s; }
    .version-nav a:hover, .version-nav a.active { background: #003189; color: #fff; }

    /* Version card */
    .version-card { background: #fff; border: 1px solid #ddd; border-radius: 6px; margin-bottom: 2rem; overflow: hidden; }
    .version-header { background: linear-gradient(135deg, #003189 0%, #1a4fa0 100%); color: #fff; padding: 1.25rem 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .5rem; }
    .version-header h2 { font-size: 1.3rem; font-weight: bold; }
    .version-date { font-size: .85rem; opacity: .85; }

    .section-block { border-bottom: 1px solid #eee; }
    .section-block:last-child { border-bottom: none; }
    .section-header { padding: .75rem 1.5rem; display: flex; align-items: center; gap: .5rem; font-weight: bold; font-size: .95rem; cursor: default; }
    .section-header .section-icon { font-size: 1.1rem; }
    .section-security .section-header { background: #fff3e0; color: #b45309; }
    .section-fix .section-header { background: #e3f2fd; color: #1565c0; }
    .section-feature .section-header { background: #e8f5e9; color: #1a6b3c; }
    .section-major .section-header { background: #f3e5f5; color: #7b1fa2; }
    .section-ux .section-header { background: #fce4ec; color: #c62828; }
    .section-cleanup .section-header { background: #f5f5f5; color: #555; }
    .section-initial .section-header { background: #e0f2f1; color: #00695c; }
    .section-default .section-header { background: #f5f5f5; color: #555; }

    .section-items { padding: .5rem 1.5rem .75rem; list-style: none; }
    .section-items li { padding: .35rem 0; font-size: .88rem; line-height: 1.6; color: #333; border-bottom: 1px solid #f5f5f5; }
    .section-items li:last-child { border-bottom: none; }
    .section-items li::before { content: "•"; color: #003189; font-weight: bold; margin-right: .5rem; }
    .section-items code { background: #f0f0f8; padding: .15rem .4rem; border-radius: 3px; font-size: .82rem; color: #c0392b; }

    .empty-changelog { text-align: center; padding: 3rem; color: #888; }
    .empty-changelog .empty-icon { font-size: 3rem; margin-bottom: 1rem; }

    @media (max-width: 600px) {
      .version-header { padding: 1rem; }
      .section-items { padding: .5rem 1rem .75rem; }
    }
  </style>
</head>
<body>
<div class="bandeau">
  <strong>DREETS</strong> — Direction Régionale de l'Économie, de l'Emploi, du Travail et des Solidarités
  <span><a href="docs.php">📖 Documentation</a> <a href="dashboard.php">📊 Tableau de bord</a></span>
</div>
<div class="container">
  <h1>📋 Journal des modifications</h1>
  <p class="subtitle">Historique des évolutions et corrections du système de workflow</p>
  <div class="current-version">Version actuelle : v<?= h(APP_VERSION) ?></div>

  <?php if (empty($changelog)): ?>
    <div class="empty-changelog">
      <div class="empty-icon">📝</div>
      <p>Aucun journal de modifications disponible.</p>
    </div>
  <?php else: ?>
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
  <?php endif; ?>
</div>
<?= render_footer() ?>
</body>
</html>
