<?php

declare(strict_types=1);

namespace App\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags $this->redirect('index.php...') calls where persona_token is not propagated.
 *
 * Every redirect to an internal URL MUST use App::html()->buildUrl() so that
 * ?persona_token is preserved. A raw string argument means persona mode is dropped.
 *
 * @implements Rule<MethodCall>
 */
class RequireBuildUrlForRedirectRule implements Rule
{
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!($node->name instanceof \PhpParser\Node\Identifier)) {
            return [];
        }

        if ($node->name->name !== 'redirect') {
            return [];
        }

        // Only match $this->redirect(...)
        if (!($node->var instanceof \PhpParser\Node\Expr\Variable) || $node->var->name !== 'this') {
            return [];
        }

        $args = $node->getArgs();
        if ($args === []) {
            return [];
        }

        $firstArg = $args[0]->value;

        // If the argument is a string literal containing index.php or assets.php,
        // it's missing buildUrl() — persona_token will be dropped.
        if ($firstArg instanceof \PhpParser\Node\Scalar\String_) {
            $value = $firstArg->value;
            if (str_contains($value, 'index.php') || str_contains($value, 'assets.php')) {
                return [
                    RuleErrorBuilder::message(
                        "redirect() with raw URL '{$value}' → wrap in App::html()->buildUrl() to preserve persona_token."
                    )
                        ->identifier('requireBuildUrlForRedirect')
                        ->build(),
                ];
            }
        }

        return [];
    }
}
