<?php

declare(strict_types=1);

namespace App\Render;

/**
 * Anti-jargon dictionary — translates admin jargon to plain French.
 *
 * SINGLE SOURCE OF TRUTH for jargon translation. Both HtmlService::tJargon()
 * and the global t_jargon() wrapper delegate to JargonService::translate().
 *
 * Previously HtmlService had its own JARGON_MAPPINGS (28 entries) and
 * JargonService had its own (30 entries + 11 preg_replace), causing
 * divergent translations for the same input on different pages (UX bug B4
 * from audit 2026-07-26). This file is now the unique reference.
 */
final class JargonService
{
    /** @var array<string, string> */
    public const array JARGON_MAPPINGS = [
        'Dématérialisation'      => 'Demande en ligne',
        'dématérialisation'      => 'demande en ligne',
        'Circuit de validation'  => 'Étapes de validation',
        'circuit de validation'  => 'étapes de validation',
        'Workflow'               => 'Parcours',
        'workflow'               => 'parcours',
        'Quotité'                => 'Temps de travail (en %)',
        'quotité'                => 'temps de travail (en %)',
        'Corps / Grade'          => 'Catégorie professionnelle',
        'Corps/Grade'            => 'Catégorie professionnelle',
        'corps / grade'          => 'catégorie professionnelle',
        'corps/grade'            => 'catégorie professionnelle',
        'Accès SI'               => 'Accès aux outils informatiques',
        'accès SI'               => 'accès aux outils informatiques',
        'Onboarding'             => 'Accueil d\'un nouvel agent',
        'onboarding'             => 'accueil d\'un nouvel agent',
        'Outboarding'            => 'Départ d\'un agent',
        'outboarding'            => 'départ d\'un agent',
        'Fonction publique'      => "\x03",
        'fonction publique'      => "\x04",
        'Soumissions'            => 'Demandes',
        'soumissions'            => 'demandes',
        'Soumission'             => 'Demande',
        'soumission'             => 'demande',
        'Back-office'            => 'Espace administration',
        'Back office'            => 'Espace administration',
        'back-office'            => 'espace administration',
        'back office'            => 'espace administration',
        'Démarches'              => 'Demandes',
        'démarches'              => 'demandes',
        'Task Scheduler'         => 'Planificateur de tâches Windows',
        'Dry-Run'                => 'Mode test (sans envoi réel)',
        'dry-run'                => 'mode test (sans envoi réel)',
    ];

    /**
     * Translate admin jargon to plain French for display.
     */
    public static function translate(string $text): string
    {
        $text = str_replace('CircuitDémat', "\x01", $text);
        $text = str_replace('Équipement de protection individuelle (EPI)', "\x02", $text);
        $text = str_replace('Métier de la fonction publique', "\x03", $text);
        $text = str_replace('métier de la fonction publique', "\x04", $text);
        $text = str_replace('Annuaire d\'entreprise (LDAP)', "\x05", $text);
        $text = str_replace('Serveur email (SMTP)', "\x06", $text);

        $text = str_replace(
            array_keys(self::JARGON_MAPPINGS),
            array_values(self::JARGON_MAPPINGS),
            $text
        );

        // Compound phrases (kept as plain str_replace above for speed)
        // Acronyms handled via preg_replace with word boundaries so we don't
        // replace inside compound words (e.g. "Tokenized" should not match "Token").

        // ── Acronyms shared with former HtmlService dictionary ──────────
        $text = preg_replace('/\bEPI\b/u', 'Équipement de protection individuelle (EPI)', $text) ?? $text;
        $text = preg_replace('/\bCSRF\b/u', 'Jeton de sécurité (CSRF)', $text) ?? $text;
        $text = preg_replace('/\bRGPD\b/u', 'Protection des données (RGPD)', $text) ?? $text;
        $text = preg_replace('/\bToken\b/u', 'Lien de validation', $text) ?? $text;
        $text = preg_replace('/\btokens\b/u', 'liens de validation', $text) ?? $text;
        $text = preg_replace('/\btoken\b/u', 'lien de validation', $text) ?? $text;
        $text = preg_replace('/\bSlug\b/u', 'Nom technique', $text) ?? $text;
        $text = preg_replace('/\bslug\b/u', 'nom technique', $text) ?? $text;
        $text = preg_replace('/\bSI\b/u', 'Système d\'information (SI)', $text) ?? $text;
        $text = preg_replace('/\bLDAP\b/u', 'Annuaire d\'entreprise (LDAP)', $text) ?? $text;
        $text = preg_replace('/\bSMTP\b/u', 'Serveur email (SMTP)', $text) ?? $text;
        $text = preg_replace('/\bDSI\b/u', 'Direction des systèmes d\'information (DSI)', $text) ?? $text;
        $text = preg_replace('/\bRH\b/u', 'Ressources humaines (RH)', $text) ?? $text;
        $text = preg_replace('/\bDREETS\b/u', 'Direction régionale (DREETS)', $text) ?? $text;
        $text = preg_replace('/\bFK\b/u', 'Clé étrangère (FK)', $text) ?? $text;
        $text = preg_replace('/\bPK\b/u', 'Clé primaire (PK)', $text) ?? $text;
        $text = preg_replace('/\bUUID\b/u', 'Identifiant unique (UUID)', $text) ?? $text;
        $text = preg_replace('/\bCRON\b/u', 'Tâche planifiée (cron)', $text) ?? $text;
        $text = preg_replace('/\bCSV\b/u', 'Tableur (CSV)', $text) ?? $text;
        $text = preg_replace('/\bJSON\b/u', 'Format de données (JSON)', $text) ?? $text;
        $text = preg_replace('/\bBDD\b/u', 'Base de données', $text) ?? $text;
        $text = preg_replace('/\bIIS\b/u', 'Serveur web (IIS)', $text) ?? $text;
        $text = preg_replace('/\bTLS\b/u', 'Chiffrement (TLS)', $text) ?? $text;
        $text = preg_replace('/\bSSL\b/u', 'Chiffrement (SSL)', $text) ?? $text;
        $text = preg_replace('/\bHTTP\b/u', 'Protocole web (HTTP)', $text) ?? $text;
        $text = preg_replace('/\bHTTPS\b/u', 'Protocole web sécurisé (HTTPS)', $text) ?? $text;
        $text = preg_replace('/\bURL\b/u', 'Adresse web (URL)', $text) ?? $text;
        $text = preg_replace('/\bSEO\b/u', 'Référencement (SEO)', $text) ?? $text;
        $text = preg_replace('/\bSAAS\b/u', 'Service en ligne (SaaS)', $text) ?? $text;
        $text = preg_replace('/\bAPI\b/u', 'Interface de programmation (API)', $text) ?? $text;

        $text = str_replace("\x01", 'CircuitDémat', $text);
        $text = str_replace("\x02", 'Équipement de protection individuelle (EPI)', $text);
        $text = str_replace("\x03", 'Métier de la fonction publique', $text);
        $text = str_replace("\x04", 'métier de la fonction publique', $text);
        $text = str_replace("\x05", 'Annuaire d\'entreprise (LDAP)', $text);

        return str_replace("\x06", 'Serveur email (SMTP)', $text);
    }
}
