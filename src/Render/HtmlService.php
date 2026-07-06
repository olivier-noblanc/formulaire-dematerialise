<?php
declare(strict_types=1);

namespace App\Render;

use App\Contract\HtmlInterface;

/**
 * Service de rendu HTML — échappement, icônes, jargon.
 */
final class HtmlService implements HtmlInterface
{
    /**
     * Échappe une valeur pour l'affichage HTML.
     */
    public function escape(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Raccourci pour escape().
     */
    public function h(?string $value): string
    {
        return $this->escape($value);
    }

    /**
     * Génère une icône SVG pour un type MIME.
     */
    public function getFileIcon(string $mimeType): string
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => '🖼️',
            str_starts_with($mimeType, 'application/pdf') => '📄',
            str_starts_with($mimeType, 'application/zip') => '📦',
            str_starts_with($mimeType, 'application/msword'),
            str_starts_with($mimeType, 'application/vnd.openxmlformats-officedocument') => '📝',
            str_starts_with($mimeType, 'text/') => '📃',
            default => '📎',
        };
    }

    /**
     * Formate une taille de fichier en unités lisibles.
     */
    public function formatFileSize(int $bytes): string
    {
        $units = ['o', 'Ko', 'Mo', 'Go'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }

    /**
     * Dictionnaire anti-jargon — traduit le jargon administratif.
     * @var array<string, string>
     */
    private const JARGON_MAPPINGS = [
        'Workflow' => 'Parcours',
        'workflow' => 'parcours',
        'EPI' => 'Équipement de protection individuelle (EPI)',
        'EPIs' => 'Équipements de protection individuelle (EPI)',
        'RGPD' => 'Protection des données (RGPD)',
        'SI' => 'Système d\'information (SI)',
        'DSI' => 'Direction des systèmes d\'information (DSI)',
        'RH' => 'Ressources humaines (RH)',
        'DREETS' => 'Direction régionale (DREETS)',
        'CircuitDémat' => 'CircuitDémat',
        'LDAP' => 'Annuaire (LDAP)',
        'SMTP' => 'Serveur mail (SMTP)',
        'CSRF' => 'Jeton de sécurité (CSRF)',
        'FK' => 'Clé étrangère (FK)',
        'PK' => 'Clé primaire (PK)',
        'UUID' => 'Identifiant unique (UUID)',
        'CRON' => 'Tâche planifiée (cron)',
        'CSV' => 'Tableur (CSV)',
        'JSON' => 'Format de données (JSON)',
        'BDD' => 'Base de données',
        'IIS' => 'Serveur web (IIS)',
        'TLS' => 'Chiffrement (TLS)',
        'SSL' => 'Chiffrement (SSL)',
        'HTTP' => 'Protocole web (HTTP)',
        'HTTPS' => 'Protocole web sécurisé (HTTPS)',
        'URL' => 'Adresse web (URL)',
        'SEO' => 'Référencement (SEO)',
        'SAAS' => 'Service en ligne (SaaS)',
        'API' => 'Interface de programmation (API)',
    ];

    /**
     * Traduit le jargon administratif en termes compréhensibles.
     */
    public function tJargon(string $text): string
    {
        // Protection des placeholders
        $text = str_replace('CircuitDémat', "\x01", $text);
        $text = str_replace('Fonction publique', "\x02", $text);

        foreach (self::JARGON_MAPPINGS as $jargon => $replacement) {
            if ($jargon === 'CircuitDémat') continue;
            $text = preg_replace('/\b' . preg_quote($jargon, '/') . '\b/u', $replacement, $text) ?? $text;
        }

        // Restaurer les placeholders
        $text = str_replace("\x01", 'CircuitDémat', $text);
        $text = str_replace("\x02", 'Fonction publique', $text);

        return $text;
    }
}
