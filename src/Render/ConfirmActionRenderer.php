<?php

declare(strict_types=1);

namespace App\Render;

use App\Core\App;

final class ConfirmActionRenderer
{
    /**
     * @param array{label: string, description: string, params: list<string>, param_label: string, danger: bool} $config
     * @param array<string, string> $getParams
     */
    public static function content(
        string $action,
        array $config,
        string $confirmMessage,
        string $detailText,
        string $cancelUrl,
        string $postUrl,
        array $getParams,
    ): string {
        $e = App::html(...);
        $escape = static fn(string $s): string => $e()->escape($s);

        $cardClass = $config['danger'] ? 'danger' : 'warning';
        $icon = $config['danger'] ? '⚠️' : '🔄';
        $titleClass = $config['danger'] ? '' : 'warning-title';
        $warningBlock = $config['danger']
            ? '<div class="confirm-warning">' . "\n      Cette action est irréversible." . "\n    </div>"
            : '';

        $csrf = App::security()->csrfField();

        $hiddenInputs = '';
        foreach ($config['params'] as $param) {
            $hiddenInputs .= '        <input type="hidden" name="' . $escape($param) . '" value="' . $escape($getParams[$param] ?? '') . '">' . "\n";
        }

        return <<<HTML
              <div class="confirm-card {$cardClass}">
                <div class="confirm-icon">{$icon}</div>
                <div class="confirm-title {$titleClass}">{$escape($config['label'])}</div>
                <div class="confirm-message">
                  {$confirmMessage} <strong>{$detailText}</strong>
                </div>

                {$warningBlock}

                <form method="POST" action="{$escape($postUrl)}">
                  {$csrf}
                  <input type="hidden" name="action" value="{$escape($action)}">
                  <input type="hidden" name="confirmed" value="1">
            {$hiddenInputs}
                  <div class="confirm-actions">
                    <button type="submit" class="btn btn-danger">Confirmer</button>
                    <a href="{$escape($cancelUrl)}" class="btn btn-secondary">Annuler</a>
                  </div>
                </form>
              </div>
            HTML;
    }
}
