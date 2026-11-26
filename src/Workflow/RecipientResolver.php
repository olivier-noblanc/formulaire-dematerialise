<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Repository\FormRepository;
use App\Repository\SubmissionRepository;
use App\Settings\SettingsService;
use App\Validation\RecipientAddress;

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
        // Cas spécial : {{owner}} et son alias {{owner_email}} (proposé par l'UI).
        if ($recipient === '{{owner}}' || $recipient === '{{owner_email}}') {
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

        $dynamicName = RecipientAddress::dynamicName($recipient);
        if ($dynamicName !== null) {
            $fieldName = $dynamicName;
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
