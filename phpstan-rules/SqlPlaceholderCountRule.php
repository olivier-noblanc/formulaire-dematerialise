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

        $visitor = new SqlPlaceholderVisitor($this);
        $traverser = new \PhpParser\NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        return $visitor->getErrors();
    }

    /**
     * @internal utilisé par SqlPlaceholderVisitor
     */
    public function prepareCallPlaceholderCount(Node $node): ?int
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

    /**
     * @internal utilisé par SqlPlaceholderVisitor
     */
    public function literalArrayArgCount(MethodCall $call): ?int
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

/**
 * Parcours séquentiel du corps d'une fonction/méthode, dans l'ordre réel
 * des instructions — contrairement à une recherche NodeFinder globale
 * (non ordonnée), ceci gère correctement une variable réaffectée à
 * plusieurs prepare() différents dans la même fonction : chaque
 * execute() est comparé au prepare() qui le précède réellement dans le
 * flux, pas au dernier prepare() trouvé n'importe où dans la fonction.
 *
 * @internal
 */
final class SqlPlaceholderVisitor extends \PhpParser\NodeVisitorAbstract
{
    /** @var array<string, int> nom de variable => nombre de '?' actuellement connu à ce point du parcours */
    private array $preparedCounts = [];

    /** @var list<\PHPStan\Rules\IdentifierRuleError> */
    private array $errors = [];

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function __construct(private readonly SqlPlaceholderCountRule $rule)
    {
    }

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Assign
            && $node->var instanceof Variable
            && is_string($node->var->name)
        ) {
            $count = $this->rule->prepareCallPlaceholderCount($node->expr);
            if ($count !== null) {
                $this->preparedCounts[$node->var->name] = $count;
            }
            return null;
        }

        if ($node instanceof MethodCall
            && $node->name instanceof Identifier
            && $node->name->toString() === 'execute'
        ) {
            $argCount = $this->rule->literalArrayArgCount($node);
            if ($argCount === null) {
                return null;
            }

            $placeholderCount = $this->rule->prepareCallPlaceholderCount($node->var);
            if ($placeholderCount === null && $node->var instanceof Variable && is_string($node->var->name)) {
                $placeholderCount = $this->preparedCounts[$node->var->name] ?? null;
            }
            if ($placeholderCount === null) {
                return null;
            }

            if ($placeholderCount !== $argCount) {
                $this->errors[] = RuleErrorBuilder::message(
                    "execute() reçoit {$argCount} élément(s) mais la requête préparée attend {$placeholderCount} placeholder(s) '?'. " .
                    "Sur PDO/SQLite un décalage ne lève PAS d'exception : le(s) paramètre(s) manquant(s) valent NULL, la requête " .
                    "s'exécute quand même et peut silencieusement ne rien matcher (ex. WHERE id = NULL) au lieu de planter."
                )->identifier('sqlPlaceholder.mismatch')->build();
            }
        }

        return null;
    }
}
