<?php
// style.php — Design System 2026 pour Formulaire Dématérialisé DREETS
// À inclure via require_once __DIR__ . '/style.php'; dans le <head>
// Zéro JavaScript — Pure CSS + HTML5
?>
<style>
/* ═══════════════════════════════════════════════════════════════
   DESIGN SYSTEM 2026 — "Glassmorphism Institutionnel"
   Palette indigo-violet, glassmorphism, soft shadows, pill buttons
   ═══════════════════════════════════════════════════════════════ */

/* ── Custom Properties (Design Tokens) ──────────────────────── */
:root {
  /* Primary — Indigo → Violet gradient */
  --c-primary: #4F46E5;
  --c-primary-dark: #3730A3;
  --c-primary-darker: #312E81;
  --c-primary-light: #818CF8;
  --c-primary-50: #EEF2FF;

  /* Accent — Warm amber for alerts */
  --c-accent: #F59E0B;
  --c-accent-dark: #D97706;

  /* Semantic */
  --c-success: #10B981;
  --c-success-dark: #059669;
  --c-success-50: #ECFDF5;
  --c-warning: #F59E0B;
  --c-warning-dark: #D97706;
  --c-warning-50: #FFFBEB;
  --c-danger: #EF4444;
  --c-danger-dark: #DC2626;
  --c-danger-50: #FEF2F2;
  --c-info: #6366F1;
  --c-info-50: #EEF2FF;

  /* Neutrals — Warm gray */
  --c-bg: #F7F5F3;
  --c-bg-warm: #FAF9F7;
  --c-surface: #FFFFFF;
  --c-surface-glass: rgba(255, 255, 255, 0.72);
  --c-border: #E5E1DB;
  --c-border-light: #F0EDE8;
  --c-text: #1C1917;
  --c-text-secondary: #57534E;
  --c-text-tertiary: #A8A29E;
  --c-text-inverse: #FFFFFF;

  /* Shadows — Layered soft */
  --shadow-xs: 0 1px 2px rgba(0,0,0,.04);
  --shadow-sm: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
  --shadow-md: 0 4px 6px -1px rgba(0,0,0,.07), 0 2px 4px -2px rgba(0,0,0,.05);
  --shadow-lg: 0 10px 15px -3px rgba(0,0,0,.08), 0 4px 6px -4px rgba(0,0,0,.04);
  --shadow-xl: 0 20px 25px -5px rgba(0,0,0,.1), 0 8px 10px -6px rgba(0,0,0,.04);
  --shadow-glow: 0 0 20px rgba(79,70,229,.15);
  --shadow-colored: 0 4px 14px rgba(79,70,229,.18);

  /* Radius */
  --r-sm: 6px;
  --r-md: 10px;
  --r-lg: 14px;
  --r-xl: 20px;
  --r-full: 9999px;

  /* Spacing */
  --sp-xs: .25rem;
  --sp-sm: .5rem;
  --sp-md: 1rem;
  --sp-lg: 1.5rem;
  --sp-xl: 2rem;
  --sp-2xl: 3rem;

  /* Typography */
  --font-sans: "Marianne", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  --font-mono: "SFMono-Regular", Menlo, Consolas, "Liberation Mono", monospace;
  --text-xs: .75rem;
  --text-sm: .8125rem;
  --text-base: .9375rem;
  --text-lg: 1.125rem;
  --text-xl: 1.375rem;
  --text-2xl: 1.75rem;
  --text-3xl: 2.25rem;

  /* Transitions */
  --ease-out: cubic-bezier(.16, 1, .3, 1);
  --ease-spring: cubic-bezier(.34, 1.56, .64, 1);
  --duration-fast: .15s;
  --duration-normal: .25s;
  --duration-slow: .4s;

  /* Gradients */
  --gradient-primary: linear-gradient(135deg, #4F46E5 0%, #7C3AED 100%);
  --gradient-primary-hover: linear-gradient(135deg, #4338CA 0%, #6D28D9 100%);
  --gradient-warm: linear-gradient(135deg, #F59E0B 0%, #EF4444 100%);
  --gradient-cool: linear-gradient(135deg, #6366F1 0%, #06B6D4 100%);
  --gradient-success: linear-gradient(135deg, #10B981 0%, #059669 100%);
  --gradient-surface: linear-gradient(180deg, rgba(255,255,255,.9) 0%, rgba(255,255,255,.6) 100%);
}

/* ── Reset & Base ────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }
body {
  font-family: var(--font-sans);
  background: var(--c-bg);
  color: var(--c-text);
  font-size: var(--text-base);
  line-height: 1.6;
  min-height: 100vh;
}

/* ── Navigation — Glassmorphism top bar ─────────────────────── */
.bandeau {
  background: var(--gradient-primary);
  color: var(--c-text-inverse);
  padding: 0 2rem;
  font-size: var(--text-sm);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--sp-sm);
  position: sticky;
  top: 0;
  z-index: 100;
  box-shadow: var(--shadow-lg);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
  min-height: 56px;
}
.bandeau a {
  color: rgba(255,255,255,.88);
  font-size: var(--text-sm);
  text-decoration: none;
  padding: .4rem .75rem;
  border-radius: var(--r-full);
  transition: background var(--duration-fast) var(--ease-out), color var(--duration-fast) var(--ease-out);
  white-space: nowrap;
}
.bandeau a:hover {
  background: rgba(255,255,255,.18);
  color: #fff;
  text-decoration: none;
}
.bandeau a:focus-visible {
  outline: 2px solid #fff;
  outline-offset: 2px;
}
.bandeau a.nav-active {
  background: rgba(255,255,255,.22);
  color: #fff;
  font-weight: 700;
  box-shadow: inset 0 0 0 1px rgba(255,255,255,.15);
}
.bandeau .nav-brand {
  color: #fff;
  font-size: var(--text-lg);
  font-weight: 800;
  text-decoration: none;
  padding: 0;
  letter-spacing: -.02em;
  display: flex;
  align-items: center;
  gap: .5rem;
}
.bandeau .nav-brand:hover { background: transparent; }
.bandeau .nav-brand .brand-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--c-accent); margin-left: 2px; }
.bandeau .nav-main { display: flex; gap: .2rem; flex-wrap: wrap; align-items: center; }
.bandeau .nav-admin { display: flex; gap: .2rem; align-items: center; flex-wrap: wrap; }
.bandeau .nav-user { color: rgba(255,255,255,.7); font-size: var(--text-xs); margin-left: .5rem; }
.bandeau .nav-badge {
  background: var(--c-accent);
  color: var(--c-text);
  font-size: .68rem;
  padding: 1px 7px;
  border-radius: var(--r-full);
  font-weight: 800;
  vertical-align: middle;
  box-shadow: 0 0 0 2px rgba(245,158,11,.3);
}

/* ── Container ──────────────────────────────────────────────── */
.container { max-width: 960px; margin: 0 auto; padding: 0 1.5rem 2rem; }

/* ── Typography ─────────────────────────────────────────────── */
h1 {
  font-size: var(--text-2xl);
  font-weight: 800;
  color: var(--c-primary-dark);
  margin-bottom: .25rem;
  letter-spacing: -.025em;
  line-height: 1.2;
}
h2 {
  font-size: var(--text-xl);
  font-weight: 700;
  color: var(--c-primary-dark);
  border-bottom: none;
  padding-bottom: 0;
  margin-bottom: var(--sp-md);
  letter-spacing: -.015em;
  line-height: 1.3;
}
h3 {
  font-size: var(--text-lg);
  font-weight: 600;
  color: var(--c-primary-dark);
  margin-bottom: var(--sp-sm);
  line-height: 1.35;
}
p { margin-bottom: var(--sp-sm); }

/* ── Cards — Soft glass ─────────────────────────────────────── */
.card {
  background: var(--c-surface);
  border: 1px solid var(--c-border-light);
  border-radius: var(--r-lg);
  padding: var(--sp-lg);
  margin-bottom: var(--sp-lg);
  box-shadow: var(--shadow-sm);
  transition: box-shadow var(--duration-normal) var(--ease-out), transform var(--duration-normal) var(--ease-out);
}
.card:hover {
  box-shadow: var(--shadow-md);
}
.card h2 {
  font-size: var(--text-lg);
  color: var(--c-primary-dark);
  border-bottom: 2px solid var(--c-primary-50);
  padding-bottom: var(--sp-sm);
  margin-bottom: var(--sp-md);
}

/* ── Buttons — Pill style ───────────────────────────────────── */
.btn {
  padding: .55rem 1.25rem;
  border: none;
  border-radius: var(--r-full);
  font-size: var(--text-sm);
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: .35rem;
  transition: all var(--duration-fast) var(--ease-out);
  line-height: 1.4;
}
.btn:active { transform: scale(.97); }
.btn-primary {
  background: var(--gradient-primary);
  color: #fff;
  box-shadow: var(--shadow-sm), var(--shadow-colored);
}
.btn-primary:hover {
  background: var(--gradient-primary-hover);
  box-shadow: var(--shadow-md), var(--shadow-colored);
  color: #fff;
  text-decoration: none;
}
.btn-secondary {
  background: var(--c-surface);
  color: var(--c-text-secondary);
  border: 1px solid var(--c-border);
  box-shadow: var(--shadow-xs);
}
.btn-secondary:hover {
  background: var(--c-bg);
  border-color: var(--c-text-tertiary);
  color: var(--c-text);
  text-decoration: none;
}
.btn-danger {
  background: var(--c-danger);
  color: #fff;
  box-shadow: 0 2px 8px rgba(239,68,68,.25);
}
.btn-danger:hover {
  background: var(--c-danger-dark);
  color: #fff;
  box-shadow: 0 4px 12px rgba(239,68,68,.3);
  text-decoration: none;
}
.btn-test {
  background: var(--gradient-success);
  color: #fff;
}
.btn-test:hover { background: var(--c-success-dark); color: #fff; }

/* ── Messages — Rounded with icon band ──────────────────────── */
.msg-success {
  background: var(--c-success-50);
  border: 1px solid var(--c-success);
  border-left: 4px solid var(--c-success);
  border-radius: var(--r-md);
  padding: .85rem 1.25rem;
  margin-bottom: var(--sp-md);
  color: var(--c-success-dark);
  font-weight: 500;
}
.msg-error {
  background: var(--c-danger-50);
  border: 1px solid var(--c-danger);
  border-left: 4px solid var(--c-danger);
  border-radius: var(--r-md);
  padding: .85rem 1.25rem;
  margin-bottom: var(--sp-md);
  color: var(--c-danger-dark);
  font-weight: 500;
}
.msg-info {
  background: var(--c-info-50);
  border: 1px solid var(--c-info);
  border-left: 4px solid var(--c-info);
  border-radius: var(--r-md);
  padding: .85rem 1.25rem;
  margin-bottom: var(--sp-md);
  color: var(--c-primary-dark);
  font-weight: 500;
}
.errors {
  background: var(--c-danger-50);
  border: 1px solid var(--c-danger);
  border-radius: var(--r-md);
  padding: 1.25rem;
  margin-bottom: var(--sp-lg);
  color: var(--c-danger-dark);
}
.success {
  background: var(--c-success-50);
  border: 1px solid var(--c-success);
  border-radius: var(--r-lg);
  padding: 2rem;
  color: var(--c-success-dark);
  text-align: center;
  box-shadow: var(--shadow-sm);
}
.success strong { display: block; font-size: var(--text-xl); margin-bottom: .5rem; }

/* ── Form fields — Underline + floating feel ────────────────── */
.field { display: flex; flex-direction: column; gap: .3rem; margin-bottom: 1.15rem; }
label { font-size: var(--text-sm); font-weight: 600; color: var(--c-text-secondary); }
.hint { font-size: var(--text-xs); color: var(--c-text-tertiary); font-weight: 400; }
.req { color: var(--c-danger); margin-left: 2px; }
input[type="text"], input[type="date"], input[type="number"], input[type="password"], input[type="email"], input[type="tel"], input[type="url"], select, textarea {
    width: 100%;
    padding: .65rem .875rem;
    border: 1.5px solid var(--c-border);
    border-radius: var(--r-md);
    font-size: var(--text-base);
    font-family: inherit;
    background: var(--c-surface);
    color: var(--c-text);
    transition: border-color var(--duration-fast) var(--ease-out), box-shadow var(--duration-fast) var(--ease-out);
}
input:focus, select:focus, textarea:focus {
  outline: none;
  border-color: var(--c-primary);
  box-shadow: 0 0 0 3px rgba(79,70,229,.12);
}
textarea { resize: vertical; min-height: 90px; }
.field-error { border-color: var(--c-danger) !important; background: var(--c-danger-50); }
.error-hint { color: var(--c-danger); font-size: var(--text-xs); font-weight: 500; }

/* ── Checkboxes ─────────────────────────────────────────────── */
.checkbox-label { display: flex; align-items: center; gap: .5rem; font-size: var(--text-base); }
.checkbox-label input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--c-primary); }
.checkboxes { display: flex; flex-direction: column; gap: .5rem; margin-top: .25rem; }
.checkbox-item { display: flex; align-items: center; gap: .5rem; font-size: var(--text-base); }
input[type="checkbox"] { width: 18px; height: 18px; accent-color: var(--c-primary); cursor: pointer; flex-shrink: 0; }

/* ── Tables — Clean, no heavy header ────────────────────────── */
table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  font-size: var(--text-sm);
  background: var(--c-surface);
  border-radius: var(--r-lg);
  overflow: hidden;
  box-shadow: var(--shadow-sm);
  border: 1px solid var(--c-border-light);
}
thead { background: var(--c-primary-50); }
thead th {
  padding: .75rem 1rem;
  text-align: left;
  font-weight: 600;
  color: var(--c-primary-dark);
  white-space: nowrap;
  font-size: var(--text-xs);
  text-transform: uppercase;
  letter-spacing: .05em;
  border-bottom: 2px solid var(--c-primary-50);
}
th, td { padding: .6rem 1rem; text-align: left; border-bottom: 1px solid var(--c-border-light); }
th { font-weight: 600; }
tbody td { vertical-align: middle; }
tbody tr:last-child td { border-bottom: none; }
tbody tr { transition: background var(--duration-fast) var(--ease-out); }
tbody tr:nth-child(even) { background: var(--c-bg-warm); }
tbody tr:hover { background: var(--c-primary-50); }

