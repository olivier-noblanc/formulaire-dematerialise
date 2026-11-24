<?php
declare(strict_types=1);

namespace App\Tests;

use App\Controller\AdminAlertsController;
use App\Core\App;
use App\Core\Database;
use App\Repository\AlertRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Contrat « notify_who » des règles d'alerte.
 *
 * Trois couches doivent rester cohérentes : la validation statique du
 * repository, le refus explicite à l'écriture (create/update) et la résolution
 * de la sentinelle `custom` côté contrôleur. Aucun test ne couvrait ces trois
 * couches avant l'audit du 2026-11-24.
 */
final class AlertNotifyWhoTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function sentinelProvider(): array
    {
        return [
            'admin'            => ['admin'],
            'submitter'        => ['submitter'],
            'validators'       => ['validators'],
            'admin+submitter'  => ['admin+submitter'],
            'admin+validators' => ['admin+validators'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function validEmailProvider(): array
    {
        return [
            'adresse nominative' => ['manager@exemple.invalid'],
            'adresse courte'     => ['a@b.co'],
            'adresse service'    => ['alertes.dreets@exemple.invalid'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function invalidProvider(): array
    {
        return [
            'chaîne vide'                    => [''],
            'valeur arbitraire'              => ['foo'],
            'sentinelle custom non résolue'  => ['custom'],
            'casse différente'               => ['ADMIN'],
            'sentinelle avec espaces'        => ['admin + submitter'],
            'sentinelle inconnue'            => ['admin+owner'],
            'email sans domaine'             => ['foo@'],
            'email sans partie locale'       => ['@exemple.invalid'],
            'email sans TLD'                 => ['admin@localhost'],
            'email avec espace interne'      => ['foo bar@exemple.invalid'],
            'texte quelconque'               => ['not-an-email'],
        ];
    }

    private function repo(): AlertRepository
    {
        return new AlertRepository(App::getInstance()->get(Database::class));
    }

    /**
     * Appelle le helper privé du contrôleur et retourne le message d'erreur
     * ainsi que la valeur effectivement résolue.
     *
     * @return array{string, string}
     */
    private function normalizeNotifyWho(string $notifyWho, string $customEmail): array
    {
        $controller = new AdminAlertsController();
        $method = new \ReflectionMethod(AdminAlertsController::class, 'normalizeNotifyWho');
        $args = [&$notifyWho, $customEmail];
        $error = $method->invokeArgs($controller, $args);

        return [is_string($error) ? $error : '', $args[0]];
    }

    #[DataProvider('sentinelProvider')]
    public function testSentinelsAreAcceptedByRepository(string $notifyWho): void
    {
        self::assertTrue(AlertRepository::isValidNotifyWho($notifyWho));
        self::assertSame(['', $notifyWho], $this->normalizeNotifyWho($notifyWho, ''));
    }

    #[DataProvider('validEmailProvider')]
    public function testValidEmailsAreAccepted(string $email): void
    {
        self::assertTrue(AlertRepository::isValidNotifyWho($email));
        self::assertSame(['', $email], $this->normalizeNotifyWho($email, ''));
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidValuesAreRejectedByRepository(string $notifyWho): void
    {
        self::assertFalse(AlertRepository::isValidNotifyWho($notifyWho));
    }

    public function testCustomSentinelIsResolvedToTheValidatedCustomEmail(): void
    {
        // `custom` n'est pas une valeur stockable : elle doit être résolue
        // avant la validation, sinon l'alerte est refusée à tort.
        self::assertFalse(AlertRepository::isValidNotifyWho('custom'));
        self::assertSame(['', 'manager@exemple.invalid'], $this->normalizeNotifyWho('custom', 'manager@exemple.invalid'));
    }

    public function testCustomSentinelRequiresAnEmail(): void
    {
        self::assertSame(
            ['L\'adresse email personnalisée est obligatoire.', 'custom'],
            $this->normalizeNotifyWho('custom', '')
        );
    }

    public function testCustomSentinelRejectsAnInvalidEmail(): void
    {
        self::assertSame(
            ['L\'adresse email personnalisée est invalide.', 'custom'],
            $this->normalizeNotifyWho('custom', 'pas-un-email')
        );
    }

    public function testUnresolvedOrUnknownRecipientIsReported(): void
    {
        self::assertSame(
            ['Le destinataire de l\'alerte est invalide.', 'admin+owner'],
            $this->normalizeNotifyWho('admin+owner', '')
        );
    }

    public function testCreateRuleRejectsInvalidNotifyWhoWithoutWriting(): void
    {
        $repo = $this->repo();
        $before = (int) $repo->pdo()->query('SELECT COUNT(*) FROM alert_rules')->fetchColumn();

        try {
            $repo->createRule([
                'form_id'        => 'irrelevant-form',
                'days_before'    => 5,
                'condition_type' => 'steps_incomplete',
                'notify_who'     => 'custom',
                'label'          => 'Règle invalide',
            ]);
            self::fail('createRule doit refuser une sentinelle non résolue.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('notify_who is invalid', $exception->getMessage());
        }

        self::assertSame($before, (int) $repo->pdo()->query('SELECT COUNT(*) FROM alert_rules')->fetchColumn());
    }

    public function testUpdateRuleRejectsInvalidNotifyWhoWithoutWriting(): void
    {
        $repo = $this->repo();
        $before = (int) $repo->pdo()->query('SELECT COUNT(*) FROM alert_rules')->fetchColumn();

        try {
            $repo->updateRule('irrelevant-rule', [
                'days_before'    => 5,
                'condition_type' => 'steps_incomplete',
                'notify_who'     => '',
                'label'          => 'Règle invalide',
                'actif'          => 1,
            ]);
            self::fail('updateRule doit refuser un destinataire vide.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('notify_who is invalid', $exception->getMessage());
        }

        self::assertSame($before, (int) $repo->pdo()->query('SELECT COUNT(*) FROM alert_rules')->fetchColumn());
    }
}
