<?php
declare(strict_types=1);

/**
 * Database access layer.
 *
 * Gestion de la connexion PDO (singleton), migrations automatiques,
 * et utilitaires de génération de slugs / field_names.
 *
 * @package lib
 */

require_once __DIR__ . '/../classes/DatabaseMigrations.php';

// ── PDO ──────────────────────────────────────────────────────
// L'instance PDO est stockée dans $GLOBALS['_pdo'] (et $GLOBALS['_pdo_test'] en
// mode test) plutôt qu'en static locale, afin de pouvoir être libérée par
// release_pdo() — nécessaire pour le restore de backup.php (T-19/O-05).
// Comportement singleton préservé : get_pdo() retourne la même instance tant
// que release_pdo() n'a pas été appelée.
function get_pdo(): PDO {
    return \App\Core\App::db()->getPdo();
}

/**
 * Libère la connexion PDO globale (production et test).
 * À appeler avant de remplacer le fichier SQLite (ex: backup.php restore).
 *
 * - Rollback toute transaction en cours (sécurité contre écriture partielle)
 * - Met la référence PDO à null pour fermer le handle SQLite et libérer le fichier
 *
 * Le prochain appel à get_pdo() rouvrira une nouvelle connexion.
 * T-19/O-05 : implémentation réelle (l'ancienne version dans backup.php était vide).
 */
function release_pdo(): void {
    \App\Core\App::db()->release();
}

/**
 * Recupere un formulaire par son UUID
 * @return array<string, mixed>|null
 */
function get_form_by_uuid(string $uuid): ?array {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT * FROM forms WHERE id = ?");
    $stmt->execute([$uuid]);
    $form = $stmt->fetch(PDO::FETCH_ASSOC);
    return $form ?: null;
}

/**
 * Genere automatiquement un field_name a partir d'un libelle
 * Ex: "Date de prise de poste" → "date_de_prise_de_poste"
 * Ex: "Type d'arrivée" → "type_arrivee"
 */
function generate_field_name(string $label): string {
    // Minuscules (requiert ext-mbstring)
    $name = mb_strtolower($label, 'UTF-8');
    // Supprimer les accents
    if (function_exists('transliterator_transliterate')) {
        $transliterated = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name);
        if ($transliterated !== false) {
            $name = $transliterated;
        }
    }
    // Fallback manuel si intl pas dispo ou a échoué
    $name = str_replace(
        ['à','â','ä','é','è','ê','ë','ï','î','ô','ö','ù','û','ü','ç','œ','æ','ÿ'],
        ['a','a','a','e','e','e','e','i','i','o','o','u','u','u','c','oe','ae','y'],
        $name
    );
    // Remplacer tout ce qui n'est pas alphanumérique par un underscore
    $name = preg_replace('/[^a-z0-9]+/', '_', $name) ?? $name;
    // Nettoyer les underscores en double et en bordure
    $name = trim($name, '_');
    $name = preg_replace('/_+/', '_', $name) ?? $name;
    return $name ?: 'champ';
}

/**
 * Génère automatiquement un slug unique à partir d'un libellé.
 * Ex: "Accueil agent" → "accueil_agent"
 * Ex: "Demande de congé" → "demande_de_conge"
 * Si le slug existe déjà, ajoute un suffixe numérique : "onboarding_agent_2"
 *
 * Le slug n'est JAMAIS visible par l'utilisateur final — c'est un identifiant
 * technique interne utilisé uniquement dans les URLs (form.php?f=onboarding).
 */
function generate_slug(string $label, ?string $exclude_form_id = null): string {
    $base = generate_field_name($label);
    if (empty($base)) $base = 'formulaire';

    $pdo = get_pdo();
    $slug = $base;
    $suffix = 2;

    while (true) {
        $sql = "SELECT COUNT(*) FROM forms WHERE slug = ?";
        $params = [$slug];
        if ($exclude_form_id !== null) {
            $sql .= " AND id != ?";
            $params[] = $exclude_form_id;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ((int)$stmt->fetchColumn() === 0) {
            return $slug;
        }
        $slug = $base . '_' . $suffix;
        $suffix++;
    }
}

/**
 * Convertit une liste d'options (une par ligne) en JSON array
 * Ex: "Option A\nOption B" → '["Option A","Option B"]'
 * Si c'est déjà du JSON valide, le retourne tel quel
 */
function parse_options_input(string $input): ?string {
    $input = trim($input);
    if (empty($input)) return null;

    // Vérifier si c'est déjà du JSON valide
    $decoded = json_decode($input, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return $input;
    }

    // Traiter comme une liste une option par ligne
    $lines = array_filter(array_map('trim', explode("\n", $input)));
    if (empty($lines)) return null;

    $result = json_encode($lines, JSON_UNESCAPED_UNICODE);
    return $result === false ? null : $result;
}
