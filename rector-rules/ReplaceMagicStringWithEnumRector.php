<?php

declare(strict_types=1);

namespace App\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Name\FullyQualified;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\Contract\DocumentedRuleInterface;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Replaces magic strings with their enum case counterparts.
 */
final class ReplaceMagicStringWithEnumRector extends AbstractRector implements DocumentedRuleInterface
{
    /**
     * Maps magic string values to their enum FQCN + case name.
     *
     * @var array<string, array{enum: string, case: string}>
     */
    private const array STRING_TO_ENUM = [
        // SubmissionStatus
        'en_cours' => ['enum' => 'App\Enum\SubmissionStatus', 'case' => 'EnCours'],
        'valide' => ['enum' => 'App\Enum\SubmissionStatus', 'case' => 'Valide'],
        'refuse' => ['enum' => 'App\Enum\SubmissionStatus', 'case' => 'Refuse'],
        'annule' => ['enum' => 'App\Enum\SubmissionStatus', 'case' => 'Annule'],

        // FieldType
        'text' => ['enum' => 'App\Enum\FieldType', 'case' => 'Text'],
        'email' => ['enum' => 'App\Enum\FieldType', 'case' => 'Email'],
        'date' => ['enum' => 'App\Enum\FieldType', 'case' => 'Date'],
        'select' => ['enum' => 'App\Enum\FieldType', 'case' => 'Select'],
        'checkbox' => ['enum' => 'App\Enum\FieldType', 'case' => 'Checkbox'],
        'textarea' => ['enum' => 'App\Enum\FieldType', 'case' => 'Textarea'],
        'file' => ['enum' => 'App\Enum\FieldType', 'case' => 'File'],

        // ValidationAction
        'valider' => ['enum' => 'App\Enum\ValidationAction', 'case' => 'Valider'],
        'refuser' => ['enum' => 'App\Enum\ValidationAction', 'case' => 'Refuser'],

        // FilledBy
        'demandeur' => ['enum' => 'App\Enum\FilledBy', 'case' => 'Demandeur'],
        'validator' => ['enum' => 'App\Enum\FilledBy', 'case' => 'Validator'],

        // FieldVisibility
        'owner_only' => ['enum' => 'App\Enum\FieldVisibility', 'case' => 'OwnerOnly'],

        // AdminRequestStatus
        'pending' => ['enum' => 'App\Enum\AdminRequestStatus', 'case' => 'Pending'],
        'approved' => ['enum' => 'App\Enum\AdminRequestStatus', 'case' => 'Approved'],
        'rejected' => ['enum' => 'App\Enum\AdminRequestStatus', 'case' => 'Rejected'],

        // UrgencyLevel
        'overdue' => ['enum' => 'App\Enum\UrgencyLevel', 'case' => 'Overdue'],
        'critical' => ['enum' => 'App\Enum\UrgencyLevel', 'case' => 'Critical'],
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Replace magic strings with backed enum cases',
            []
        );
    }

    public function getNodeTypes(): array
    {
        return [String_::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!($node instanceof String_)) {
            return null;
        }

        $value = $node->value;

        if (!isset(self::STRING_TO_ENUM[$value])) {
            return null;
        }

        // Skip very short strings that might be false positives (SQL fragments, etc.)
        if (strlen($value) < 3) {
            return null;
        }

        // Skip enum files — don't replace case values with self-references
        $file = $this->file->getFilePath();
        if (str_contains($file, '/Enum/')) {
            return null;
        }

        $mapping = self::STRING_TO_ENUM[$value];

        // Build: EnumClass::Case->value
        return new Node\Expr\PropertyFetch(
            new ClassConstFetch(
                new FullyQualified($mapping['enum']),
                $mapping['case']
            ),
            'value'
        );
    }
}