/* ── Badges — Rounded pills ─────────────────────────────────── */
.badge { display: inline-flex; align-items: center; gap: .25rem; padding: .25rem .75rem; border-radius: var(--r-full); font-size: var(--text-xs); font-weight: 700; letter-spacing: .02em; }
.badge-ok, .badge-valide { background: var(--c-success-50); color: var(--c-success-dark); }
.badge-warn, .badge-en-cours { background: var(--c-warning-50); color: var(--c-warning-dark); }
.badge-err, .badge-refuse { background: var(--c-danger-50); color: var(--c-danger-dark); }
.badge-info { background: var(--c-info-50); color: var(--c-primary-dark); }

/* ── Stats — Bento cards with colored accent ────────────────── */
.stats { display: flex; gap: var(--sp-md); margin-bottom: var(--sp-lg); flex-wrap: wrap; }
.stat {
  background: var(--c-surface);
  border: 1px solid var(--c-border-light);
  border-radius: var(--r-lg);
  padding: 1rem 1.5rem;
  min-width: 120px;
  font-size: var(--text-sm);
  box-shadow: var(--shadow-sm);
  position: relative;
  overflow: hidden;
  transition: transform var(--duration-fast) var(--ease-out), box-shadow var(--duration-fast) var(--ease-out);
}
.stat:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.stat::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 3px;
  background: var(--gradient-primary);
}
.stat strong { display: block; font-size: var(--text-3xl); color: var(--c-primary); font-weight: 800; letter-spacing: -.03em; }
.stat span { color: var(--c-text-tertiary); font-size: var(--text-xs); font-weight: 500; text-transform: uppercase; letter-spacing: .05em; }
.stat.en-cours strong, .stat.warning strong { color: var(--c-warning-dark); }
.stat.en-cours::before, .stat.warning::before { background: var(--c-warning); }
.stat.valide strong, .stat.success strong { color: var(--c-success-dark); }
.stat.valide::before, .stat.success::before { background: var(--c-success); }
.stat.refuse strong, .stat.danger strong { color: var(--c-danger-dark); }
.stat.refuse::before, .stat.danger::before { background: var(--c-danger); }

