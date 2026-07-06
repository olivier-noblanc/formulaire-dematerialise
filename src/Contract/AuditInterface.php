<?php
declare(strict_types=1);

namespace App\Contract;

interface AuditInterface
{
    public function log(string $action, string $target = '', string $detail = '', string $actor = ''): void;
    public function securityLog(string $event, string $detail = '', string $actor = ''): void;
    /** @return array<int, array<string, mixed>> */
    public function getLogs(int $limit = 100, string $actionFilter = ''): array;
}
