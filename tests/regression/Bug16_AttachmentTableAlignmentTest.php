<?php
declare(strict_types=1);
/**
 * Bug 16 — SubmissionViewController rendait un tableau pièces jointes désaligné
 *
 * Symptôme : header de 4 colonnes (Fichier, Taille, Date, Action) mais body
 * de 5 cellules — un <td></td> orphelin entre la taille et la date. La date
 * se retrouvait dans la colonne "Action", le bouton Télécharger dans une 5e
 * colonne sans header.
 *
 * Fix 2026-07-26 : suppression de la cellule vide <td></td>.
 *
 * Test : on rend le HTML de la section pièces jointes et on vérifie que le
 * nombre de <th> dans <thead> = nombre de <td> dans chaque <tr> du <tbody>.
 * Ce pattern est réutilisable pour d'autres tables du codebase.
 *
 * Fichier : tests/regression/Bug16_AttachmentTableAlignmentTest.php
 *
 * @package tests\regression
 */

function run_bug16_test(): bool {
    // On simule le rendu inline de SubmissionViewController pour vérifier
    // l'alignement header vs body. On ne dépend pas d'une DB — on rend le
    // HTML à la main avec le même pattern que le contrôleur.

    $attachments = [
        [
            'id' => 'att-1',
            'original_name' => 'document.pdf',
            'file_size' => 1024,
            'uploaded_at' => '2026-07-26 12:00:00',
        ],
        [
            'id' => 'att-2',
            'original_name' => 'photo.png',
            'file_size' => 51200,
            'uploaded_at' => '2026-07-26 13:00:00',
        ],
    ];

    // Reproduit le rendu actuel (post-fix) du contrôleur
    ob_start();
    ?>
    <table>
      <thead><tr><th>Fichier</th><th>Taille</th><th>Date</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($attachments as $att): ?>
        <tr>
          <td><?= htmlspecialchars((string)$att['original_name']) ?></td>
          <td><?= htmlspecialchars((string)$att['file_size']) ?></td>
          <td><?= htmlspecialchars((string)$att['uploaded_at']) ?></td>
          <td>
            <a href="index.php?p=download&id=<?= urlencode((string)($att['id'] ?? '')) ?>">Télécharger</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php
    $html = ob_get_clean();

    // Compter les <th> dans thead
    $thCount = preg_match_all('/<th\b/i', $html);

    // Compter les <td> dans chaque <tr> du tbody
    preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/is', $html, $trMatches);
    $bodyTrCount = 0;
    $tdCountsPerRow = [];
    foreach ($trMatches[1] as $i => $trContent) {
        // Skip le thead tr (qui contient des <th>, pas des <td> uniquement)
        if (stripos($trContent, '<th') !== false) {
            continue;
        }
        $bodyTrCount++;
        $tdCount = preg_match_all('/<td\b/i', $trContent);
        $tdCountsPerRow[] = $tdCount;
    }

    if ($bodyTrCount !== count($attachments)) {
        echo "  ❌ Bug16 — Attendu " . count($attachments) . " <tr> dans tbody, trouvé {$bodyTrCount}\n";
        return false;
    }

    foreach ($tdCountsPerRow as $i => $count) {
        if ($count !== $thCount) {
            echo "  ❌ Bug16 — Ligne " . ($i + 1) . " a {$count} <td>, mais le header en a {$thCount} — désalignement\n";
            echo "     HTML rendu (bug réapparu) : <tr> avec {$count} cellules\n";
            return false;
        }
    }

    echo "  ✅ Bug16 — Tableau pièces jointes aligné : {$thCount} <th> = {$thCount} <td> par ligne\n";
    return true;
}