/* ── Stat cards (monitoring) ────────────────────────────────── */
.stat-card {
  background: var(--c-surface);
  border: 1px solid var(--c-border-light);
  border-radius: var(--r-lg);
  padding: 1.5rem;
  text-align: center;
  box-shadow: var(--shadow-sm);
  position: relative;
  overflow: hidden;
}
.stat-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 3px;
  background: var(--gradient-primary);
}
.stat-card .stat-value { font-size: 2.25rem; font-weight: 800; color: var(--c-primary); letter-spacing: -.03em; }
.stat-card .stat-label { font-size: var(--text-sm); color: var(--c-text-tertiary); margin-top: .25rem; font-weight: 500; }
.stat-card.success .stat-value { color: var(--c-success-dark); }
.stat-card.success::before { background: var(--gradient-success); }
.stat-card.danger .stat-value { color: var(--c-danger-dark); }
.stat-card.danger::before { background: linear-gradient(135deg, #EF4444, #DC2626); }
.stat-card.warning .stat-value { color: var(--c-warning-dark); }
.stat-card.warning::before { background: linear-gradient(135deg, #F59E0B, #D97706); }

/* ── Empty state ────────────────────────────────────────────── */
.empty-state { text-align: center; padding: var(--sp-2xl) var(--sp-md); color: var(--c-text-tertiary); }
.empty-state .empty-icon { font-size: 3.5rem; margin-bottom: var(--sp-md); opacity: .6; }
.empty-state p { margin-bottom: var(--sp-md); font-size: var(--text-base); }

/* ── Toolbar / Filters ─────────────────────────────────────── */
.toolbar { display: flex; gap: var(--sp-sm); align-items: center; margin-bottom: var(--sp-lg); flex-wrap: wrap; }
select.form-filter {
  padding: .5rem .875rem;
  border: 1.5px solid var(--c-border);
  border-radius: var(--r-md);
  font-size: var(--text-sm);
  font-family: inherit;
  background: var(--c-surface);
  color: var(--c-text);
  transition: border-color var(--duration-fast);
}
.form-actions { display: flex; gap: var(--sp-sm); margin-top: var(--sp-md); }

/* ── Grids ──────────────────────────────────────────────────── */
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: var(--sp-md); }
.grid-3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--sp-md); margin-bottom: var(--sp-lg); }

/* ── Timeline / Workflow steps ──────────────────────────────── */
.timeline { display: flex; align-items: flex-start; margin-top: var(--sp-md); padding-top: var(--sp-md); border-top: 1px solid var(--c-border-light); overflow-x: auto; }
.step-item { display: flex; flex-direction: column; align-items: center; min-width: 100px; max-width: 160px; flex: 1; text-align: center; position: relative; padding: 0 .5rem; }
.step-item:not(:last-child)::after { content: ''; position: absolute; top: 16px; right: -50%; width: 100%; height: 3px; z-index: 0; border-radius: 2px; }
.step-item.step-validated:not(:last-child)::after { background: var(--c-success); }
.step-item.step-current:not(:last-child)::after { background: var(--c-warning); }
.step-item.step-upcoming:not(:last-child)::after { background: var(--c-border); }
.step-icon {
  width: 34px; height: 34px; border-radius: var(--r-full);
  display: flex; align-items: center; justify-content: center;
  font-size: var(--text-sm); font-weight: 700; z-index: 1; margin-bottom: var(--sp-sm);
}
.step-validated .step-icon { background: var(--c-success); color: #fff; box-shadow: 0 2px 8px rgba(16,185,129,.3); }
.step-current .step-icon { background: var(--c-warning); color: #fff; box-shadow: 0 2px 8px rgba(245,158,11,.3); }
.step-upcoming .step-icon { background: var(--c-border); color: var(--c-text-tertiary); }
.step-label { font-size: var(--text-xs); font-weight: 700; color: var(--c-text); margin-bottom: .2rem; line-height: 1.3; }
.step-detail { font-size: .7rem; color: var(--c-text-tertiary); line-height: 1.4; }

/* ── Health dots (monitoring) ───────────────────────────────── */
.health-dot { display: inline-block; width: 10px; height: 10px; border-radius: var(--r-full); margin-right: .5rem; vertical-align: middle; }
.health-ok { background: var(--c-success); box-shadow: 0 0 6px rgba(16,185,129,.4); }
.health-err { background: var(--c-danger); box-shadow: 0 0 6px rgba(239,68,68,.4); }
.health-warn { background: var(--c-warning); box-shadow: 0 0 6px rgba(245,158,11,.4); }
.health-unknown { background: var(--c-text-tertiary); }

/* ── Action buttons (admin) ─────────────────────────────────── */
.actions { display: flex; gap: var(--sp-sm); }
.action-btn { padding: .3rem .6rem; border: none; border-radius: var(--r-sm); font-size: var(--text-xs); cursor: pointer; font-weight: 600; }
.approve-btn, .toggle-btn { background: var(--c-success); color: #fff; }
.reject-btn, .delete-btn { background: var(--c-danger); color: #fff; }

/* ── Form layout ────────────────────────────────────────────── */
.form-group { margin-bottom: var(--sp-lg); }
.form-selector { display: flex; align-items: center; gap: var(--sp-md); margin-bottom: var(--sp-lg); }
.form-selector select {
  padding: .6rem;
  border: 1.5px solid var(--c-border);
  border-radius: var(--r-md);
  font-size: var(--text-base);
  font-family: inherit;
}
.form-selector button {
  padding: .6rem 1.2rem;
  border: none;
  border-radius: var(--r-full);
  background: var(--gradient-primary);
  color: #fff;
  cursor: pointer;
  font-weight: 600;
  box-shadow: var(--shadow-colored);
}
.form-selector button:hover { background: var(--gradient-primary-hover); }
.form-section { margin-bottom: var(--sp-lg); }
.form-section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--sp-md); }
.form-section-header h2 { margin: 0; }
.form-section-header a { text-decoration: none; }
.section-title {
  font-size: var(--text-xl);
  color: var(--c-primary-dark);
  margin-bottom: var(--sp-md);
  padding-bottom: var(--sp-sm);
  border-bottom: 2px solid var(--c-primary-50);
  font-weight: 700;
}

/* ── Pagination — Pill style ────────────────────────────────── */
.pagination { display: flex; justify-content: center; align-items: center; gap: var(--sp-sm); margin-top: var(--sp-lg); font-size: var(--text-sm); }
.pagination a, .pagination span {
  padding: .4rem .85rem;
  border: 1px solid var(--c-border);
  border-radius: var(--r-full);
  text-decoration: none;
  color: var(--c-primary);
  font-weight: 500;
  transition: all var(--duration-fast) var(--ease-out);
}
.pagination a:hover { background: var(--c-primary-50); border-color: var(--c-primary-light); text-decoration: none; }
.pagination .current {
  background: var(--gradient-primary);
  color: #fff;
  border-color: transparent;
  font-weight: 700;
  box-shadow: var(--shadow-colored);
}
.pagination .disabled { color: var(--c-text-tertiary); border-color: var(--c-border-light); pointer-events: none; }

/* ── Info / Warn boxes — Modern callout ─────────────────────── */
.info-box {
  background: var(--c-info-50);
  border-left: 4px solid var(--c-primary);
  padding: 1rem 1.25rem;
  margin-bottom: var(--sp-md);
  border-radius: 0 var(--r-md) var(--r-md) 0;
  box-shadow: var(--shadow-xs);
}
.info-box p { margin-bottom: .25rem; }
.warn-box {
  background: var(--c-warning-50);
  border-left: 4px solid var(--c-warning);
  padding: 1rem 1.25rem;
  margin-bottom: var(--sp-md);
  border-radius: 0 var(--r-md) var(--r-md) 0;
  box-shadow: var(--shadow-xs);
}
.warn-box p { margin-bottom: .25rem; }
.success-box {
  background: var(--c-success-50);
  border-left: 4px solid var(--c-success);
  padding: 1rem 1.25rem;
  margin-bottom: var(--sp-md);
  border-radius: 0 var(--r-md) var(--r-md) 0;
  box-shadow: var(--shadow-xs);
}
.success-box p { margin-bottom: .25rem; }

/* ── Details / Summary (HTML5 — sans JS) ────────────────────── */
details { margin-bottom: var(--sp-md); }
details > summary {
  cursor: pointer;
  font-weight: 600;
  color: var(--c-primary-dark);
  padding: .7rem 1rem;
  background: var(--c-primary-50);
  border: 1px solid var(--c-border-light);
  border-radius: var(--r-md);
  list-style: none;
  display: flex;
  align-items: center;
  gap: .5rem;
  transition: background var(--duration-fast) var(--ease-out);
}
details > summary::before {
  content: "";
  display: inline-block;
  width: 0; height: 0;
  border-left: 6px solid var(--c-primary);
  border-top: 5px solid transparent;
  border-bottom: 5px solid transparent;
  transition: transform var(--duration-fast) var(--ease-out);
}
details[open] > summary::before { transform: rotate(90deg); }
details > summary:hover { background: #DDD6FE; }
details > summary:focus-visible { outline: 2px solid var(--c-primary); outline-offset: 2px; }
details > div, details > .card { margin-top: var(--sp-sm); }

/* ── Responsive ─────────────────────────────────────────────── */
@media (max-width: 768px) {
  .grid-2 { grid-template-columns: 1fr; }
  .grid-3 { grid-template-columns: 1fr 1fr; }
  .bandeau { padding: .5rem 1rem; gap: .35rem; }
  .bandeau a { font-size: var(--text-xs); padding: .3rem .5rem; }
  table { font-size: var(--text-xs); display: block; overflow-x: auto; -webkit-overflow-scrolling: touch; }
  th, td { padding: .4rem .5rem; }
  .container { padding: 0 .75rem 1.5rem; }
  .card { padding: var(--sp-md); }
  h1 { font-size: var(--text-xl); }
  .stats { gap: var(--sp-sm); }
  .stat { min-width: 90px; padding: .75rem; }
  .stat strong { font-size: var(--text-2xl); }
  .form-actions { flex-direction: column; }
  .form-actions .btn { width: 100%; text-align: center; justify-content: center; }
  .toolbar { flex-direction: column; align-items: stretch; }
  .filtres { flex-wrap: wrap; }
}
@media (max-width: 600px) {
  .grid-2 { grid-template-columns: 1fr; }
  .grid-3 { grid-template-columns: 1fr; }
  .bandeau { flex-direction: column; align-items: flex-start; }
  .bandeau .nav-main { gap: .2rem; flex-wrap: wrap; }
  .bandeau .nav-admin { gap: .2rem; flex-wrap: wrap; }
  .bandeau .nav-user { margin-left: 0; }
  h1 { font-size: var(--text-lg); }
  h2 { font-size: var(--text-base); }
  .card { padding: .85rem; }
  .btn { padding: .5rem .85rem; font-size: var(--text-xs); }
  .form-selector { flex-direction: column; align-items: stretch; }
  .form-selector select, .form-selector button { width: 100%; }
  .pagination { gap: .3rem; }
  .pagination a, .pagination span { padding: .3rem .55rem; font-size: var(--text-xs); }
  .timeline { flex-direction: column; align-items: stretch; }
  .step-item { max-width: 100%; flex-direction: row; gap: .5rem; text-align: left; }
  .step-item:not(:last-child)::after { display: none; }
  .stat-card { padding: var(--sp-md); }
  .stat-card .stat-value { font-size: 1.75rem; }
  input[type="text"], input[type="date"], input[type="number"], input[type="password"], input[type="email"], select, textarea {
    font-size: 16px; /* Empêche le zoom automatique sur iOS */
  }
  .breadcrumb { font-size: var(--text-xs); }
}

/* ── Skip link (RGAA) ─────────────────────────────────────── */
.skip-link {
  position: absolute;
  left: -9999px;
  top: 0;
  background: var(--c-primary);
  color: #fff;
  padding: .5rem 1.25rem;
  z-index: 9999;
  font-size: var(--text-sm);
  font-weight: 600;
  border-radius: 0 0 var(--r-md) 0;
}
.skip-link:focus { left: 0; }

/* ── Focus visible (RGAA) ──────────────────────────────────── */
:focus-visible { outline: 3px solid var(--c-primary); outline-offset: 2px; }
a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible {
  outline: 3px solid var(--c-primary);
  outline-offset: 2px;
}

/* ── Visually hidden (screen readers only) ─────────────────── */
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }

/* ── Error pages — Centered soft cards ──────────────────────── */
.error-page { display: flex; min-height: calc(100vh - 120px); align-items: center; justify-content: center; padding: var(--sp-xl) var(--sp-md); }
.error-card {
  background: var(--c-surface-glass);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
  border: 1px solid var(--c-border-light);
  border-radius: var(--r-xl);
  padding: 3rem 2.5rem;
  max-width: 560px;
  width: 100%;
  text-align: center;
  box-shadow: var(--shadow-xl);
}
.error-card .error-code { font-size: 5rem; font-weight: 900; line-height: 1; margin-bottom: .25rem; letter-spacing: -3px; }
.error-card .error-code.code-403 { color: var(--c-danger); }
.error-card .error-code.code-404 { color: var(--c-primary); }
.error-card .error-code.code-400 { color: var(--c-warning); }
.error-card .error-code.code-401 { color: var(--c-primary); }
.error-card .error-code.code-500 { color: var(--c-danger); }
.error-card .error-illustration { margin-bottom: 1.25rem; }
.error-card .error-illustration svg { width: 100px; height: 100px; }
.error-card h1 { font-size: var(--text-xl); color: var(--c-text); margin-bottom: .75rem; border: none; padding: 0; }
.error-card .error-message { color: var(--c-text-secondary); font-size: var(--text-base); line-height: 1.6; margin-bottom: 1.25rem; }
.error-card .error-hint {
  font-size: var(--text-sm);
  color: var(--c-text-secondary);
  background: var(--c-bg-warm);
  border: 1px solid var(--c-border-light);
  border-radius: var(--r-md);
  padding: 1rem 1.25rem;
  margin-bottom: 1.5rem;
  text-align: left;
  line-height: 1.55;
}
.error-card .error-hint strong { color: var(--c-text); display: block; margin-bottom: .35rem; }
.error-card .error-actions { display: flex; gap: var(--sp-sm); justify-content: center; flex-wrap: wrap; }
.error-card .error-stamp { margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--c-border-light); font-size: var(--text-xs); color: var(--c-text-tertiary); }

/* ── Breadcrumb ─────────────────────────────────────────────── */
.breadcrumb {
  font-size: var(--text-sm);
  padding: .6rem 0;
  color: var(--c-text-tertiary);
}
.breadcrumb a {
  color: var(--c-primary);
  text-decoration: none;
  transition: color var(--duration-fast);
}
.breadcrumb a:hover { color: var(--c-primary-dark); text-decoration: underline; }
.breadcrumb .separator { color: var(--c-border); margin: 0 .4rem; }
.breadcrumb .current { color: var(--c-text-secondary); font-weight: 500; }

/* ── Footer ─────────────────────────────────────────────────── */
footer {
  text-align: center;
  padding: var(--sp-lg) var(--sp-md);
  font-size: var(--text-xs);
  color: var(--c-text-tertiary);
  background: var(--c-bg-warm);
  border-top: 1px solid var(--c-border-light);
  margin-top: var(--sp-xl);
}
footer a {
  color: var(--c-primary);
  text-decoration: none;
  font-weight: 600;
  transition: color var(--duration-fast);
}
footer a:hover { color: var(--c-primary-dark); text-decoration: underline; }

/* ── Print ──────────────────────────────────────────────────── */
@media print {
  .bandeau, footer, .btn, .form-actions, .actions-bar, .action-btn, .card-actions,
  .toolbar, .filtres, .form-selector { display: none !important; }
  body { background: #fff !important; color: #000 !important; }
  .card, .section-card { border: none !important; box-shadow: none !important; }
  .container { max-width: 100% !important; padding: 0 !important; margin: 0 !important; }
  a[href]::after { content: " (" attr(href) ")"; font-size: .85em; color: #555; }
  a[href^="#"]::after, a[href^="javascript"]::after { content: ""; }
  table, th, td { border: 1px solid #999 !important; }
  thead { background: #eee !important; color: #000 !important; }
  th { background: #eee !important; color: #000 !important; }
  .card, .section-card { page-break-inside: avoid; }
}

/* ── Entrance animation (CSS only — @keyframes) ────────────── */
@keyframes fadeSlideIn {
  from { opacity: 0; transform: translateY(12px); }
  to { opacity: 1; transform: translateY(0); }
}
main > .card, main > .stats, main > .hero, main > h1, main > h2,
main > .quick-stats, main > .form-cards, main > .nav-tiles,
main > .empty-state, main > .sub-card, main > .validation-card {
  animation: fadeSlideIn .4s var(--ease-out) both;
}
main > .card:nth-child(2), main > .stats:nth-child(2),
main > .sub-card:nth-child(2), main > .validation-card:nth-child(2) { animation-delay: .05s; }
main > .card:nth-child(3), main > .sub-card:nth-child(3), main > .validation-card:nth-child(3) { animation-delay: .1s; }
main > .card:nth-child(4), main > .sub-card:nth-child(4) { animation-delay: .15s; }

/* ── Subtle pulse for pending items (CSS only) ─────────────── */
@keyframes softPulse {
  0%, 100% { opacity: 1; }
  50% { opacity: .7; }
}
.badge-warn { animation: softPulse 2.5s ease-in-out infinite; }
</style>
