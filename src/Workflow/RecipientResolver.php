<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Repository\FormRepository;
use App\Repository\SubmissionRepository;
use App\Settings\SettingsService;

/**
 * Résout les destinataires dynamiques ({{owner}}, {{field_name}}).
 *
 * Extrait de WorkflowEngine (H-01, 2026-08-04).
 */
final readonly class RecipientResolver
{
    public function __construct(
        private SettingsService $settingsService,
        private SubmissionRepository $submissionRepository,
        private FormRepository $formRepository,
    ) {}

    /** @param array<string, mixed> $formData */
    public function resolve(string $recipient, mixed $formData, ?string $submissionId = null): string
    {
        // Cas spécial : {{owner}}
        if ($recipient === '{{owner}}') {
            if ($submissionId !== null) {
                $fid = $this->submissionRepository->findFormIdById($submissionId);
                if ($fid !== null && $fid !== '') {
                    $owners = $this->formRepository->findOwnersByFormId($fid);
                    $firstOwnerEmail = $owners[0]['email'] ?? '';
                    if ($owners !== [] && filter_var($firstOwnerEmail, FILTER_VALIDATE_EMAIL) !== false) {
                        return $firstOwnerEmail;
                    }
                    $adminEmail = $this->settingsService->get('admin_email');
                    if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) !== false) {
                        return $adminEmail;
                    }
                }
            }
            return $recipient;
        }

        if (preg_match('/^\{\{([a-z][a-z0-9_]*)\}\}$/', $recipient, $m) === 1) {
            $fieldName = $m[1];
            if (isset($formData[$fieldName]) && (bool)($formData[$fieldName])) {
                $resolved = trim((string) $formData[$fieldName]);
                if (filter_var($resolved, FILTER_VALIDATE_EMAIL) !== false) {
                    return $resolved;
                }
            }
            foreach ($formData as $key => $val) {
                if (strtolower((string) $key) === $fieldName && $val !== '' && $val !== null && $val !== '0') {
                    $resolved = trim((string) $val);
                    if (filter_var($resolved, FILTER_VALIDATE_EMAIL) !== false) {
                        return $resolved;
                    }
                }
            }
            return $recipient;
        }

        return $recipient;
    }
}
