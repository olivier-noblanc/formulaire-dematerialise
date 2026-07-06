<?php
declare(strict_types=1);

namespace App\View;

/**
 * Templates d'emails — enveloppe render_email_template() et build_mail_html().
 *
 * Permet l'injection de dépendances et une API OOP cohérente avec ViewRenderer.
 */
final class EmailView
{
    public function template(string $title, string $bodyHtml): string
    {
        return render_email_template($title, $bodyHtml);
    }

    public function validationEmail(array $submission, string $stepLabel, string $token): string
    {
        return build_mail_html($submission, $stepLabel, $token);
    }
}
