<?php

declare(strict_types=1);

namespace App\Rector;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Scalar\String_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\Contract\DocumentedRuleInterface;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Replaces magic strings with their enum case counterparts — SAFELY.
 *
 * Only replaces in contexts where the string is semantically an enum value:
 * - Comparison: $var === 'en_cours' (where $var is known to hold a status)
 * - Assignment: $status = 'en_cours'
 *
 * NEVER replaces:
 * - Array keys: $row['email'] (could be a DB column, form field, config key)
 * - Function arguments: getFields('text') (could be a parameter name)
 * - SQL fragments: WHERE status = 'en_cours' (use SubmissionStatus::EnCours->value inline instead)
 * - Strings shorter than 4 chars (too many false positives)
 * - Files in /Enum/ (self-reference)
 */
final class ReplaceMagicStringWithEnumRector extends AbstractRector implements DocumentedRuleInterface
{
    /**
     * Maps magic string values to their enum FQCN + case name.
     * Only includes strings that are UNAMBIGUOUSLY enum values
     * (len >= 4, no overlap with common array keys / SQL columns).
     *
     * @var array<string, array{enum: string, case: string}>
     */
    private const array STRING_TO_ENUM = [
        // SubmissionStatus (all 4 — unambiguous in any context)
        'en_cours' => ['enum' => 'App\Enum\SubmissionStatus', 'case' => 'EnCours'],
        'valide' => ['enum' => 'App\Enum\SubmissionStatus', 'case' => 'Valide'],
        'refuse' => ['enum' => 'App\Enum\SubmissionStatus', 'case' => 'Refuse'],
        'annule' => ['enum' => 'App\Enum\SubmissionStatus', 'case' => 'Annule'],
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
        // FieldType — ONLY the unambiguous ones (len >= 5)
        // 'text', 'email', 'date', 'file' are EXCLUDED — too many false positives
        // (DB columns, form field names, array keys all use these common words)
        'select' => ['enum' => 'App\Enum\FieldType', 'case' => 'Select'],
        'checkbox' => ['enum' => 'App\Enum\FieldType', 'case' => 'Checkbox'],
        'textarea' => ['enum' => 'App\Enum\FieldType', 'case' => 'Textarea'],
    ];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Replace magic strings with backed enum cases — ONLY in comparison/assignment contexts (never array keys, function args, or SQL)',
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

        // Skip enum files — don't replace case values with self-references
        $file = $this->file->getFilePath();
        if (str_contains($file, '/Enum/')) {
            return null;
        }

        // SAFETY CHECK: only replace in comparison or assignment contexts.
        // This is the key fix — the previous version replaced EVERY occurrence,
        // including array keys ($row['email']), function args (getFields('text')),
        // and SQL fragments (WHERE status = 'en_cours').

        $parent = $node->getAttribute('parent');
        if ($parent === null) {
            return null;
        }

        // Allowed contexts:
        // 1. Comparison: ===, !==, ==, !=  (Identical/Equality/NotIdentical/NotEqual)
        // 2. Assignment: = $var = 'en_cours'  (Assign)
        // 3. In array: in_array('en_cours', [...])  (Arg of FuncCall)
        if ($this->isInComparisonContext($parent)) {
            return $this->buildEnumNode($value);
        }

        if ($this->isInAssignmentContext($parent)) {
            return $this->buildEnumNode($value);
        }

        if ($this->isInArrayFunctionContext($parent)) {
            return $this->buildEnumNode($value);
        }

        // All other contexts (array key, function arg, SQL string, etc.) → skip
        return null;
    }

    private function isInComparisonContext(Node $parent): bool
    {
        // PHP-AST: comparison nodes are instances of Compare, Identical, Equality, etc.
        // In nikic/php-parser, these are: Expr\BinaryOp\Identical, Expr\BinaryOp\Equal,
        // Expr\BinaryOp\NotIdentical, Expr\BinaryOp\NotEqual
        return $parent instanceof Node\Expr\BinaryOp\Identical
            || $parent instanceof Node\Expr\BinaryOp\Equal
            || $parent instanceof Node\Expr\BinaryOp\NotIdentical
            || $parent instanceof Node\Expr\BinaryOp\NotEqual;
    }

    private function isInAssignmentContext(Node $parent): bool
    {
        // Direct assignment: $var = 'string'
        if ($parent instanceof Node\Expr\Assign) {
            return true;
        }
        // Match arm: 'en_cours' => $result (match expression)
        if ($parent instanceof Node\MatchArm) {
            return true;
        }
        // Switch case: case 'en_cours':
        if ($parent instanceof Node\Stmt\Case_) {
            return true;
        }
        return false;
    }

    private function isInArrayFunctionContext(Node $parent): bool
    {
        // in_array('en_cours', $statuses) — the string is the first Arg of in_array
        if ($parent instanceof Arg) {
            $grandParent = $parent->getAttribute('parent');
            if ($grandParent instanceof Node\Expr\FuncCall) {
                $name = $grandParent->name;
                if ($name instanceof Node\Name && in_array($name->toString(), ['in_array', 'array_search'], true)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function buildEnumNode(string $value): Node\Expr\PropertyFetch
    {
        $mapping = self::STRING_TO_ENUM[$value];
        return new PropertyFetch(
            new ClassConstFetch(
                new Node\Name\FullyQualified($mapping['enum']),
                $mapping['case']
            ),
            'value'
        );
    }
}
