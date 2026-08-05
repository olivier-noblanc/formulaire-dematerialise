<?php

declare(strict_types=1);

namespace App\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Opérationnalise AGENTS.md règle 9 ("Ne jamais avaler une exception sur
 * un chemin critique") : bloque tout catch qui n'a ni throw ni exit()/
 * die() dans son corps, sauf marqueur explicite en commentaire
 * "@silent-ok: <raison>".
 *
 * AGENTS.md distingue 3 usages légitimes du try/catch, dont deux
 * n'ont pas de throw/exit direct dans le catch :
 *   1. Nettoyage puis relance — a un throw, satisfait la règle telle quelle.
 *   2. Panne externe attendue retournée en valeur structurée (SMTP, LDAP,
 *      réseau) — légitime, mais N'A PAS de throw : cette règle ne peut
 *      pas distinguer statiquement ce cas du 3e (avale et continue en
 *      silence, à proscrire), donc elle les traite pareil et demande
 *      une annotation explicite pour les deux. C'est voulu :
 *      l'"Action" d'AGENTS.md ("classer explicitement dans laquelle des
 *      3 catégories il tombe") s'applique à CHAQUE catch qui ne relance
 *      pas, pas seulement à ceux jugés suspects a posteriori — deviner
 *      la catégorie serait justement le genre d'inférence non fiable
 *      que la règle 10 d'AGENTS.md met en garde.
 *
 * Ne détecte que les throw/exit/die directement dans le corps du catch
 * (recherche récursive dans les sous-blocs if/foreach/etc. du catch,
 * mais pas dans une fonction appelée depuis le catch — un appel à un
 * helper qui throw ailleurs n'est pas visible statiquement ici, faux
 * négatif assumé plutôt que risquer un faux positif).
 *
 * @implements Rule<Catch_>
 */
class NoSilentCatchRule implements Rule
{
    private const string MARKER_PATTERN = '/@silent-ok:\s*\S/';

    public function getNodeType(): string
    {
        return Catch_::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node instanceof Catch_) {
            return [];
        }

        if ($this->hasThrowOrExit($node->stmts)) {
            return [];
        }

        if ($this->hasSilentOkMarker($node)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                "Ce catch n'a ni throw ni exit()/die() : il avale l'exception silencieusement. " .
                "AGENTS.md règle 9 (\"Ne jamais avaler une exception sur un chemin critique\") : " .
                "sur un chemin d'écriture, d'audit ou de conformité, l'échec doit remonter ou être " .
                "surfacé de façon visible — jamais error_log() seul comme unique trace. Si c'est " .
                "un cas légitime (nettoyage déjà relancé ailleurs, panne externe attendue retournée " .
                "en valeur structurée, ou vraiment sans conséquence), ajouter un commentaire " .
                "'// @silent-ok: <raison>' dans ce catch plutôt que de laisser planer le doute."
            )->identifier('noSilentCatch.swallowed')->build(),
        ];
    }

    /**
     * @param Node\Stmt[] $stmts
     */
    private function hasThrowOrExit(array $stmts): bool
    {
        if ($stmts === []) {
            return false;
        }
        $finder = new NodeFinder();
        $found = $finder->findFirst(
            $stmts,
            static fn (Node $n): bool => $n instanceof Throw_ || $n instanceof Exit_
        );
        return $found instanceof \PhpParser\Node;
    }

    private function hasSilentOkMarker(Catch_ $catch): bool
    {
        foreach ($catch->getComments() as $comment) {
            if (preg_match(self::MARKER_PATTERN, $comment->getText()) === 1) {
                return true;
            }
        }
        if ($catch->stmts !== []) {
            foreach ($catch->stmts[0]->getComments() as $comment) {
                if (preg_match(self::MARKER_PATTERN, $comment->getText()) === 1) {
                    return true;
                }
            }
        }
        return false;
    }
}
