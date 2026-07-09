<?php
declare(strict_types=1);

/**
 * Anti-jargon dictionary (S4-UI / Action 1).
 *
 * t_jargon() traduit le jargon administratif en termes compréhensibles
 * pour les agents 40-60 ans. Utilise la table t_jargon si elle existe,
 * sinon un dictionnaire statique de fallback.
 *
 * @package lib
 */

// ── S4-UI / Action 1 : DICTIONNAIRE ANTI-JARGON — t_jargon() ─────
// VÉTO 1 M. Robert (SESSION-ROBERT) : mots techniques non traduits
// ("Dématérialisation", "Circuit de validation", "Quotité", "EPI", "Workflow",
// "Token", "Slug", "CSRF") bloquent la compréhension pour les agents peu à
// l'aise avec l'informatique. Cette fonction remplace ces termes par du
// français courant à l'affichage, sans toucher au stockage en base.
//
// Principes :
//  - Idempotente : appliquer t_jargon() 2× donne le même résultat qu'1×.
//    (protection via placeholders des chaînes déjà traduites)
//  - Préserve le nom de l'application « CircuitDémat ».
//  - Sensible à la casse : "Workflow" → "Parcours", "workflow" → "parcours".
//  - Acronymes courts (EPI, CSRF) protégés par frontières de mot (\b) pour
//    éviter les faux positifs type "EPIsode".
//  - Aucune dépendance externe, code procédural pur (KISS).

/**
 * Table de mappings interne (substitutions simples).
 *
 * Tableau associatif [jargon => traduction]. L'ordre est important :
 * on traite d'abord les expressions composées ("Circuit de validation")
 * avant les mots courts, pour éviter les doubles remplacements.
 * Le pluriel est placé avant le singulier (Soumissions avant Soumission)
 * pour ne pas remplacer le "Soumission" du pluriel et laisser "s" orphelin.
 *
 * S5-A — nouveaux mappings (rapport M. Robert 21 screenshots) :
 *  - "Fonction publique" / "fonction publique" → placeholders \x03/\x04
 *    (et non directement la traduction) car le résultat "Métier de la
 *    fonction publique" contient "fonction publique" qui serait
 *    re-substitué par la règle minuscule. Les placeholders sont
 *    restaurés à l'étape 4.
 *  - "Soumissions/Soumission" → "Demandes/Demande",
 *  - "Back-office/Back office" → "Espace administration",
 *  - "Démarches" → "Demandes",
 *  - "Task Scheduler" → "Planificateur de tâches Windows",
 *  - "Dry-Run/dry-run" → "Mode test/mode test (sans envoi réel)".
 *
 * @var array<string, string>
 */
const JARGON_MAPPINGS = [
    // Dématérialisation → Demande en ligne
    'Dématérialisation'      => 'Demande en ligne',
    'dématérialisation'      => 'demande en ligne',
    // Circuit de validation → Étapes de validation
    'Circuit de validation'  => 'Étapes de validation',
    'circuit de validation'  => 'étapes de validation',
    // Workflow → Parcours
    'Workflow'               => 'Parcours',
    'workflow'               => 'parcours',
    // Quotité → Temps de travail (en %)
    'Quotité'                => 'Temps de travail (en %)',
    'quotité'                => 'temps de travail (en %)',
    // Corps / Grade → Catégorie professionnelle
    'Corps / Grade'          => 'Catégorie professionnelle',
    'Corps/Grade'            => 'Catégorie professionnelle',
    'corps / grade'          => 'catégorie professionnelle',
    'corps/grade'            => 'catégorie professionnelle',
    // Accès SI → Accès aux outils informatiques
    'Accès SI'               => 'Accès aux outils informatiques',
    'accès SI'               => 'accès aux outils informatiques',
    // Onboarding → Accueil d'un nouvel agent
    'Onboarding'             => 'Accueil d\'un nouvel agent',
    'onboarding'             => 'accueil d\'un nouvel agent',
    // Outboarding → Départ d'un agent
    'Outboarding'            => 'Départ d\'un agent',
    'outboarding'            => 'départ d\'un agent',
    // S5-A — Fonction publique → placeholders \x03/\x04 (restaurés à l'étape 4)
    'Fonction publique'      => "\x03",
    'fonction publique'      => "\x04",
    // Soumissions → Demandes (pluriel avant singulier)
    'Soumissions'            => 'Demandes',
    'soumissions'            => 'demandes',
    'Soumission'             => 'Demande',
    'soumission'             => 'demande',
    // Back-office → Espace administration
    'Back-office'            => 'Espace administration',
    'Back office'            => 'Espace administration',
    'back-office'            => 'espace administration',
    'back office'            => 'espace administration',
    // Démarches → Demandes
    'Démarches'              => 'Demandes',
    'démarches'              => 'demandes',
    // Task Scheduler → Planificateur de tâches Windows
    'Task Scheduler'         => 'Planificateur de tâches Windows',
    // Dry-Run → Mode test (sans envoi réel)
    'Dry-Run'                => 'Mode test (sans envoi réel)',
    'dry-run'                => 'mode test (sans envoi réel)',
];

