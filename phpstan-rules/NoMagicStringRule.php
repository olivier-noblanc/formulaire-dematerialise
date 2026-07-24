<?php

declare(strict_types=1);

namespace App\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags magic strings that should be replaced with enums.
 *
 * @implements Rule<String_>
 */
class NoMagicStringRule implements Rule
{
    /**
     * Business strings that must be replaced by their enum counterparts.
     * Format: string value => suggested enum class (short name).
     *
     * @var array<string, string>
     */
    private const array BUSINESS_STRINGS = [
        // SubmissionStatus (already migrated — keep as safety net)
        'en_cours' => 'SubmissionStatus::EnCours->value',
        'valide' => 'SubmissionStatus::Valide->value',
        'refuse' => 'SubmissionStatus::Refuse->value',
        'annule' => 'SubmissionStatus::Annule->value',

        // FieldType
        'text' => 'FieldType::Text->value',
        'email' => 'FieldType::Email->value',
        'date' => 'FieldType::Date->value',
        'select' => 'FieldType::Select->value',
        'checkbox' => 'FieldType::Checkbox->value',
        'textarea' => 'FieldType::Textarea->value',
        'file' => 'FieldType::File->value',

        // ValidationAction
        'valider' => 'ValidationAction::Valider->value',
        'refuser' => 'ValidationAction::Refuser->value',

        // FilledBy
        'demandeur' => 'FilledBy::Demandeur->value',
        'validator' => 'FilledBy::Validator->value',

        // FieldVisibility
        'owner_only' => 'FieldVisibility::OwnerOnly->value',

        // AdminRequestStatus
        'pending' => 'AdminRequestStatus::Pending->value',
        'approved' => 'AdminRequestStatus::Approved->value',
        'rejected' => 'AdminRequestStatus::Rejected->value',

        // UrgencyLevel
        'overdue' => 'UrgencyLevel::Overdue->value',
        'critical' => 'UrgencyLevel::Critical->value',
    ];

    /**
     * Files/patterns where magic strings are allowed (SQL, config, bootstrap, etc.).
     */
    private const array ALLOWED_PATTERNS = [
        'bootstrap.php',
        'config.php',
        'helpers.php',
        'install.php',
        'phpstan_inst_stubs.php',
        'deptrac.php',
        '/migrations/',
        '/tests/',
    ];

    public function getNodeType(): string
    {
        return String_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $value = $node->value;

        // Skip empty strings and very short strings
        if ($value === '' || strlen($value) < 2) {
            return [];
        }

        // Skip if not a known business string
        if (!isset(self::BUSINESS_STRINGS[$value])) {
            return [];
        }

        // Skip allowed files
        $fileDescription = $scope->getFileDescription();
        $file = is_string($fileDescription) ? $fileDescription : $fileDescription->getFile();
        foreach (self::ALLOWED_PATTERNS as $pattern) {
            if (str_contains($file, $pattern)) {
                return [];
            }
        }

        $suggestion = self::BUSINESS_STRINGS[$value];

        return [
            RuleErrorBuilder::message("Magic string '{$value}' detected → use {$suggestion} instead.")
                ->identifier('noMagicString')
                ->build(),
        ];
    }
}
