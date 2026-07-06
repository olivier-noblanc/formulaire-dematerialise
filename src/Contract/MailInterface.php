<?php
declare(strict_types=1);

namespace App\Contract;

interface MailInterface
{
    public function send(string $to, string $subject, string $body): bool;
    public function buildValidationEmail(array $submission, string $stepLabel, string $token): string;
    public function renderEmailTemplate(string $title, string $bodyHtml): string;
}
