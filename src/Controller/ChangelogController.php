<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Contrôleur de la page Journal des modifications (changelog.php).
 *
 * Parse le fichier CHANGELOG.md et affiche les versions de manière structurée.
 * Aucune accès base de données — page purement statique.
 */
final class ChangelogController extends BaseController
{
    public function handle(): void
    {
        $changelog = $this->parseChangelog(dirname(__DIR__, 2) . '/CHANGELOG.md');

        $pageCss = '';
        ob_start();
        ?>
  <h1>📋 Journal des mises à jour — CircuitDémat</h1>
  <div class="current-version">Version actuelle : v<?= \App\Core\App::html()->escape(App::cache()->getLatestVersion()) ?></div>

  <?php if (empty($changelog)): ?>
    <div class="empty-changelog">
      <div class="empty-icon">📝</div>
      <p>Aucun journal de modifications disponible.</p>
    </div>
  <?php else: ?>
    <div class="changelog-explain" role="note" aria-label="À propos de cette page">
      <p>
        <span aria-hidden="true">ℹ️</span>
        <strong><?= \App\Core\App::html()->escape(App::html()->tJargon('Cette page liste les évolutions de l\'application.')) ?></strong>
        <?= \App\Core\App::html()->escape(App::html()->tJargon('Le résumé en français courant est en haut. Le détail technique est en bas, réservé aux experts.')) ?>
      </p>
    </div>

    <?php
    $hasSummaries = false;
    foreach ($changelog as $v) {
        if (!empty($v['summary'])) { $hasSummaries = true; break; }
    }
    ?>
    <?php if ($hasSummaries): ?>
    <section class="changelog-summary" aria-label="En résumé">
      <h2><span aria-hidden="true">📌</span> En résumé</h2>
      <p class="summary-intro"><?= \App\Core\App::html()->escape(App::html()->tJargon('Vue simplifiée — le détail technique suit plus bas, réservé aux experts.')) ?></p>
      <ul class="summary-list">
        <?php
        foreach ($changelog as $v):
            $hasSummary = !empty($v['summary']);
            $badgeCls = $hasSummary ? 'version-recent' : 'version-old';
        ?>
          <li>
            <span class="summary-version <?= $badgeCls ?>">v<?= \App\Core\App::html()->escape($v['version']) ?></span>
            <span class="summary-date"><?= \App\Core\App::html()->escape($v['date']) ?></span>
            <?php if ($hasSummary): ?>
              <span class="summary-text"><?= \App\Core\App::html()->escape($v['summary']) ?></span>
            <?php else: ?>
              <span class="summary-text summary-empty">— Pas de résumé pour cette ancienne version</span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <details class="technical-details">
      <summary><span aria-hidden="true">🔧</span> Voir le détail technique (réservé aux experts)</summary>
      <div class="details-body">
        <div class="version-nav">
          <?php foreach ($changelog as $i => $v): ?>
            <a href="#v-<?= \App\Core\App::html()->escape($v['version']) ?>" <?= $i === 0 ? 'class="active"' : '' ?>>v<?= \App\Core\App::html()->escape($v['version']) ?></a>
          <?php endforeach; ?>
        </div>

        <?php foreach ($changelog as $v): ?>
        <div class="version-card" id="v-<?= \App\Core\App::html()->escape($v['version']) ?>">
          <div class="version-header">
            <h2>v<?= \App\Core\App::html()->escape($v['version']) ?></h2>
            <span class="version-date"><?= \App\Core\App::html()->escape($v['date']) ?></span>
          </div>

          <?php foreach ($v['sections'] as $sectionName => $items):
              $style = $this->sectionStyle($sectionName);
          ?>
            <div class="section-block <?= \App\Core\App::html()->escape($style['cls']) ?>">
              <div class="section-header">
                <span class="section-icon"><?= $style['icon'] ?></span>
                <?= \App\Core\App::html()->escape($sectionName) ?>
              </div>
              <ul class="section-items">
                <?php foreach ($items as $item): ?>
                  <li><?= $this->inlineMd(\App\Core\App::html()->escape($item)) ?></li>
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
        echo $this->renderPage('Journal des modifications', 'changelog', $pageCss, $content);
    }

    /**
     * Parse le fichier CHANGELOG.md et retourne un tableau structuré
     *
     * @return list<array<string, mixed>>
     */
    private function parseChangelog(string $filepath): array
    {
        if (!file_exists($filepath)) {
            return [];
        }

        $content = (string)file_get_contents($filepath);
        $lines = explode("\n", $content);
        $versions = [];
        $currentVersion = null;
        $currentSection = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Titre principal (# ) — on l'ignore
            if (preg_match('/^# (?!#)/', $trimmed)) {
                continue;
            }

            // Version : ## [x.y.z] — date
            if (preg_match('/^## \[(\d+\.\d+\.\d+)\]\s*[—\-]\s*(.+)$/u', $trimmed, $m)) {
                if ($currentVersion !== null) {
                    $versions[] = $currentVersion;
                }
                $currentVersion = [
                    'version' => $m[1],
                    'date' => trim($m[2]),
                    'summary' => '',
                    'sections' => [],
                ];
                $currentSection = null;
                continue;
            }

            // Résumé exécutif optionnel
            if ($currentVersion !== null && preg_match('/^_Résumé\s*:\s*(.+?)_\s*$/u', $trimmed, $m)) {
                $currentVersion['summary'] = trim($m[1]);
                continue;
            }

            // Section : ### Titre
            if ($currentVersion !== null && preg_match('/^### (.+)$/', $trimmed, $m)) {
                $currentSection = trim($m[1]);
                $currentVersion['sections'][$currentSection] = [];
                continue;
            }

            // Ligne vide — on passe
            if ($trimmed === '') {
                continue;
            }

            // Élément de liste : - texte
            if ($currentVersion !== null && $currentSection !== null && preg_match('/^- (.+)$/', $trimmed, $m)) {
                $currentVersion['sections'][$currentSection][] = $m[1];
            }
        }

        // Dernière version
        if ($currentVersion !== null) {
            $versions[] = $currentVersion;
        }

        return $versions;
    }

    /**
     * Convertit le markdown inline en HTML (gras, code, liens)
     */
    private function inlineMd(string $text): string
    {
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/`(.+?)`/', '<code>$1</code>', $text) ?? $text;
        return $text;
    }

    /**
     * Retourne une classe CSS et une icone selon le nom de la section
     *
     * @return array<string, mixed>
     */
    private function sectionStyle(string $section): array
    {
        $lower = mb_strtolower($section);
        if (str_contains($lower, 'sécurité'))   return ['icon' => '🔒', 'cls' => 'section-security'];
        if (str_contains($lower, 'correction'))  return ['icon' => '🔧', 'cls' => 'section-fix'];
        if (str_contains($lower, 'fonctionnalité')) return ['icon' => '✨', 'cls' => 'section-feature'];
        if (str_contains($lower, 'majeure'))     return ['icon' => '🚀', 'cls' => 'section-major'];
        if (str_contains($lower, 'ux') || str_contains($lower, 'accessibilité')) return ['icon' => '🎨', 'cls' => 'section-ux'];
        if (str_contains($lower, 'nettoyage'))   return ['icon' => '🧹', 'cls' => 'section-cleanup'];
        if (str_contains($lower, 'initial'))     return ['icon' => '📌', 'cls' => 'section-initial'];
        return ['icon' => '📄', 'cls' => 'section-default'];
    }
}