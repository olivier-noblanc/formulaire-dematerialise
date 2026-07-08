<?php
// lib/html.php — facade vers HtmlService

/**
 * Alias de App::html()->escape()
 * Gardé pour la compatibilité avec les 530+ appelants restants.
 */
function h(?string $val): string {
    return \App\Core\App::html()->escape($val);
}
