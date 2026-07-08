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

    /**
     * Affiche un email avec masquage du domaine pour l'utilisateur courant.
     */
    public function displayUser(string $email, ?string $current_user = null, bool $force_email = false): string
    {
        if ($email === '') return '';
        if ($force_email) return $this->h($email);

        if ($current_user === null) {
            $current_user = function_exists('get_auth_user') ? get_auth_user() : '';
        }

        // Cas 1 : email = user courant → "Vous"
        if ($current_user !== '' && strcasecmp($email, $current_user) === 0) {
            return '<strong>Vous</strong>';
        }

        // Cas 2 : masquer le domaine si = domaine du user courant
        if ($current_user !== '' && str_contains($current_user, '@')) {
            $user_domain = strtolower(substr($current_user, strrpos($current_user, '@')));
            $email_lower = strtolower($email);
            if (str_ends_with($email_lower, $user_domain)) {
                $local_part = substr($email, 0, strlen($email) - strlen($user_domain));
                return $this->h($local_part . '@');
            }
        }

        // Cas 3 : email complet
        return $this->h($email);
    }

    /**
     * Génère un graphique donut pour la répartition des statuts.
     */
    public function renderDonutChart(int $total, int $valide, int $en_cours, int $refuse): string
    {
        if ($total <= 0) {
            return '<div class="chart-row">'
                 . '<div class="donut-chart" style="background:#e0e0e0;">'
                 . '<div class="donut-center">'
                 . '<span class="donut-value">0</span>'
                 . '<span class="donut-label">Total</span>'
                 . '</div></div>'
                 . '<div class="chart-legend">'
                 . '<div class="legend-item"><span class="legend-dot" style="background:#1a6b3c;"></span><strong>Validées</strong> : 0 (0%)</div>'
                 . '<div class="legend-item"><span class="legend-dot" style="background:#b45309;"></span><strong>En cours</strong> : 0 (0%)</div>'
                 . '<div class="legend-item"><span class="legend-dot" style="background:#c0392b;"></span><strong>Refusées</strong> : 0 (0%)</div>'
                 . '</div></div>';
        }

        $p_valide = round(($valide / $total) * 100);
        $p_en_cours = round(($en_cours / $total) * 100);
        $p_refuse = round(($refuse / $total) * 100);
        // Ajuster pour que ça fasse 100%
        $diff = 100 - $p_valide - $p_en_cours - $p_refuse;
        $p_valide += $diff; // Compenser les arrondis

        // Construire le conic-gradient
        $g_valide_end = $p_valide;
        $g_en_cours_end = $p_valide + $p_en_cours;
        $gradient = "conic-gradient(#1a6b3c 0% {$g_valide_end}%, #b45309 {$g_valide_end}% {$g_en_cours_end}%, #c0392b {$g_en_cours_end}% 100%)";

        return '<div class="chart-row">'
             . '<div class="donut-chart" style="background:' . $gradient . ';">'
             . '<div class="donut-center">'
             . '<span class="donut-value">' . $total . '</span>'
             . '<span class="donut-label">Total</span>'
             . '</div></div>'
             . '<div class="chart-legend">'
             . '<div class="legend-item"><span class="legend-dot" style="background:#1a6b3c;"></span><strong>Validées</strong> : ' . $valide . ' (' . $p_valide . '%)</div>'
             . '<div class="legend-item"><span class="legend-dot" style="background:#b45309;"></span><strong>En cours</strong> : ' . $en_cours . ' (' . $p_en_cours . '%)</div>'
             . '<div class="legend-item"><span class="legend-dot" style="background:#c0392b;"></span><strong>Refusées</strong> : ' . $refuse . ' (' . $p_refuse . '%)</div>'
             . '</div></div>';
    }
}
