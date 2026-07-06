<?php
declare(strict_types=1);

/**
 * CSS de la page "Gestion des formulaires".
 *
 * Extrait de {@see render_admin_forms_page()} pour réduire la taille du
 * contrôleur principal. La fonction {@see get_admin_forms_page_css()}
 * retourne le bloc CSS injecté via `<style>` par {@see render_page()}.
 *
 * @package lib
 */

/**
 * Retourne le CSS spécifique à la page admin_forms.php.
 *
 * @return string CSS brut (sans la balise `<style>`)
 */
function get_admin_forms_page_css(): string {
    return <<<'CSS'
        .container { max-width: 1200px; }

        /* ── Section cards with colored headers ──────────────── */
        .section-card {
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            border-radius: var(--r-md);
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        .section-card-header {
            background: var(--c-primary-dark);
            color: var(--c-text-inverse);
            padding: .75rem 1.25rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .section-card-header h2 {
            color: var(--c-text-inverse);
            border: none;
            margin: 0;
            padding: 0;
            font-size: 1.05rem;
        }
        .section-card-header a {
            color: var(--c-text-inverse);
            text-decoration: none;
            font-size: .82rem;
            opacity: .85;
        }
        .section-card-header a:hover {
            opacity: 1;
        }
        .section-card-header button.btn-secondary {
            color: var(--c-sidebar-text);
            background: var(--c-surface);
            border: 1px solid var(--c-border);
            font-size: .82rem;
            opacity: .95;
        }
        .section-card-header button.btn-secondary:hover {
            opacity: 1;
            background: var(--c-primary-50);
        }
        .section-card-header button:not(.btn-secondary) {
            color: var(--c-text-inverse);
            font-size: .82rem;
            opacity: .85;
        }
        .section-card-header button:not(.btn-secondary):hover {
            opacity: 1;
        }
        .section-card-body {
            padding: 1.25rem;
        }

        /* ── Workflow diagram ────────────────────────────────── */
        .workflow-diagram {
            display: flex;
            align-items: flex-start;
            gap: 0;
            padding: 1.5rem 0.5rem;
            overflow-x: auto;
        }
        .workflow-step-group {
            display: flex;
            flex-direction: column;
            align-items: center;
            min-width: 150px;
            max-width: 200px;
            flex-shrink: 0;
        }
        .workflow-arrow {
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 40px;
            flex-shrink: 0;
            padding-top: 0;
            align-self: stretch;
            display: flex;
            align-items: center;
        }
        .workflow-arrow::after {
            content: '→';
            font-size: 1.8rem;
            color: var(--c-primary-dark);
            font-weight: bold;
        }
        .workflow-box {
            background: var(--c-primary-dark);
            color: var(--c-text-inverse);
            border-radius: var(--r-md);
            padding: .75rem 1rem;
            text-align: center;
            width: 100%;
            margin-bottom: .5rem;
            box-shadow: var(--shadow-colored);
        }
        .workflow-box.inactive {
            background: #b0b0b0;
            box-shadow: none;
        }
        .workflow-box .wb-label {
            font-weight: bold;
            font-size: .88rem;
            margin-bottom: .25rem;
        }
        .workflow-box .wb-ordre {
            font-size: .72rem;
            opacity: .8;
            margin-bottom: .35rem;
        }
        .workflow-box .wb-emails {
            font-size: .72rem;
            opacity: .75;
            line-height: 1.4;
            word-break: break-all;
        }
        .workflow-box.inactive .wb-label { opacity: .7; }
        .workflow-box.inactive .wb-ordre { opacity: .5; }
        .workflow-box.inactive .wb-emails { opacity: .5; }
        .workflow-empty {
            text-align: center;
            padding: 2rem;
            color: #888;
            font-style: italic;
        }

        /* ── Step list items ─────────────────────────────────── */
        .step-card {
            border: 1px solid var(--c-border);
            border-radius: var(--r-sm);
            padding: .75rem 1rem;
            margin-bottom: .75rem;
            background: var(--c-bg-warm);
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
        }
        .step-card.editing {
            background: #f0f4ff;
            border-color: var(--c-primary-dark);
        }
        .step-info { flex: 1; }
        .step-info .step-label { font-weight: bold; color: var(--c-primary-dark); }
        .step-info .step-meta { font-size: .82rem; color: #666; margin-top: .25rem; }
        .step-info .step-meta .badge-ok { margin-left: .5rem; }
        .step-actions { display: flex; gap: .4rem; flex-shrink: 0; }
        .recipient-chips { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .4rem; }
        .recipient-chip {
            background: #e3f2fd;
            border: 1px solid #90caf9;
            border-radius: 12px;
            padding: .15rem .6rem;
            font-size: .76rem;
            color: #1565c0;
            display: inline-flex;
            align-items: center;
            gap: .3rem;
        }
        .recipient-chip form {
            display: inline;
        }
        .recipient-chip .chip-delete {
            background: none;
            border: none;
            color: #c0392b;
            cursor: pointer;
            font-size: .9rem;
            padding: 0;
            line-height: 1;
        }

        /* ── Field table improvements ────────────────────────── */
        .fields-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
        .fields-table thead th {
            background: var(--c-primary-dark);
            color: var(--c-text-inverse);
            padding: .55rem .6rem;
            text-align: left;
            font-weight: normal;
            white-space: nowrap;
        }
        .fields-table tbody td {
            padding: .5rem .6rem;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }
        .fields-table tbody tr:hover { background: #f0f4ff; }
        .field-type-badge {
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            background: #e8eaf6;
            color: var(--c-primary-dark);
            border-radius: var(--r-sm);
            padding: .2rem .5rem;
            font-size: .78rem;
            font-weight: bold;
        }
        .required-star {
            color: #c0392b;
            font-weight: bold;
            font-size: 1rem;
            margin-left: 2px;
        }

        /* ── Preview button ──────────────────────────────────── */
        .btn-preview {
            background: var(--c-success);
            color: var(--c-text-inverse);
            padding: .5rem 1rem;
            border: none;
            border-radius: var(--r-sm);
            font-size: .85rem;
            font-family: inherit;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: .3rem;
        }
        .btn-preview:hover { background: #219a52; }

        /* ── Form grid ───────────────────────────────────────── */
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .75rem;
        }
        .form-grid .full-width {
            grid-column: 1 / -1;
        }
        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
        }

        /* ── Step recipient section ──────────────────────────── */
        .step-recipient-picker {
            margin-top: 1rem;
        }
        .step-recipient-picker select {
            max-width: 350px;
        }

        /* ── Add forms ───────────────────────────────────────── */
        .add-sub-card {
            background: #f9f9ff;
            border: 1px dashed #aab;
            border-radius: 5px;
            padding: 1rem;
            margin-top: 1rem;
        }
        .add-sub-card h4 {
            font-size: .92rem;
            color: var(--c-primary-dark);
            margin-bottom: .75rem;
        }
CSS;
}
