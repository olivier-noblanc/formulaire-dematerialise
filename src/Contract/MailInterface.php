<?php

declare(strict_types=1);

namespace App\Contract;

interface MailInterface
{
    public function send(string $to, string $subject, string $body): bool;

    /**
     * @param array<string, mixed> $submission
     */
    public function buildValidationEmail(array $submission, string $stepLabel, string $token): string;

    public function renderEmailTemplate(string $title, string $bodyHtml): string;

    /**
     * Variante détaillée de send() retournant un tableau de diagnostic.
     * Implémentée par MailService::sendDetailed().
     *
     * CS-10 (audit 2026-07-26) : cette méthode était la seule de MailService
     * à ne pas être déclarée dans l'interface — empêchant l'injection d'un
     * mock/stub à des fins de test. Maintenant elle fait partie du contrat.
     *
     * @return array{success:bool,error:string,smtp_log:string,status:string}
     */
    public function sendDetailed(string $to, string $subject, string $body): array;

    /**
     * @param array{data: string, form_label?: string} $submission
     */
    public function buildMailHtml(array $submission, string $stepLabel, string $token): string;

    /**
     * Récupère les N dernières entrées de mail_log.
     *
     * @return array<int, array{id: string, created_at: string, recipient: string, subject: string, status: string, error_message: string, smtp_log: string, actor: string, ip: string}>
     */
    public function getRecentLogs(int $limit = 30): array;
}
