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
     * Strings that match a business enum value but are NOT business logic
     * in the contexts where they appear:
     * - DB column names (email, date)
     * - HTML5 input types (email, text, date)
     * - HTML element types (select, checkbox, textarea, file)
     * - CSS class names (overdue, critical)
     * - Constant-like strings used pervasively across the codebase
     *
     * @var array<string, string>
     */
    private const array ALLOWED_VALUES = [
        'email' => 'DB column / HTML5 input type — too many non-FieldType usages',
        'text' => 'HTML5 input type default — not always FieldType::Text',
        'date' => 'DB column / HTML5 input type — not always FieldType::Date',
        'select' => 'HTML element type name — used in migrations and HTML context',
        'checkbox' => 'HTML element type name — used in migrations and HTML context',
        'textarea' => 'HTML element type name — used in migrations and HTML context',
        'file' => 'HTML element type name — used in migrations and HTML context',
        'overdue' => 'CSS class name — used alongside UrgencyLevel comparisons',
        'critical' => 'CSS class name — used alongside UrgencyLevel comparisons',
        'pending' => 'URL tab parameter / internal status string — not always AdminRequestStatus',
    ];

    /**
     * Files/patterns where magic strings are allowed (SQL, config, bootstrap, etc.).
     * Uses both / and \ separators for cross-platform path matching.
     */
    private const array ALLOWED_PATTERNS = [
        'bootstrap.php',
        'config.php',
        'helpers.php',
        'install.php',
        'phpstan_inst_stubs.php',
        'deptrac.php',
        '/migrations/',
        '\\migrations\\',
        '/Enum/',
        '\\Enum\\',
        '/phpstan-rules/',
        '\\phpstan-rules\\',
        '/tests/',
        '\\tests\\',
    ];

    /** @phpstan-ignore shipmonk.deadMethod */
    public function getNodeType(): string
    {
        return String_::class;
    }

    /** @phpstan-ignore shipmonk.deadMethod */
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

        // Skip strings that are allowed (non-business usages like DB columns, HTML types, CSS classes)
        if (isset(self::ALLOWED_VALUES[$value])) {
            return [];
        }

        // Skip allowed files
        $fileDescription = $scope->getFileDescription();
        $file = $fileDescription;
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