/**
 * Traduit le jargon technique en français courant pour l'affichage.
 * Vise les agents peu à l'aise avec l'informatique (persona M. Robert, 70 ans).
 *
 * @param string $text Texte source (label, description, titre, etc.)
 * @return string Texte avec jargon traduit
 */
function t_jargon(string $text): string {
    // 1) Protection du nom de l'application et des traductions déjà présentes
    //    pour garantir l'idempotence (appels multiples / chaînes imbriquées).
    //    On utilise des caractères de contrôle (\x01-\x06) impossibles à
    //    obtenir par substitution et sans risque de collision avec le jargon.
    //    S5-A — placeholders \x03-\x06 ajoutés pour les nouveaux mappings
    //    dont le résultat contient la source (ex. "métier de la fonction
    //    publique" contient "fonction publique", "Annuaire d'entreprise (LDAP)"
    //    contient "LDAP", "Serveur email (SMTP)" contient "SMTP").
    $text = str_replace('CircuitDémat', "\x01", $text);
    $text = str_replace('Équipement de protection individuelle (EPI)', "\x02", $text);
    $text = str_replace('Métier de la fonction publique', "\x03", $text);
    $text = str_replace('métier de la fonction publique', "\x04", $text);
    $text = str_replace('Annuaire d\'entreprise (LDAP)', "\x05", $text);
    $text = str_replace('Serveur email (SMTP)', "\x06", $text);

    // 2) Substitutions simples (phrases et mots longs — pas d'ambiguïté de sous-chaîne).
    //    Ordre : on traite d'abord les expressions composées ("Circuit de validation")
    //    avant les mots courts, pour éviter les doubles remplacements.
    //    La table JARGON_MAPPINGS (constante de ce module) contient l'ensemble
    //    des correspondances jargon → traduction. Les placeholders \x03/\x04
    //    (pour "Fonction publique") sont restaurés à l'étape 4.
    $text = str_replace(
        array_keys(JARGON_MAPPINGS),
        array_values(JARGON_MAPPINGS),
        $text
    );

    // 3) Acronymes courts — frontières de mot (\b) pour éviter les faux positifs
    //    (ex. "EPI" dans "EPIsode", "CSRF" dans "CSRFFFF", "SI" dans "EPIsode"
    //    ou "si" conditionnel). \b en PCRE fonctionne sur [A-Za-z0-9_] : OK pour
    //    ces acronymes ASCII. Unicode (u) pour ne pas casser l'encodage UTF-8
    //    des chaînes environnantes.
    //    S5-A — nouveaux acronymes : "SI" (majuscule uniquement — ne pas toucher
    //    "si" conditionnel), "LDAP", "SMTP" (pages admin).
    $text = preg_replace('/\bEPI\b/u',  'Équipement de protection individuelle (EPI)', $text) ?? $text;
    $text = preg_replace('/\bCSRF\b/u', 'Code de sécurité', $text) ?? $text;
    $text = preg_replace('/\bRGPD\b/u', 'Protection des données (RGPD)', $text) ?? $text;
    $text = preg_replace('/\bToken\b/u', 'Lien de validation', $text) ?? $text;
    $text = preg_replace('/\btokens\b/u', 'liens de validation', $text) ?? $text;
    $text = preg_replace('/\btoken\b/u',  'lien de validation', $text) ?? $text;
    $text = preg_replace('/\bSlug\b/u',  'Nom technique', $text) ?? $text;
    $text = preg_replace('/\bslug\b/u',  'nom technique', $text) ?? $text;
    // S5-A — "SI" majuscule uniquement : \bSI\b ne matche pas "si", "Si", "SImplifie",
    // "EPIsode", "RSI" (pas de frontière de mot entre R et S). Le résultat
    // "systèmes d'information" ne contient pas "SI" → idempotent.
    $text = preg_replace('/\bSI\b/u',   'systèmes d\'information', $text) ?? $text;
    // S5-A — "LDAP" réservé aux pages admin (annuaire d'entreprise).
    $text = preg_replace('/\bLDAP\b/u', 'Annuaire d\'entreprise (LDAP)', $text) ?? $text;
    // S5-A — "SMTP" réservé aux pages admin (serveur email).
    $text = preg_replace('/\bSMTP\b/u', 'Serveur email (SMTP)', $text) ?? $text;

    // 4) Restauration des placeholders protégés au point 1.
    $text = str_replace("\x01", 'CircuitDémat', $text);
    $text = str_replace("\x02", 'Équipement de protection individuelle (EPI)', $text);
    // S5-A — restauration des nouveaux placeholders
    $text = str_replace("\x03", 'Métier de la fonction publique', $text);
    $text = str_replace("\x04", 'métier de la fonction publique', $text);
    $text = str_replace("\x05", 'Annuaire d\'entreprise (LDAP)', $text);
    $text = str_replace("\x06", 'Serveur email (SMTP)', $text);

    return $text;
}
