<?php

declare(strict_types=1);

namespace App\Render;

use App\Contract\HtmlInterface;
use App\Core\App;

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
     * Traduit le jargon administratif en termes compréhensibles.
     *
     * Délègue à JargonService::translate() — source unique de vérité.
     * Anciennement HtmlService avait son propre JARGON_MAPPINGS divergeant
     * de JargonService (cf. bug B4 du rapport d'audit 2026-07-26).
     */
    public function tJargon(string $text): string
    {
        return JargonService::translate($text);
    }

    /**
     * Formate une date SQL au format français "d/m/Y à H:i".
     *
     * Centralise le date(...)/strtotime(...) dupliqué dans plusieurs
     * renderers pour l'affichage du statut des tokens (sent_at, done_at,
     * expires_at) — signalé 2026-07-30 (2 occurrences dans
     * SubmissionViewRenderer seul, formatage à l'identique dans
     * MonitoringRenderer/MyValidationsRenderer à migrer si l'occasion se
     * présente).
     */
    public function formatDateTimeFr(?string $dateStr): string
    {
        if ($dateStr === null || $dateStr === '') {
            return '';
        }
        return date('d/m/Y à H:i', (int) strtotime($dateStr));
    }

    /**
     * Formate un fragment " — N rappel(s) envoyé(s)" à ajouter à un texte
     * de statut de token, ou une chaîne vide si aucune relance.
     *
     * Centralise le pluriel dupliqué (2 occurrences dans
     * SubmissionViewRenderer, une variante dans MyValidationsRenderer)
     * signalé 2026-07-30.
     */
    public function formatRelanceSuffix(int $relanceCount): string
    {
        if ($relanceCount <= 0) {
            return '';
        }
        return ' — ' . $relanceCount . ' rappel' . ($relanceCount > 1 ? 's' : '') . ' envoyé' . ($relanceCount > 1 ? 's' : '');
    }

    /**
     * Affiche un email avec masquage du domaine pour l'utilisateur courant.
     */
    public function displayUser(string $email, ?string $current_user = null, bool $force_email = false): string
    {
        if ($email === '') {
            return '';
        }
        if ($force_email) {
            return $this->h($email);
        }

        if ($current_user === null) {
            $current_user = App::auth()->getUser() ?: '';
        }

        // Cas 1 : email = user courant → "Vous"
        if ($current_user !== '' && strcasecmp($email, $current_user) === 0) {
            return '<strong>Vous</strong>';
        }

        // Cas 2 : masquer le domaine si = domaine du user courant
        if ($current_user !== '' && str_contains($current_user, '@')) {
            $user_domain = strtolower(substr($current_user, (int) strrpos($current_user, '@')));
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
     * Affiche le "local part" d'un email (sans @domaine).
     */
    public function displayUserShort(string $email): string
    {
        if ($email === '') {
            return '';
        }
        $at_pos = strpos($email, '@');
        if ($at_pos !== false) {
            return $this->h(substr($email, 0, $at_pos));
        }
        if (str_contains($email, '\\')) {
            $parts = explode('\\', $email);
            return $this->h($parts[1] ?? $parts[0]);
        }
        return $this->h($email);
    }

    /**
     * Génère la pagination HTML.
     */
    public function renderPagination(int $page, int $total_pages, string $base_url): string
    {
        if ($total_pages <= 1) {
            return '';
        }
        $html = '<div class="pagination flex-gap5-5">';
        $sep = (str_contains($base_url, '?')) ? '&' : '?';
        if ($page > 1) {
            $html .= '<a href="' . $this->h($base_url . $sep . 'page=' . ($page - 1)) . '" class="btn btn-secondary btn-sm-4">← Précédent</a>';
        }
        $html .= '<span class="u-col-fon-8">Page ' . $page . ' / ' . $total_pages . '</span>';
        if ($page < $total_pages) {
            $html .= '<a href="' . $this->h($base_url . $sep . 'page=' . ($page + 1)) . '" class="btn btn-secondary btn-sm-4">Suivant →</a>';
        }
        return $html . '</div>';
    }

    /**
     * Construit une URL en propageant automatiquement ?persona_token si présent.
     */
    public function buildUrl(string $url): string
    {
        $token = isset($_GET['persona_token']) ? (string) $_GET['persona_token'] : '';
        if ($token === '') {
            return $url;
        }

        $anchor = '';
        $url_without_anchor = $url;
        $anchor_pos = strpos($url, '#');
        if ($anchor_pos !== false) {
            $anchor = substr($url, $anchor_pos);
            $url_without_anchor = substr($url, 0, $anchor_pos);
        }

        $sep = str_contains($url_without_anchor, '?') ? '&' : '?';
        return $url_without_anchor . $sep . 'persona_token=' . urlencode($token) . $anchor;
    }

    /**
     * Génère un graphique donut pour la répartition des statuts.
     */
    public function renderDonutChart(int $total, int $valide, int $en_cours, int $refuse): string
    {
        if ($total <= 0) {
            return '<div class="chart-row">'
                 . '<div class="donut-chart bg-light-gray">'
                 . '<div class="donut-center">'
                 . '<span class="donut-value">0</span>'
                 . '<span class="donut-label">Total</span>'
                 . '</div></div>'
                 . '<div class="chart-legend">'
                 . '<div class="legend-item"><span class="legend-dot bg-success"></span><strong>Validées</strong> : 0 (0%)</div>'
                 . '<div class="legend-item"><span class="legend-dot bg-warning"></span><strong>En cours</strong> : 0 (0%)</div>'
                 . '<div class="legend-item"><span class="legend-dot bg-danger"></span><strong>Refusées</strong> : 0 (0%)</div>'
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
             . '<div class="legend-item"><span class="legend-dot bg-success"></span><strong>Validées</strong> : ' . $valide . ' (' . $p_valide . '%)</div>'
             . '<div class="legend-item"><span class="legend-dot bg-warning"></span><strong>En cours</strong> : ' . $en_cours . ' (' . $p_en_cours . '%)</div>'
             . '<div class="legend-item"><span class="legend-dot bg-danger"></span><strong>Refusées</strong> : ' . $refuse . ' (' . $p_refuse . '%)</div>'
             . '</div></div>';
    }
}
