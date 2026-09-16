<?php

declare(strict_types=1);

namespace App\Tests;

use App\Enum\ValidationAction;
use App\Mail\MailService;
use App\Repository\MailRepository;
use App\Repository\SettingsRepository;
use App\Repository\SubmissionRepository;
use App\Repository\TokenRepository;
use App\Settings\SettingsService;
use App\Tests\WorkflowEngineTest\Base;
use App\Workflow\TokenValidationHandler;

/**
 * BUG3 — TokenValidationHandler::validate() ouvrait une transaction et une
 * exception Throwable remontant entre beginTransaction() et commit() laissait
 * la transaction ouverte : la connexion PDO restait avec une transaction active,
 * inutilisable pour le cron différé et les opérations suivantes
 * ("cannot start a transaction within a transaction" / SQLITE_LOCKED).
 *
 * Vérifie que le chemin transactionnel est encapsulé (catch Throwable →
 * rollback sous inTransaction() → rethrow) et qu'une seconde opération reste
 * possible après l'échec.
 */
final class TokenValidationHandlerTransactionTest extends Base
{
    /**
     * Exception lévée par appendToDataJson() (json_encode JSON_THROW_ON_ERROR)
     * au milieu de la transaction : la transaction doit être fermée et le token
     * non consommé (rollback effectif), puis une seconde validation doit réussir.
     */
    public function testExceptionInAppendToDataJsonRollsBackTransactionThenAllowsNextOperation(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $pdo = $this->db->getPdo();
        while ($pdo->inTransaction()) {
            $pdo->rollBack(); // état propre avant l'appel
        }

        // done_by en UTF-8 invalide : json_encode(..., JSON_THROW_ON_ERROR) dans
        // appendToDataJson() lève une JsonException au milieu de la transaction.
        $thrown = null;
        try {
            $this->workflow->validateToken($tokenVal, ValidationAction::Valider->value, '', "\xC3\x28");
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(
            \JsonException::class,
            $thrown,
            'appendToDataJson doit propager la JsonException (jamais l\'avaler).'
        );
        self::assertFalse(
            $pdo->inTransaction(),
            'BUG3: la transaction doit être fermée (rollback) après une exception remontée.'
        );

        // Seconde opération possible : la connexion est réutilisable et le token
        // n'a pas été marqué done (le rollback a bien annulé le markDoneByTokenValue).
        $result = $this->workflow->validateToken($tokenVal, ValidationAction::Valider->value, '', 'validator@test.com');
        self::assertSame(
            'ok',
            $result['status'],
            'BUG3: une seconde opération doit être possible après l\'échec (connexion utilisable, token non consommé).'
        );
        self::assertFalse(
            $pdo->inTransaction(),
            'La transaction doit également être fermée après la seconde opération réussie.'
        );
    }

    /**
     * Cas générique : un Throwable quelconque (ici le callback de lecture du
     * token) survenant juste après beginTransaction() doit aussi déclencher le
     * rollback avant rethrow — pas seulement la JsonException d'appendToDataJson.
     */
    public function testGenericThrowableOnTransactionalPathRollsBackAndAllowsNextOperation(): void
    {
        [$formId, $stepId] = $this->createTestForm();
        $subId = $this->createTestSubmission($formId);
        [, $tokenVal] = $this->createTestToken($subId, $stepId);

        $pdo = $this->db->getPdo();
        while ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $handler = $this->makeHandler();

        $thrown = null;
        try {
            $handler->validate(
                $tokenVal,
                ValidationAction::Valider->value,
                '',
                'validator@test.com',
                static function (string $submissionId): void {},
                static function (string $token): never {
                    throw new \RuntimeException('boom dans getTokenWithContext');
                },
            );
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(\RuntimeException::class, $thrown);
        self::assertFalse(
            $pdo->inTransaction(),
            'BUG3: tout Throwable sur le chemin transactionnel doit fermer la transaction.'
        );

        // Seconde opération possible : la connexion accepte une nouvelle transaction.
        $result = $this->workflow->validateToken($tokenVal, ValidationAction::Valider->value, '', 'validator@test.com');
        self::assertSame('ok', $result['status']);
    }

    private function makeHandler(): TokenValidationHandler
    {
        $settings = new SettingsService(new SettingsRepository($this->db));

        return new TokenValidationHandler(
            new TokenRepository($this->db),
            new SubmissionRepository($this->db),
            new MailService(new MailRepository($this->db), $settings),
        );
    }
}