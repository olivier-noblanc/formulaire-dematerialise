<?php
// style.php — CSS partagé pour toutes les pages du Workflow DREETS
// À inclure via require_once __DIR__ . '/style.php'; dans le <head>
// Les pages ajoutent leur CSS spécifique dans un second bloc <style> après celui-ci
?>
<style>
/* ── Reset & Base ──────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body { font-family: "Marianne", Arial, sans-serif; background: #f5f5fe; color: #1e1e1e; }

/* ── Bandeau ───────────────────────────────────────────────── */
.bandeau { background: #003189; color: #fff; padding: .75rem 2rem; font-size: .85rem; margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: .5rem; }
.bandeau a { color: #b3c8f0; font-size: .8rem; text-decoration: none; }
.bandeau a:hover { text-decoration: underline; }

/* ── Container ─────────────────────────────────────────────── */
.container { max-width: 900px; margin: 0 auto; padding: 0 1rem 2rem; }

/* ── Typography ────────────────────────────────────────────── */
h1 { font-size: 1.4rem; color: #003189; margin-bottom: 1.25rem; }
h2 { font-size: 1.1rem; color: #003189; border-bottom: 2px solid #003189; padding-bottom: .5rem; margin-bottom: 1rem; }
h3 { font-size: 1.05rem; color: #003189; margin-bottom: .75rem; }

/* ── Cards ─────────────────────────────────────────────────── */
.card { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 1.5rem; margin-bottom: 1.5rem; }
.card h2 { font-size: 1.1rem; color: #003189; border-bottom: 2px solid #003189; padding-bottom: .5rem; margin-bottom: 1.25rem; }

/* ── Buttons ───────────────────────────────────────────────── */
.btn { padding: .5rem 1rem; border: none; border-radius: 3px; font-size: .85rem; font-family: inherit; cursor: pointer; text-decoration: none; display: inline-block; }
.btn-primary { background: #003189; color: #fff; }
.btn-primary:hover { background: #002270; }
.btn-secondary { background: #f0f0f0; color: #333; }
.btn-secondary:hover { background: #e0e0e0; }
.btn-danger { background: #c0392b; color: #fff; }
.btn-danger:hover { background: #a93226; }
.btn-test { background: #27ae60; color: #fff; }
.btn-test:hover { background: #219a52; }

/* ── Messages ──────────────────────────────────────────────── */
.msg-success { background: #e8f5e9; border: 1px solid #27ae60; border-radius: 3px; padding: .75rem 1rem; margin-bottom: 1rem; color: #1a6b3c; }
.msg-error { background: #ffebee; border: 1px solid #c0392b; border-radius: 3px; padding: .75rem 1rem; margin-bottom: 1rem; color: #c0392b; }
.msg-info { background: #e3f2fd; border: 1px solid #1976d2; border-radius: 3px; padding: .75rem 1rem; margin-bottom: 1rem; color: #1565c0; }
.errors { background: #fde8e8; border: 1px solid #c0392b; border-radius: 3px; padding: 1rem; margin-bottom: 1.5rem; color: #c0392b; }
.success { background: #e8f5e9; border: 1px solid #27ae60; border-radius: 3px; padding: 1.5rem; color: #1a6b3c; text-align: center; }
.success strong { display: block; font-size: 1.2rem; margin-bottom: .5rem; }

/* ── Form fields ───────────────────────────────────────────── */
.field { display: flex; flex-direction: column; gap: .3rem; margin-bottom: 1rem; }
label { font-size: .85rem; font-weight: bold; color: #444; }
.hint { font-size: .75rem; color: #888; font-weight: normal; }
.req { color: #c0392b; margin-left: 2px; }
input[type="text"], input[type="date"], input[type="number"], input[type="password"], input[type="email"], select, textarea {
    width: 100%; padding: .5rem .75rem; border: 1px solid #aaa;
    border-radius: 3px; font-size: .9rem; font-family: inherit; background: #fff; color: #1e1e1e;
}
input:focus, select:focus, textarea:focus { outline: 2px solid #003189; outline-offset: 1px; border-color: #003189; }
textarea { resize: vertical; min-height: 80px; }
.field-error { border-color: #c0392b !important; background: #fff5f5; }
.error-hint { color: #c0392b; font-size: .78rem; }

/* ── Checkboxes ────────────────────────────────────────────── */
.checkbox-label { display: flex; align-items: center; gap: .5rem; font-size: .9rem; }
.checkbox-label input[type="checkbox"] { width: 18px; height: 18px; accent-color: #003189; }
.checkboxes { display: flex; flex-direction: column; gap: .5rem; margin-top: .25rem; }
.checkbox-item { display: flex; align-items: center; gap: .5rem; font-size: .9rem; }
input[type="checkbox"] { width: 18px; height: 18px; accent-color: #003189; cursor: pointer; flex-shrink: 0; }

/* ── Tables ────────────────────────────────────────────────── */
table { width: 100%; border-collapse: collapse; font-size: .85rem; background: #fff; }
thead { background: #003189; color: #fff; }
thead th { padding: .6rem .75rem; text-align: left; font-weight: normal; white-space: nowrap; }
th, td { padding: .6rem .75rem; text-align: left; border-bottom: 1px solid #eee; }
th { background: #003189; color: #fff; font-weight: normal; }
tbody td { padding: .5rem .75rem; border-bottom: 1px solid #eee; vertical-align: middle; }
tbody tr:nth-child(even) { background: #f7f7fb; }
tbody tr:hover { background: #f0f0f8; }

/* ── Badges ────────────────────────────────────────────────── */
.badge { display: inline-block; padding: .25rem .75rem; border-radius: 3px; font-size: .8rem; font-weight: bold; }
.badge-ok, .badge-valide { background: #e8f5e9; color: #1a6b3c; }
.badge-warn, .badge-en-cours { background: #fff3e0; color: #b45309; }
.badge-err, .badge-refuse { background: #fde8e8; color: #c0392b; }
.badge-info { background: #e3f2fd; color: #1565c0; }

/* ── Stats ─────────────────────────────────────────────────── */
.stats { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
.stat { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: .75rem 1.25rem; min-width: 120px; font-size: .9rem; }
.stat strong { display: block; font-size: 1.8rem; color: #003189; }
.stat.en-cours strong, .stat.warning strong { color: #b45309; }
.stat.valide strong, .stat.success strong { color: #1a6b3c; }
.stat.refuse strong, .stat.danger strong { color: #c0392b; }

/* ── Stat cards (monitoring) ───────────────────────────────── */
.stat-card { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 1.25rem; text-align: center; }
.stat-card .stat-value { font-size: 2rem; font-weight: bold; color: #003189; }
.stat-card .stat-label { font-size: .85rem; color: #555; margin-top: .25rem; }
.stat-card.success .stat-value { color: #1a6b3c; }
.stat-card.danger .stat-value { color: #c0392b; }
.stat-card.warning .stat-value { color: #b45309; }

/* ── Empty state ───────────────────────────────────────────── */
.empty-state { text-align: center; padding: 2rem 1rem; color: #888; }
.empty-state .empty-icon { font-size: 3rem; margin-bottom: 1rem; }
.empty-state p { margin-bottom: 1rem; }

/* ── Toolbar / Filters ─────────────────────────────────────── */
.toolbar { display: flex; gap: .5rem; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; }
select.form-filter { padding: .4rem .75rem; border: 1px solid #aaa; border-radius: 3px; font-size: .85rem; font-family: inherit; }
.form-actions { display: flex; gap: .5rem; margin-top: 1rem; }

/* ── Grids ─────────────────────────────────────────────────── */
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
.grid-3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem; }

/* ── Timeline / Workflow steps ─────────────────────────────── */
.timeline { display: flex; align-items: flex-start; margin-top: 1rem; padding-top: 1rem; border-top: 1px solid #eee; overflow-x: auto; }
.step-item { display: flex; flex-direction: column; align-items: center; min-width: 100px; max-width: 160px; flex: 1; text-align: center; position: relative; padding: 0 .5rem; }
.step-item:not(:last-child)::after { content: ''; position: absolute; top: 16px; right: -50%; width: 100%; height: 3px; z-index: 0; }
.step-item.step-validated:not(:last-child)::after { background: #1a6b3c; }
.step-item.step-current:not(:last-child)::after { background: #b45309; }
.step-item.step-upcoming:not(:last-child)::after { background: #ccc; }
.step-icon { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: .9rem; font-weight: bold; z-index: 1; margin-bottom: .5rem; }
.step-validated .step-icon { background: #1a6b3c; color: #fff; }
.step-current .step-icon { background: #b45309; color: #fff; }
.step-upcoming .step-icon { background: #ccc; color: #666; }
.step-label { font-size: .78rem; font-weight: bold; color: #333; margin-bottom: .25rem; line-height: 1.3; }
.step-detail { font-size: .72rem; color: #888; line-height: 1.4; }

/* ── Health dots (monitoring) ──────────────────────────────── */
.health-dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; margin-right: .5rem; vertical-align: middle; }
.health-ok { background: #1a6b3c; }
.health-err { background: #c0392b; }
.health-warn { background: #b45309; }
.health-unknown { background: #999; }

/* ── Action buttons (admin) ────────────────────────────────── */
.actions { display: flex; gap: .5rem; }
.action-btn { padding: .3rem .6rem; border: none; border-radius: 3px; font-size: .8rem; cursor: pointer; }
.approve-btn, .toggle-btn { background: #1a6b3c; color: #fff; }
.reject-btn, .delete-btn { background: #c0392b; color: #fff; }

/* ── Form layout ───────────────────────────────────────────── */
.form-group { margin-bottom: 1.5rem; }
.form-selector { display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem; }
.form-selector select { padding: .6rem; border: 1px solid #aaa; border-radius: 3px; font-size: 1rem; font-family: inherit; }
.form-selector button { padding: .6rem 1.2rem; border: none; border-radius: 3px; background: #003189; color: #fff; cursor: pointer; }
.form-selector button:hover { background: #002270; }
.form-section { margin-bottom: 2rem; }
.form-section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.form-section-header h2 { margin: 0; }
.form-section-header a { text-decoration: none; }
.section-title { font-size: 1.2rem; color: #003189; margin-bottom: 1rem; padding-bottom: .5rem; border-bottom: 2px solid #003189; }

/* ── Pagination ────────────────────────────────────────────── */
.pagination { display: flex; justify-content: center; align-items: center; gap: .75rem; margin-top: 1.5rem; font-size: .9rem; }
.pagination a, .pagination span { padding: .4rem .75rem; border: 1px solid #003189; border-radius: 3px; text-decoration: none; color: #003189; }
.pagination .current { background: #003189; color: #fff; border-color: #003189; }
.pagination .disabled { color: #aaa; border-color: #ddd; pointer-events: none; }

/* ── Info / Warn boxes (docs) ──────────────────────────────── */
.info-box { background: #e8eaf6; border-left: 4px solid #003189; padding: 1rem 1.25rem; margin-bottom: 1rem; border-radius: 0 4px 4px 0; }
.info-box p { margin-bottom: .25rem; }
.warn-box { background: #fff3e0; border-left: 4px solid #b45309; padding: 1rem 1.25rem; margin-bottom: 1rem; border-radius: 0 4px 4px 0; }
.warn-box p { margin-bottom: .25rem; }
.success-box { background: #e8f5e9; border-left: 4px solid #27ae60; padding: 1rem 1.25rem; margin-bottom: 1rem; border-radius: 0 4px 4px 0; }
.success-box p { margin-bottom: .25rem; }

/* ── Responsive ────────────────────────────────────────────── */
@media (max-width: 768px) { .grid-2 { grid-template-columns: 1fr; } }
@media (max-width: 600px) { .grid-2 { grid-template-columns: 1fr; } }

/* ── Skip link (RGAA) ────────────────────────────────────── */
.skip-link { position: absolute; left: -9999px; top: 0; background: #003189; color: #fff; padding: .5rem 1rem; z-index: 9999; font-size: .9rem; }
.skip-link:focus { left: 0; }

/* ── Focus visible (RGAA) ────────────────────────────────── */
:focus-visible { outline: 3px solid #003189; outline-offset: 2px; }
a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible { outline: 3px solid #003189; outline-offset: 2px; }

/* ── Visually hidden (screen readers only) ────────────────── */
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }

/* ── Print ──────────────────────────────────────────────────── */
@media print {
  /* Hide non-essential elements */
  .bandeau, footer, .btn, .form-actions, .actions-bar, .action-btn, .card-actions,
  .toolbar, .filtres, .form-selector { display: none !important; }

  /* Reset body for print */
  body { background: #fff !important; color: #000 !important; }

  /* Remove card borders and shadows */
  .card, .section-card { border: none !important; box-shadow: none !important; }

  /* Full-width container */
  .container { max-width: 100% !important; padding: 0 !important; margin: 0 !important; }

  /* Show URLs after links */
  a[href]::after { content: " (" attr(href) ")"; font-size: .85em; color: #555; }
  a[href^="#"]::after, a[href^="javascript"]::after { content: ""; }

  /* Table borders for print */
  table, th, td { border: 1px solid #999 !important; }
  thead { background: #eee !important; color: #000 !important; }
  th { background: #eee !important; color: #000 !important; }

  /* Avoid page breaks inside cards */
  .card, .section-card { page-break-inside: avoid; }
}
</style>
