<?php

declare(strict_types=1);

namespace App\Render;

/**
 * Anti-jargon dictionary — translates admin jargon to plain French.
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

        $text = preg_replace('/\bEPI\b/u', 'Équipement de protection individuelle (EPI)', $text) ?? $text;
        $text = preg_replace('/\bCSRF\b/u', 'Code de sécurité', $text) ?? $text;
        $text = preg_replace('/\bRGPD\b/u', 'Protection des données (RGPD)', $text) ?? $text;
        $text = preg_replace('/\bToken\b/u', 'Lien de validation', $text) ?? $text;
        $text = preg_replace('/\btokens\b/u', 'liens de validation', $text) ?? $text;
        $text = preg_replace('/\btoken\b/u', 'lien de validation', $text) ?? $text;
        $text = preg_replace('/\bSlug\b/u', 'Nom technique', $text) ?? $text;
        $text = preg_replace('/\bslug\b/u', 'nom technique', $text) ?? $text;
        $text = preg_replace('/\bSI\b/u', 'systèmes d\'information', $text) ?? $text;
        $text = preg_replace('/\bLDAP\b/u', 'Annuaire d\'entreprise (LDAP)', $text) ?? $text;
        $text = preg_replace('/\bSMTP\b/u', 'Serveur email (SMTP)', $text) ?? $text;

        $text = str_replace("\x01", 'CircuitDémat', $text);
        $text = str_replace("\x02", 'Équipement de protection individuelle (EPI)', $text);
        $text = str_replace("\x03", 'Métier de la fonction publique', $text);
        $text = str_replace("\x04", 'métier de la fonction publique', $text);
        $text = str_replace("\x05", 'Annuaire d\'entreprise (LDAP)', $text);

        return str_replace("\x06", 'Serveur email (SMTP)', $text);
    }
}
