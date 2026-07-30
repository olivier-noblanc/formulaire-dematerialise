<?php

declare(strict_types=1);

namespace App\Render;

/**
 * Collecteur de règles CSS dynamiques — remplace les style="" inline
 * et les classes hash (s-md5) par un système sémantique et dynamique.
 *
 * Usage dans un renderer ou controller :
 *   $css = App::css();
 *   $css->rule('btn-search', 'font-size:.8rem;padding:.4rem .75rem;');
 *   // puis dans le HTML : class="btn-search"
 *
 * Le CSS est généré dynamiquement par style.php via render() — pas de
 * fichier statique à maintenir, pas de script Python, pas de hash illisible.
 *
 * Pour les styles calculés dynamiquement (largeur de barre, couleur) :
 *   $css->rule('progress-' . $pct, "width:{$pct}%;");
 *
 * @package App\Render
 */
final class DynamicCssService
{
    /** @var array<string, string> nom_classe => déclarations CSS */
    private array $rules = [];

    /**
     * Enregistre une règle CSS. Si la classe existe déjà, les déclarations
     * sont fusionnées (les nouvelles écrasent les anciennes propriétés).
     *
     * @param string $className Nom de classe CSS (sans le point)
     * @param string $declarations Déclarations CSS (ex: "font-weight:bold;color:#003189;")
     */
    public function rule(string $className, string $declarations): void
    {
        if ($className === '' || $className === '0') {
            return;
        }
        if (isset($this->rules[$className])) {
            // Fusion : les nouvelles déclarations écrasent les anciennes
            $this->rules[$className] .= $declarations;
        } else {
            $this->rules[$className] = $declarations;
        }
    }

    /**
     * Génère le CSS pour toutes les règles enregistrées.
     * Appelé par style.php à la fin du <style>.
     */
    public function render(): string
    {
        if ($this->rules === []) {
            return '';
        }
        $css = "\n/* DynamicCssService — règles générées dynamiquement */\n";
        foreach ($this->rules as $className => $declarations) {
            // Sécurité : pas de } ou { dans les déclarations (injection CSS)
            $safe = str_replace(['{', '}'], '', $declarations);
            $css .= ".{$className} { {$safe} }\n";
        }
        return $css;
    }

    /**
     * Charge les règles existantes depuis le fichier style_generated-inline.css.
     * Permet une transition en douceur : les s-hash existants continuent de
     * fonctionner, et les nouvelles règles utilisent l'API sémantique.
     *
     * @param string $cssFile Chemin vers lib/style_generated-inline.css
     */
    public function loadFromFile(string $cssFile): void
    {
        if (!file_exists($cssFile)) {
            return;
        }
        $content = file_get_contents($cssFile);
        if ($content === false) {
            return;
        }
        // Parser les règles .s-hash { declarations }
        preg_match_all('/\.([a-zA-Z0-9_-]+)\s*\{\s*([^}]+)\s*\}/', $content, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $className = $m[1];
            $declarations = trim($m[2]);
            if (!isset($this->rules[$className])) {
                $this->rules[$className] = $declarations;
            }
        }
    }
}
