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
        'ErrorRenderer.php' => 'Balise <style> de fallback (fallback CSS d\'urgence quand style.php est injoignable) — noncée via SecurityService::getScriptNonce(). Sert uniquement pour les pages d\'erreur 500/403 quand le système est défaillant.',
        'InstallRenderer.php' => 'Balise <style> pour la page d\'installation (style.php n\'est pas encore disponible avant l\'installation) — noncée via SecurityService::getScriptNonce().',
        'SubmissionViewRenderer.php' => 'Balise <style> via pageCss() : charge le CSS depuis lib/submission_view_page.css (fichier séparé) — le wrapper <style> est le mécanisme standard pour embarquer le CSS dans le <head>. Noncé via SecurityService::getScriptNonce().',
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

    /** @phpstan-ignore shipmonk.deadMethod */
    public function getNodeType(): string
    {
        return Node::class;
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     * @phpstan-ignore shipmonk.deadMethod
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $line = $node->getLine();

        if ($node instanceof String_) {
            return $this->checkText($node->value, $scope, $line);
        }

        if ($node instanceof InterpolatedString) {
            /** @var list<\PHPStan\Rules\IdentifierRuleError> $errors */
            $errors = [];
            foreach ($node->parts as $part) {
                if ($part instanceof InterpolatedStringPart) {
                    $errors = [...$errors, ...$this->checkText($part->value, $scope, $line)];
                }
            }
            return $errors;
        }

        return [];
    }

    /**
     * @param array{file: string, line: int} $loc
     */
    private function prefix(string $msg, array $loc): string
    {
        return "{$loc['file']}:{$loc['line']} — {$msg}";
    }

    /**
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    private function checkText(string $text, Scope $scope, int $line): array
    {
        if ($text === '') {
            return [];
        }

        $fileDescription = $scope->getFileDescription();
        $file = $fileDescription;

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
                    $this->prefix(
                        "<script> inline détecté (sans attribut src) — CSP script-src n'a plus 'unsafe-inline', ce script ne s'exécutera pas sans nonce.",
                        ['file' => $file, 'line' => $line]
                    )
                )->identifier('noInlineHtml.script')->build();
            }
        }

        if (preg_match('/\son[a-z]+\s*=\s*["\']/i', $text) === 1) {
            $errors[] = RuleErrorBuilder::message(
                $this->prefix(
                    "Gestionnaire d'événement inline détecté (onXxx=\"...\") — non autorisable par nonce en CSP.",
                    ['file' => $file, 'line' => $line]
                )
            )->identifier('noInlineHtml.eventHandler')->build();
        }

        if (preg_match('/\sstyle\s*=\s*["\']/i', $text) === 1) {
            $errors[] = RuleErrorBuilder::message(
                $this->prefix(
                    "Attribut style=\"\" inline — déplacer vers une classe CSS ou <style nonce>.",
                    ['file' => $file, 'line' => $line]
                )
            )->identifier('noInlineHtml.styleAttr')->build();
        }

        // Détection des balises <style> inline — le CSS doit passer par
        // style.php (README : "Zéro fichier .css : le CSS passe exclusivement
        // par style.php"). Les balises <style> sont la seule exception
        // autorisée par nonce en CSP, mais le principe du projet est de
        // centraliser tout le CSS dans style.php. Si une balise <style> est
        // vraiment nécessaire (fallback, urgence, page d'install), elle doit être
        // noncée ET listée dans FILE_EXCEPTIONS avec un motif documenté.
        if (preg_match('/<style\b/i', $text) === 1) {
            if ($exceptionReason === null) {
                $errors[] = RuleErrorBuilder::message(
                    $this->prefix(
                        "Balise <style> inline — déplacer vers lib/*.css ou style.php.",
                        ['file' => $file, 'line' => $line]
                    )
                )->identifier('noInlineHtml.styleTag')->build();
            }
        }

        return $errors;
    }
}
