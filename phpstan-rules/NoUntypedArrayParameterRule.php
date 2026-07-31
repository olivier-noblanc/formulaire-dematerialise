<?php

declare(strict_types=1);

namespace App\PHPStan;

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
 *
 * Allows:
 * - array{key: type} shapes (PHPStan-native enforcement)
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
        'mail_wrappers.php',
        'AdminFormsContext.php',
        'SubmissionViewContext.php',
        'MonitoringContext.php',
        'AdminSettingsContext.php',
    ];

    public function getNodeType(): string
    {
        return FunctionLike::class;
    }

    /**
     * @param FunctionLike $node
     * @return list<\PHPStan\Rules\IdentifierRuleError>
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

        $errors = [];
        foreach ($node->params as $param) {
            $error = $this->checkParam($param);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    private function checkParam(Param $param): ?\PHPStan\Rules\IdentifierRuleError
    {
        $type = $param->type;
        if ($type === null) {
            $paramName = $this->getParamName($param);
            return $this->buildError($paramName, 'aucun type');
        }

        $typeName = $this->getTypeName($type);

        if ($typeName === 'array') {
            $paramName = $this->getParamName($param);
            return $this->buildError($paramName, 'array (bare)');
        }

        if ($typeName === '?array') {
            $paramName = $this->getParamName($param);
            return $this->buildError($paramName, '?array (bare)');
        }

        return null;
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
