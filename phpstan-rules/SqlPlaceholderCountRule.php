<?php

declare(strict_types=1);

namespace App\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Compare le nombre de placeholders '?' d'une requête préparée au nombre
 * d'éléments du tableau passé à execute() — un décalage est un échec
 * SILENCIEUX en SQLite/PDO : un paramètre manquant vaut NULL plutôt que de
 * lever une exception, la requête s'exécute quand même et peut ne
 * silencieusement rien matcher (WHERE x = NULL) au lieu de planter.
 *
 * Cas réel qui a motivé cette règle (session 2026-07-30) :
 * TokenService::regenerate(), un mutant Infection (ArrayItemRemoval)
 * transformait execute([$old['step_id']]) en execute([]) sans qu'aucun
 * test ne le détecte — la requête WHERE id = ? s'exécutait avec id = NULL,
 * ne matchait rien, et le code continuait silencieusement sur un
 * résultat vide au lieu de planter.
 *
 * Deux formes détectées, dans le corps d'une seule fonction/méthode :
 *   1. Chaînée : $pdo->prepare($sql)->execute([...])
 *   2. Via variable : $stmt = $pdo->prepare($sql); ...; $stmt->execute([...]);
 *
 * Ne vérifie QUE les cas où le SQL et le tableau sont des littéraux
 * statiquement connus (chaîne sans partie dynamique, tableau sans spread
 * ni variable) — une requête ou des arguments construits dynamiquement
 * sont ignorés silencieusement par la règle : préférer rater un cas que
 * remonter un faux positif.
 *
 * @implements Rule<Node>
 */
class SqlPlaceholderCountRule implements Rule
{
    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof FunctionLike) {
            return [];
        }

        $stmts = $node->getStmts();
        if ($stmts === null || $stmts === []) {
            return [];
        }

        $finder = new NodeFinder();

        /** @var array<string, int> nom de variable => nombre de '?' dans son SQL préparé */
        $preparedCounts = [];
        $assigns = $finder->find($stmts, static fn (Node $n): bool => $n instanceof Assign);
        foreach ($assigns as $assign) {
            if (!$assign instanceof Assign) {
                continue;
            }
            if (!$assign->var instanceof Variable || !is_string($assign->var->name)) {
                continue;
            }
            $count = $this->prepareCallPlaceholderCount($assign->expr);
            if ($count !== null) {
                $preparedCounts[$assign->var->name] = $count;
            }
        }

        $errors = [];
        $executeCalls = $finder->find(
            $stmts,
            static fn (Node $n): bool => $n instanceof MethodCall
                && $n->name instanceof Identifier
                && $n->name->toString() === 'execute'
        );

        foreach ($executeCalls as $call) {
            if (!$call instanceof MethodCall) {
                continue;
            }
            $argCount = $this->literalArrayArgCount($call);
            if ($argCount === null) {
                continue;
            }

            $placeholderCount = $this->prepareCallPlaceholderCount($call->var);
            if ($placeholderCount === null && $call->var instanceof Variable && is_string($call->var->name)) {
                $placeholderCount = $preparedCounts[$call->var->name] ?? null;
            }
            if ($placeholderCount === null) {
                continue;
            }

            if ($placeholderCount !== $argCount) {
                $errors[] = RuleErrorBuilder::message(
                    "execute() reçoit {$argCount} élément(s) mais la requête préparée attend {$placeholderCount} placeholder(s) '?'. " .
                    "Sur PDO/SQLite un décalage ne lève PAS d'exception : le(s) paramètre(s) manquant(s) valent NULL, la requête " .
                    "s'exécute quand même et peut silencieusement ne rien matcher (ex. WHERE id = NULL) au lieu de planter."
                )->identifier('sqlPlaceholder.mismatch')->build();
            }
        }

        return $errors;
    }

    private function prepareCallPlaceholderCount(Node $node): ?int
    {
        if (!$node instanceof MethodCall) {
            return null;
        }
        if (!$node->name instanceof Identifier || $node->name->toString() !== 'prepare') {
            return null;
        }
        if (count($node->args) < 1 || !$node->args[0] instanceof Arg) {
            return null;
        }
        $sql = $this->literalStringValue($node->args[0]->value);
        if ($sql === null) {
            return null;
        }
        return substr_count($sql, '?');
    }

    private function literalStringValue(Node $node): ?string
    {
        if ($node instanceof String_) {
            return $node->value;
        }
        if ($node instanceof InterpolatedString) {
            $text = '';
            foreach ($node->parts as $part) {
                if (!$part instanceof InterpolatedStringPart) {
                    // Partie interpolée (variable/expression) dans le SQL lui-même
                    // — requête pas fiable à compter statiquement, on ignore.
                    return null;
                }
                $text .= $part->value;
            }
            return $text;
        }
        return null;
    }

    private function literalArrayArgCount(MethodCall $call): ?int
    {
        if (count($call->args) < 1) {
            return 0;
        }
        if (!$call->args[0] instanceof Arg) {
            return null;
        }
        $value = $call->args[0]->value;
        if (!$value instanceof Array_) {
            return null;
        }
        foreach ($value->items as $item) {
            if ($item !== null && $item->unpack) {
                return null;
            }
        }
        return count($value->items);
    }
}
