<?php

declare(strict_types=1);
/**
 * @var list<array{id?: string, mime_type?: string, original_name?: string, file_size?: int, uploaded_at?: string}> $attachments
 */
if ($attachments === []) {
    return '';
}

$count = count($attachments);
$rows = '';
foreach ($attachments as $attachment) {
    $icon         = \App\Core\App::html()->getFileIcon((string) ($attachment['mime_type'] ?? ''));
    $name         = \App\Core\App::html()->escape((string) ($attachment['original_name'] ?? ''));
    $mime         = \App\Core\App::html()->escape((string) ($attachment['mime_type'] ?? ''));
    $size         = \App\Core\App::html()->formatFileSize((int) ($attachment['file_size'] ?? 0));
    $date         = \App\Core\App::html()->escape(\App\Core\App::html()->formatDateTimeFr((string) ($attachment['uploaded_at'] ?? 'now')));
    $dl_url       = \App\Core\App::html()->escape('index.php?p=download&id=' . urlencode((string) ($attachment['id'] ?? '')));

    $rows .= <<<HTML
                <tr>
                  <td class="u-bor-pad">
                    {$icon}
                    <strong>{$name}</strong>
                  </td>
                  <td class="btn-sm-5">{$mime}</td>
                  <td class="btn-sm-7">{$size}</td>
                  <td class="btn-sm-7">{$date}</td>
                  <td class="u-bor-pad-tex-2">
                    <a href="{$dl_url}" class="btn btn-secondary btn-xs"><span aria-hidden="true">📥</span> Télécharger</a>
                  </td>
                </tr>
        HTML;
}

return <<<HTML
      <!-- Pièces jointes -->
      <div class="card">
        <h2><span aria-hidden="true">📎</span> Pièces jointes ({$count})</h2>
        <table class="progress-fill">
          <thead>
            <tr>
              <th class="u-bor-pad-tex">Fichier</th>
              <th class="u-bor-pad-tex">Type</th>
              <th class="u-bor-pad-tex">Taille</th>
              <th class="u-bor-pad-tex">Date</th>
              <th class="u-bor-pad-tex-3"></th>
            </tr>
          </thead>
          <tbody>
          {$rows}
          </tbody>
        </table>
      </div>
    HTML;
