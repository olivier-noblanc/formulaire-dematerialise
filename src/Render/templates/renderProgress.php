<?php
$fill_cls = $progress_pct === 100 ? 'complete' : ($progress_pct > 0 ? 'in-progress' : 'not-started');
$width    = max($progress_pct, 8);
$width_cls = 'pw-' . (int) $width;
\App\Core\App::css()->rule($width_cls, "width:{$width}%;");

return <<<HTML
      <!-- Progression -->
      <div class="progress-section">
        <div class="progress-bar-container">
          <div class="progress-bar-fill {$fill_cls} {$width_cls}">
            {$progress_pct}%
          </div>
        </div>
        <div class="progress-label">{$done_steps} / {$total_steps} étapes validées</div>
      </div>
    HTML;
