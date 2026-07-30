<?php

declare(strict_types=1);

namespace App\PHPStan;

use PhpParser\Node;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags inline CSS/JS (style="", onXxx="", <script> sans src) dans le HTML
 * généré par PHP — cible : README "Zéro fichier .css : le CSS passe
 * exclusivement par style.php" / "JavaScript : minimal, noncé, sans
 * unsafe-inline" (décision 2026-07-30, cf. TODO.md § CSP — zéro inline).
 *
 * Visite tous les nœuds (getNodeType() = Node::class) plutôt qu'un seul
 * type : le HTML de ce projet est généré à la fois par des chaînes
 * simples/nowdocs (Scalar\String_ — ex. <<<'HTML_WRAP') et des heredocs
 * interpolés (Scalar\InterpolatedString — ex. <<<HTML avec des $variables)
 * qui sont deux types de nœuds distincts dans l'AST php-parser. Seule la
 * partie littérale (InterpolatedStringPart) des heredocs interpolés est
 * scannée — pas les expressions interpolées elles-mêmes, qui ne sont pas
 * du texte.
 *
 * @implements Rule<Node>
 */
class NoInlineHtmlRule implements Rule
{
    /**
     * Fichiers où une exception ponctuelle et documentée existe. Ne pas
     * ajouter une entrée ici sans un vrai motif — c'est la liste que ce
     * rapport lui-même sert à vider, pas à agrandir.
     *
     * @var array<string, string>
     */
    private const array FILE_EXCEPTIONS = [
        'NavigationRenderer.php' => 'Seul <script> inline du projet (menu persona) — noncé via SecurityService::getScriptNonce(), plus de fallback unsafe-inline sur script-src (2026-07-30).',
    ];

    /**
     * Fichiers hors périmètre (tests, migrations, bootstrap...) — même
     * esprit que NoMagicStringRule::ALLOWED_PATTERNS.
     *
     * @var list<string>
     */
    private const array ALLOWED_PATTERNS = [
        '/tests/',
        '\\tests\\',
        '/migrations/',
        '\\migrations\\',
        '/phpstan-rules/',
        '\\phpstan-rules\\',
    ];

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof String_) {
            return $this->checkText($node->value, $node, $scope);
        }

        if ($node instanceof InterpolatedString) {
            $errors = [];
            foreach ($node->parts as $part) {
                if ($part instanceof InterpolatedStringPart) {
                    $errors = [...$errors, ...$this->checkText($part->value, $node, $scope)];
                }
            }
            return $errors;
        }

        return [];
    }

    /**
     * @return list<\PHPStan\Rules\RuleError>
     */
    private function checkText(string $text, Node $node, Scope $scope): array
    {
        if ($text === '') {
            return [];
        }

        $fileDescription = $scope->getFileDescription();
        // @phpstan-ignore function.alreadyNarrowedType
        $file = is_string($fileDescription) ? $fileDescription : $fileDescription->getFile();

        foreach (self::ALLOWED_PATTERNS as $pattern) {
            if (str_contains($file, $pattern)) {
                return [];
            }
        }

        $basename = basename(str_replace('\\', '/', $file));
        $exceptionReason = self::FILE_EXCEPTIONS[$basename] ?? null;

        $errors = [];

        if (preg_match('/<script\b(?![^>]*\bsrc\s*=)/i', $text) === 1) {
            if ($exceptionReason === null) {
                $errors[] = RuleErrorBuilder::message(
                    "<script> inline détecté (sans attribut src) — CSP script-src n'a plus 'unsafe-inline', ce script ne s'exécutera pas sans nonce."
                )->identifier('noInlineHtml.script')->build();
            }
        }

        if (preg_match('/\son[a-z]+\s*=\s*["\']/i', $text) === 1) {
            $errors[] = RuleErrorBuilder::message(
                "Gestionnaire d'événement inline détecté (onXxx=\"...\") — non autorisable par nonce en CSP (seuls <script>/<style> le sont, pas les attributs). Déplacer vers addEventListener() dans un <script nonce> ou externaliser."
            )->identifier('noInlineHtml.eventHandler')->build();
        }

        if (preg_match('/\sstyle\s*=\s*["\']/i', $text) === 1) {
            $errors[] = RuleErrorBuilder::message(
                "Attribut style=\"\" inline détecté — README : \"Zéro fichier .css : le CSS passe exclusivement par style.php\". Non autorisable par nonce en CSP (seuls <script>/<style> le sont). Déplacer vers une classe CSS ou un <style nonce> ciblé pour les valeurs dynamiques."
            )->identifier('noInlineHtml.styleAttr')->build();
        }

        return $errors;
    }
}
