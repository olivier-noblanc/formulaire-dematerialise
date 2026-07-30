<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * send_mail(), build_mail_html(), render_email_template() et format_bytes()
 * n'existaient QUE dans phpstan_inst_stubs.php (chargé uniquement pour
 * l'analyse statique) — tout appel réel depuis remind.php, alert_check.php
 * ou SubmissionViewController provoquait un "Call to undefined function"
 * fatal, jamais détecté car PHPStan voyait le stub et ne signalait aucune
 * erreur, et aucun test n'appelait ces fonctions globales directement.
 *
 * Ce test aurait évité ce bug en prod : il vérifie que chaque fonction
 * existe réellement (pas juste en stub) ET se comporte correctement.
 *
 * Fix : src/mail_wrappers.php (chargé par helpers.php) définit maintenant
 * les vraies implémentations ; les stubs dupliqués ont été retirés de
 * phpstan_inst_stubs.php pour éviter toute redéclaration.
 */
final class MailWrapperFunctionsExistTest extends TestCase
{
    public function testSendMailFunctionExists(): void
    {
        self::assertTrue(function_exists('send_mail'), 'send_mail() doit être une vraie fonction, pas seulement un stub PHPStan');
    }

    public function testBuildMailHtmlFunctionExists(): void
    {
        self::assertTrue(function_exists('build_mail_html'));
    }

    public function testRenderEmailTemplateFunctionExists(): void
    {
        self::assertTrue(function_exists('render_email_template'));
    }

    public function testFormatBytesFunctionExists(): void
    {
        self::assertTrue(function_exists('format_bytes'));
    }

    public function testSendMailReturnsBoolInDryRunMode(): void
    {
        $pdo = \App\Core\App::getInstance()->get(\App\Core\Database::class)->getPdo();
        $before = $pdo->query("SELECT value FROM settings WHERE key = 'mail_dry_run'")->fetchColumn();
        \App\Core\App::settings()->set('mail_dry_run', '1', 'test');

        $result = send_mail('test@example.com', 'Sujet test', '<p>Corps</p>');

        self::assertIsBool($result);
        self::assertTrue($result, 'send_mail() en mode dry-run doit retourner true (envoi simulé)');

        // Restaurer
        if ($before !== false) {
            $pdo->prepare("UPDATE settings SET value = ? WHERE key = 'mail_dry_run'")->execute([$before]);
        }
    }

    public function testBuildMailHtmlReturnsNonEmptyHtml(): void
    {
        $submission = ['data' => '{}'];
        $html = build_mail_html($submission, 'Étape test', 'token123');

        self::assertIsString($html);
        self::assertNotSame('', $html);
    }

    public function testRenderEmailTemplateWrapsBodyInHtml(): void
    {
        $html = render_email_template('Titre test', '<p>Contenu</p>');

        self::assertIsString($html);
        self::assertStringContainsString('<p>Contenu</p>', $html);
    }

    public function testFormatBytesReturnsHumanReadableString(): void
    {
        self::assertSame('2 Ko', format_bytes(2048));
        self::assertIsString(format_bytes(0));
        self::assertIsString(format_bytes(1_000_000));
    }
}
