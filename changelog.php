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
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><defs><linearGradient id='g' x1='0' y1='0' x2='1' y2='1'><stop offset='0%25' stop-color='%231E40AF'/><stop offset='100%25' stop-color='%233B82F6'/></linearGradient></defs><rect width='100' height='100' rx='20' fill='url(%23g)'/><text x='50' y='72' font-size='60' text-anchor='middle' fill='white' font-family='Arial' font-weight='bold'>D</text></svg>">
  <?php require_once __DIR__ . '/style.php'; ?>
  <style>
    /* Overrides */
    body { padding: 2rem 1rem; }
    .bandeau { margin: -2rem -1rem 2rem; }
    .container { max-width: 900px; padding: 0; }
    h1 { font-size: 1.8rem; margin-bottom: .5rem; }

    /* Page-specific */
    .subtitle { font-size: .9rem; color: #666; margin-bottom: 2rem; }
    .current-version { display: inline-block; background: var(--c-primary-dark); color: var(--c-text-inverse); padding: .3rem .8rem; border-radius: var(--r-sm); font-size: .85rem; font-weight: bold; margin-bottom: 1.5rem; }

    /* Navigation entre versions */
    .version-nav { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: 2rem; }
    .version-nav a { padding: .35rem .75rem; border: 1px solid var(--c-primary-dark); border-radius: var(--r-sm); font-size: .8rem; text-decoration: none; color: var(--c-primary-dark); transition: background .15s; }
    .version-nav a:hover, .version-nav a.active { background: var(--c-primary-dark); color: var(--c-text-inverse); }

    /* Version card */
    .version-card { background: var(--c-surface); border: 1px solid var(--c-border); border-radius: var(--r-md); margin-bottom: 2rem; overflow: hidden; }
    .version-header { background: linear-gradient(135deg, var(--c-primary-dark) 0%, var(--c-primary) 100%); color: var(--c-text-inverse); padding: 1.25rem 1.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .5rem; }
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
    .section-items li::before { content: "•"; color: var(--c-primary-dark); font-weight: bold; margin-right: .5rem; }
    .section-items code { background: var(--c-primary-50); padding: .15rem .4rem; border-radius: var(--r-sm); font-size: .82rem; color: var(--c-danger-dark); }

    .empty-changelog { text-align: center; padding: 3rem; color: #888; }
    .empty-changelog .empty-icon { font-size: 3rem; margin-bottom: 1rem; }

    @media (max-width: 600px) {
      .version-header { padding: 1rem; }
      .section-items { padding: .5rem 1rem .75rem; }
    }
  </style>
</head>
<body>
<a href="#main-content" class="skip-link">Aller au contenu principal</a>
<?= render_nav('changelog') ?>
<main class="container" id="main-content">
<?= render_breadcrumb([['Accueil', 'index.php'], ['Journal des modifications']]) ?>
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
</main>
<?= render_footer() ?>
</body>
</html>
