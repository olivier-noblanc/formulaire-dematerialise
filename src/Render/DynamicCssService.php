<?php

declare(strict_types=1);

namespace App\Render;

/**
 * Collecteur de règles CSS dynamiques — pour les styles calculés à l'exécution
 * (largeurs de barres de progression, couleurs dynamiques, etc.).
 *
 * Les styles statiques sont dans lib/style_utility.css (classes sémantiques).
 * Ce service ne sert que pour le CSS qui NE PEUT PAS être statique — par
 * exemple "width:{$pct}%" où $pct dépend de données runtime.
 *
 * Usage :
 *   App::css()->rule('progress-45', 'width:45%;');
 *   // puis dans le HTML : class="progress-45"
 *
 * Le CSS est injecté par style.php via render() à la fin du <style>.
 *
 * @package App\Render
 */
final class DynamicCssService
{
    /** @var array<string, string> nom_classe => déclarations CSS */
    private array $rules = [];

    /**
     * Enregistre une règle CSS dynamique.
     *
     * @param string $className Nom de classe CSS (sans le point)
     * @param string $declarations Déclarations CSS (ex: "width:45%;")
     */
    public function rule(string $className, string $declarations): void
    {
        if ($className === '' || $className === '0') {
            return;
        }
        if (isset($this->rules[$className])) {
            $this->rules[$className] .= $declarations;
        } else {
            $this->rules[$className] = $declarations;
        }
    }

    /**
     * Génère le CSS pour toutes les règles dynamiques enregistrées.
     * Appelé par style.php à la fin du <style>.
     */
    public function render(): string
    {
        if ($this->rules === []) {
            return '';
        }
        $css = "\n/* DynamicCssService — règles dynamiques runtime */\n";
        foreach ($this->rules as $className => $declarations) {
            $safe = str_replace(['{', '}'], '', $declarations);
            $css .= ".{$className} { {$safe} }\n";
        }
        return $css;
    }
}
