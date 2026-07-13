<?php

declare(strict_types=1);

namespace App\View;

use App\Core\App;

/**
 * Templates d'emails — enveloppe render_email_template() et build_mail_html().
 *
 * Permet l'injection de dépendances et une API OOP cohérente avec ViewRenderer.
 */
final class EmailView
{
    public function template(string $title, string $bodyHtml): string
    {
        return App::mail()->renderEmailTemplate($title, $bodyHtml);
    }

    /** @param array<string, mixed> $submission */
    public function validationEmail(array $submission, string $stepLabel, string $token): string
    {
        return App::mail()->buildMailHtml($submission, $stepLabel, $token);
    }
}
