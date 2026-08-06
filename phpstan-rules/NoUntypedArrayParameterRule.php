<?php

declare(strict_types=1);

namespace App\PHPStan;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags function/method parameters typed as bare array or array<string, mixed>.
 *
 * Enforces either:
 * - A precise array shape: array{id: int, name: string}
 * - A DTO/value object class type
 * - A @param annotation specifying a generic array type (array<K,V>, list<T>, array{...})
 *
 * Allows:
 * - array{key: type} shapes (PHPStan-native enforcement)
 * - Parameters with @param annotation specifying a typed array
 * - Parameters in excluded legacy paths
 *
 * @implements Rule<FunctionLike>
 */
class NoUntypedArrayParameterRule implements Rule
{
    private const array ALLOWED_PATHS = [
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
        '/lib/',
        '\\lib\\',
        'lib_wrappers.php',
        'alert_check.php',
        'lib_wrappers_admin.php',
        'lib_wrappers_cache.php',
        'lib_wrappers_conditions.php',
        'lib_wrappers_testmode.php',
        'lib_wrappers_validation.php',
        'mail_wrappers.php',
        'AdminFormsContext.php',
        'SubmissionViewContext.php',
        'MonitoringContext.php',
        'AdminSettingsContext.php',
        'AdminSettingsRenderer.php',
    ];

    /** @phpstan-ignore shipmonk.deadMethod */
    public function getNodeType(): string
    {
        return FunctionLike::class;
    }

    /**
     * @param FunctionLike $node
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     * @phpstan-ignore shipmonk.deadMethod
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof Function_ && !$node instanceof ClassMethod) {
            return [];
        }

        $filePath = $scope->getFile();
        foreach (self::ALLOWED_PATHS as $pattern) {
            if (str_contains($filePath, $pattern)) {
                return [];
            }
        }

        $node->getDocComment();

        $errors = [];
        foreach ($node->params as $param) {
            $error = $this->checkParam($param, $node->getDocComment());
            if ($error instanceof \PHPStan\Rules\IdentifierRuleError) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    private function checkParam(Param $param, ?Doc $docComment): ?\PHPStan\Rules\IdentifierRuleError
    {
        $type = $param->type;
        if (!$type instanceof \PhpParser\Node) {
            $paramName = $this->getParamName($param);
            return $this->buildError($paramName, 'aucun type');
        }

        $typeName = $this->getTypeName($type);

        if ($typeName === 'array' || $typeName === '?array') {
            $paramName = $this->getParamName($param);

            // Allow bare array if PHPDoc @param specifies a typed array (shape, list<>, array<K,V>)
            if ($docComment instanceof \PhpParser\Comment\Doc && $this->hasTypedArrayDoc($docComment, $paramName)) {
                return null;
            }

            return $this->buildError($paramName, $typeName === '?array' ? '?array (bare)' : 'array (bare)');
        }

        return null;
    }

    /**
     * Checks if a @param annotation for the given parameter specifies a typed array
     * (array shape, list<T>, array<K,V> with K specified).
     */
    private function hasTypedArrayDoc(Doc $docComment, string $paramName): bool
    {
        $text = $docComment->getText();
        // Match @param array{...} $paramName
        if (preg_match('/@param\s+array\s*\{[^}]+\}\s+\$' . preg_quote($paramName, '/') . '\b/', $text) === 1) {
            return true;
        }
        // Match @param list<...> $paramName
        if (preg_match('/@param\s+list\s*<\s*[^>]+\s*>\s+\$' . preg_quote($paramName, '/') . '\b/', $text) === 1) {
            return true;
        }
        // Match @param array<int, ...> or @param array<string, ...> but NOT bare @param array
        return (bool) preg_match('/@param\s+array\s*<\s*(?:int|string)\s*,\s*[^>]+\s*>\s+\$' . preg_quote($paramName, '/') . '\b/', $text);
    }

    private function getParamName(Param $param): string
    {
        if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
            return $param->var->name;
        }
        return '?';
    }

    private function getTypeName(Node $type): string
    {
        if ($type instanceof Node\Identifier) {
            return $type->name;
        }
        if ($type instanceof Node\NullableType) {
            $inner = $this->getTypeName($type->type);
            return '?' . $inner;
        }
        return 'complex';
    }

    private function buildError(string $paramName, string $currentType): \PHPStan\Rules\IdentifierRuleError
    {
        return RuleErrorBuilder::message(
            "Paramètre '\${$paramName}' typé {$currentType} — trop vague. " .
            "Utiliser un array shape (array{id: int, name: string}) ou un DTO/ValueObject."
        )->identifier('noUntypedArray')->build();
    }
}
