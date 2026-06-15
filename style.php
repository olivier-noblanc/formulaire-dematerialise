<?php
// style.php — Design System 2026 "Institutionnel" v3 — Sidebar Layout
// À inclure via require_once __DIR__ . '/style.php'; dans le <head>
// Zéro JavaScript — Pure CSS + HTML5
// Layout : Sidebar blanche 196px + Contenu principal
// Palette bleu #000091 / rouge #E1000F
?>
<style>
/* ═══════════════════════════════════════════════════════════════
   DESIGN SYSTEM 2026 — "Institutionnel" v3
   Sidebar layout · Palette bleu profond
   Zéro CDN · Zéro JS · Zéro dépendance externe
   ═══════════════════════════════════════════════════════════════ */

/* ── Custom Properties (Design Tokens) ──────────────────────── */
:root {
  /* Primary — Bleu républicain */
  --c-primary: #000091;
  --c-primary-dark: #00006F;
  --c-primary-darker: #000050;
  --c-primary-light: #1212FF;
  --c-primary-lighter: #5656FF;
  --c-primary-50: #F5F5FE;
  --c-primary-100: #E3E3FE;
  --c-primary-200: #C6C6FF;

  /* Tricolore républicain */
  --c-rouge: #E1000F;
  --c-rouge-dark: #C0000D;
  --c-rouge-light: #FF1D2E;
  --c-rouge-50: #FFF0F0;
  --c-rouge-100: #FFD7D7;

  /* Accent — Bleu républicain */
  --c-accent: #000091;
  --c-accent-dark: #00006F;
  --c-accent-light: #1212FF;

  /* Semantic */
  --c-success: #10B981;
  --c-success-dark: #065F46;
  --c-success-50: #D1FAE5;
  --c-success-100: #A7F3D0;
  --c-warning: #D97706;
  --c-warning-dark: #78350F;
  --c-warning-50: #FEF3C7;
  --c-warning-100: #FDE68A;
  --c-danger: #E1000F;
  --c-danger-dark: #C0000D;
  --c-danger-50: #FFF0F0;
  --c-danger-100: #FFD7D7;
  --c-info: #000091;
  --c-info-50: #F5F5FE;
  --c-info-100: #E3E3FE;

  /* Neutrals — Bleu-gris */
  --c-bg: #EBF0FA;
  --c-bg-warm: #F5F9FF;
  --c-surface: #FFFFFF;
  --c-surface-elevated: #FFFFFF;
  --c-surface-glass: rgba(255, 255, 255, 0.95);
  --c-border: #C8D8ED;
  --c-border-light: #E0EAF5;
  --c-border-subtle: #EBF0FA;
  --c-text: #161616;
  --c-text-secondary: #3A3A3A;
  --c-text-tertiary: #666666;
  --c-text-inverse: #FFFFFF;

  /* Sidebar specific */
  --c-sidebar-bg: #FFFFFF;
  --c-sidebar-hover: #F5F5FE;
  --c-sidebar-active: #000091;
  --c-sidebar-text: #3A3A3A;
  --c-sidebar-section: #666666;

  /* Shadows */
  --shadow-xs: 0 1px 2px rgba(0,0,0,.04);
  --shadow-sm: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
  --shadow-md: 0 4px 6px -1px rgba(0,0,0,.07), 0 2px 4px -2px rgba(0,0,0,.04);
  --shadow-lg: 0 10px 15px -3px rgba(0,0,0,.08), 0 4px 6px -4px rgba(0,0,0,.03);
  --shadow-xl: 0 20px 25px -5px rgba(0,0,0,.1), 0 8px 10px -6px rgba(0,0,0,.03);
  --shadow-2xl: 0 25px 50px -12px rgba(0,0,0,.12);
  --shadow-glow: 0 0 20px rgba(0,0,145,.1);
  --shadow-colored: 0 4px 14px rgba(0,0,145,.14);
  --shadow-inner: inset 0 2px 4px rgba(0,0,0,.04);

  /* Radius */
  --r-xs: 4px;
  --r-sm: 5px;
  --r-md: 7px;
  --r-lg: 9px;
  --r-xl: 14px;
  --r-2xl: 20px;
  --r-full: 9999px;

  /* Spacing */
  --sp-1: .25rem;
  --sp-2: .5rem;
  --sp-3: .75rem;
  --sp-4: 1rem;
  --sp-5: 1.25rem;
  --sp-6: 1.5rem;
  --sp-8: 2rem;
  --sp-10: 2.5rem;
  --sp-12: 3rem;
  --sp-16: 4rem;

  /* Typography — Marianne (DSFR) */
  --font-sans: "Marianne", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  --font-mono: "SFMono-Regular", Menlo, Consolas, "Liberation Mono", monospace;
  --text-xs: .75rem;
  --text-sm: .8125rem;
  --text-base: .9375rem;
  --text-lg: 1.125rem;
  --text-xl: 1.375rem;
  --text-2xl: 1.75rem;
  --text-3xl: 2.25rem;
  --text-4xl: 3rem;

  /* Transitions */
  --ease-out: cubic-bezier(.16, 1, .3, 1);
  --ease-spring: cubic-bezier(.34, 1.56, .64, 1);
  --duration-fast: .15s;
  --duration-normal: .25s;
  --duration-slow: .4s;

  /* Gradients */
  --gradient-primary: linear-gradient(135deg, #000091 0%, #1212FF 100%);
  --gradient-primary-hover: linear-gradient(135deg, #00006F 0%, #000091 100%);
  --gradient-success: linear-gradient(135deg, #10B981 0%, #059669 100%);
  --gradient-surface: linear-gradient(180deg, rgba(255,255,255,.95) 0%, rgba(255,255,255,.7) 100%);
  --gradient-mesh-hero: linear-gradient(135deg, #000091 0%, #1E40AF 30%, #3B82F6 70%, #000091 100%);

  /* Layout */
  --sidebar-width: 196px;
  --topbar-height: 50px;
}

/* ── Reset & Base ────────────────────────────────────────────── */
.hidden { display: none !important; }
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html {
  scroll-behavior: smooth;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
  text-rendering: optimizeLegibility;
}
body {
  font-family: var(--font-sans);
  background: var(--c-bg);
  color: var(--c-text-secondary);
  font-size: var(--text-base);
  line-height: 1.6;
  min-height: 100vh;
}

/* ═══════════════════════════════════════════════════════════════
   APP LAYOUT — Sidebar + Main
   ═══════════════════════════════════════════════════════════════ */
.app-layout {
  display: flex;
  min-height: 100vh;
}

/* ── Sidebar ────────────────────────────────────────────────── */
.sidebar {
  width: var(--sidebar-width);
  background: var(--c-sidebar-bg);
  border-right: 1px solid var(--c-border);
  display: flex;
  flex-direction: column;
  position: fixed;
  top: 0; left: 0; bottom: 0;
  z-index: 100;
  overflow-y: auto;
  overflow-x: hidden;
}

/* Logo / Brand area */
.sidebar-brand {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 16px 14px;
  text-decoration: none;
  color: var(--c-text);
  border-bottom: 1px solid var(--c-border-light);
}
.sidebar-brand:hover { text-decoration: none; }

.sidebar-logo-mark {
  width: 30px; height: 30px;
  background: var(--c-primary);
  border-radius: 7px;
  display: flex; align-items: center; justify-content: center;
  color: #fff;
  font-weight: 700;
  font-size: 14px;
  flex-shrink: 0;
}

.sidebar-brand-text {
  font-weight: 700;
  font-size: 14px;
  color: var(--c-text);
  letter-spacing: -.02em;
  line-height: 1.2;
}

/* Navigation sections */
.sidebar-nav {
  flex: 1;
  padding: 10px 8px;
}
.sidebar-section-title {
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  color: var(--c-sidebar-section);
  letter-spacing: .07em;
  padding: 12px 9px 4px;
  line-height: 1;
}
.sidebar-section-title:first-child { padding-top: 4px; }

/* Nav items */
.sidebar-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 9px;
  border-radius: 7px;
  text-decoration: none;
  color: var(--c-sidebar-text);
  font-size: 12px;
  font-weight: 500;
  transition: background var(--duration-fast) var(--ease-out),
              color var(--duration-fast) var(--ease-out);
  margin-bottom: 1px;
  line-height: 1.3;
}
.sidebar-item:hover {
  background: var(--c-sidebar-hover);
  color: var(--c-text);
  text-decoration: none;
}
.sidebar-item.active {
  background: var(--c-sidebar-active);
  color: #fff;
  font-weight: 600;
}
.sidebar-item.active:hover {
  background: var(--c-primary-dark);
  color: #fff;
}
.sidebar-item:focus-visible {
  outline: 2px solid var(--c-primary);
  outline-offset: 1px;
}
.sidebar-item-icon {
  font-size: 14px;
  width: 18px;
  text-align: center;
  flex-shrink: 0;
}
.sidebar-item-label { flex: 1; }

/* Badge count in sidebar */
.sidebar-badge {
  font-size: 10px;
  font-weight: 700;
  padding: 1px 6px;
  border-radius: 100px;
  line-height: 1.4;
}
/* Badge when item is NOT active */
.sidebar-item:not(.active) .sidebar-badge {
  background: var(--c-primary-100);
  color: var(--c-primary);
}
/* Badge when item IS active */
.sidebar-item.active .sidebar-badge {
  background: rgba(255,255,255,.2);
  color: #fff;
}

/* Sidebar user card */
.sidebar-user {
  padding: 10px 10px 14px;
  border-top: 1px solid var(--c-border-light);
}
.sidebar-user-card {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--c-primary-50);
  border: 1px solid var(--c-border);
  border-radius: 7px;
  padding: 8px 10px;
}
.sidebar-user-avatar {
  width: 26px; height: 26px;
  border-radius: 50%;
  background: var(--c-primary);
  color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: 11px;
  font-weight: 700;
  flex-shrink: 0;
}
.sidebar-user-email {
  font-size: 10px;
  color: var(--c-text-tertiary);
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 110px;
}

/* ── Main area ──────────────────────────────────────────────── */
.main-area {
  flex: 1;
  margin-left: var(--sidebar-width);
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

/* ── Topbar ─────────────────────────────────────────────────── */
.topbar {
  height: var(--topbar-height);
  background: var(--c-surface);
  border-bottom: 1px solid var(--c-border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 20px;
  position: sticky;
  top: 0;
  z-index: 50;
}
.topbar-left {
  display: flex;
  align-items: center;
  gap: 8px;
}
.topbar-breadcrumb {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
}
.topbar-breadcrumb a {
  color: var(--c-text-tertiary);
  font-weight: 500;
  text-decoration: none;
  transition: color var(--duration-fast);
}
.topbar-breadcrumb a:hover { color: var(--c-primary); text-decoration: underline; }
.topbar-breadcrumb .sep {
  color: var(--c-border);
  font-size: 10px;
}
.topbar-breadcrumb .current {
  color: var(--c-text);
  font-weight: 600;
}
.topbar-right {
  display: flex;
  align-items: center;
  gap: 8px;
}
.topbar-icon-btn {
  width: 30px; height: 30px;
  border: 1px solid var(--c-border);
  border-radius: 7px;
  background: var(--c-primary-50);
  color: var(--c-sidebar-text);
  display: flex; align-items: center; justify-content: center;
  text-decoration: none;
  font-size: 13px;
  cursor: pointer;
  transition: background var(--duration-fast), border-color var(--duration-fast);
  position: relative;
}
.topbar-icon-btn:hover {
  background: var(--c-primary-100);
  border-color: var(--c-primary-lighter);
  text-decoration: none;
}
.topbar-notif-dot {
  position: absolute;
  top: 4px; right: 4px;
  width: 7px; height: 7px;
  background: var(--c-rouge);
  border-radius: 50%;
  border: 1.5px solid var(--c-surface);
}
.topbar-cta {
  background: var(--c-primary);
  color: #fff;
  border: none;
  border-radius: 7px;
  padding: 6px 12px;
  font-size: 12px;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  transition: background var(--duration-fast);
}
.topbar-cta:hover { background: var(--c-primary-dark); color: #fff; text-decoration: none; }

/* ── Content area ───────────────────────────────────────────── */
.content {
  flex: 1;
  padding: 20px 24px 32px;
}

/* ── Container ──────────────────────────────────────────────── */
.container {
  max-width: 100%;
  margin: 0;
  padding: 0;
}

/* ── Typography ─────────────────────────────────────────────── */
h1 {
  font-size: var(--text-2xl);
  font-weight: 700;
  color: var(--c-text);
  margin-bottom: 4px;
  letter-spacing: -.02em;
  line-height: 1.25;
}
h2 {
  font-size: var(--text-xl);
  font-weight: 700;
  color: var(--c-text);
  border-bottom: none;
  padding-bottom: 0;
  margin-bottom: var(--sp-4);
  letter-spacing: -.015em;
  line-height: 1.3;
}
h3 {
  font-size: var(--text-lg);
  font-weight: 600;
  color: var(--c-text);
  margin-bottom: var(--sp-2);
  line-height: 1.35;
}
p { margin-bottom: var(--sp-3); }

/* ── Cards ──────────────────────────────────────────────────── */
.card {
  background: var(--c-surface);
  border: 1px solid var(--c-border);
  border-radius: var(--r-lg);
  padding: var(--sp-5);
  margin-bottom: var(--sp-5);
  box-shadow: var(--shadow-xs);
  transition: box-shadow var(--duration-normal) var(--ease-out),
              border-color var(--duration-normal) var(--ease-out);
}
.card:hover {
  box-shadow: var(--shadow-sm);
  border-color: var(--c-border);
}
.card h2 {
  font-size: 12px;
  font-weight: 700;
  color: var(--c-text);
  border-bottom: 1px solid var(--c-border-light);
  padding-bottom: var(--sp-2);
  margin-bottom: var(--sp-4);
  text-transform: uppercase;
  letter-spacing: .03em;
}

/* ── Buttons ────────────────────────────────────────────────── */
.btn {
  padding: 6px 12px;
  border: 1px solid var(--c-border);
  border-radius: 7px;
  font-size: 11px;
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  transition: all var(--duration-fast) var(--ease-out);
  line-height: 1.4;
  background: var(--c-surface);
  color: var(--c-sidebar-text);
}
.btn:active { transform: scale(.97); }
.btn-primary {
  background: var(--c-primary);
  color: #fff;
  border-color: var(--c-primary);
  box-shadow: var(--shadow-xs);
}
.btn-primary:hover {
  background: var(--c-primary-dark);
  border-color: var(--c-primary-dark);
  color: #fff;
  text-decoration: none;
}
.btn-secondary {
  background: var(--c-surface);
  color: var(--c-sidebar-text);
  border: 1px solid var(--c-border);
}
.btn-secondary:hover {
  background: var(--c-primary-50);
  border-color: var(--c-primary-lighter);
  color: var(--c-text);
  text-decoration: none;
}
.btn-danger {
  background: #fff;
  color: var(--c-danger-dark);
  border: 1px solid var(--c-danger-100);
}
.btn-danger:hover {
  background: var(--c-danger-50);
  border-color: var(--c-danger);
  color: var(--c-danger-dark);
  text-decoration: none;
}
.btn-test {
  background: var(--c-success);
  color: #fff;
  border-color: var(--c-success);
}
.btn-test:hover { background: var(--c-success-dark); color: #fff; border-color: var(--c-success-dark); }

/* ── Messages ───────────────────────────────────────────────── */
.msg-success {
  background: var(--c-success-50);
  border: 1px solid var(--c-success);
  border-left: 3px solid var(--c-success);
  border-radius: var(--r-md);
  padding: 10px 14px;
  margin-bottom: var(--sp-4);
  color: var(--c-success-dark);
  font-weight: 500;
  font-size: 12px;
}
.msg-error {
  background: var(--c-danger-50);
  border: 1px solid var(--c-danger);
  border-left: 3px solid var(--c-danger);
  border-radius: var(--r-md);
  padding: 10px 14px;
  margin-bottom: var(--sp-4);
  color: var(--c-danger-dark);
  font-weight: 500;
  font-size: 12px;
}
.msg-info {
  background: var(--c-info-50);
  border: 1px solid var(--c-border);
  border-left: 3px solid var(--c-primary);
  border-radius: var(--r-md);
  padding: 10px 14px;
  margin-bottom: var(--sp-4);
  color: var(--c-primary-dark);
  font-weight: 500;
  font-size: 12px;
}
.errors {
  background: var(--c-danger-50);
  border: 1px solid var(--c-danger);
  border-radius: var(--r-md);
  padding: var(--sp-4);
  margin-bottom: var(--sp-5);
  color: var(--c-danger-dark);
  font-size: 12px;
}
.success {
  background: var(--c-success-50);
  border: 1px solid var(--c-success);
  border-radius: var(--r-lg);
  padding: var(--sp-8);
  color: var(--c-success-dark);
  text-align: center;
  box-shadow: var(--shadow-xs);
}
.success strong { display: block; font-size: var(--text-xl); margin-bottom: var(--sp-2); }

/* ── Form fields ────────────────────────────────────────────── */
.field { display: flex; flex-direction: column; gap: 3px; margin-bottom: 12px; }
label { font-size: 11px; font-weight: 600; color: var(--c-text-secondary); }
.hint { font-size: 10px; color: var(--c-text-tertiary); font-weight: 400; }
.req { color: var(--c-rouge); margin-left: 2px; }
input[type="text"], input[type="date"], input[type="number"], input[type="password"], input[type="email"], input[type="tel"], input[type="url"], select, textarea {
    width: 100%;
    padding: 7px 10px;
    border: 1px solid var(--c-border);
    border-radius: var(--r-md);
    font-size: 12px;
    font-family: inherit;
    background: var(--c-surface);
    color: var(--c-text);
    transition: border-color var(--duration-fast) var(--ease-out),
                box-shadow var(--duration-fast) var(--ease-out);
}
input:focus, select:focus, textarea:focus {
  outline: none;
  border-color: var(--c-primary);
  box-shadow: 0 0 0 2px rgba(0,0,145,.1);
}
textarea { resize: vertical; min-height: 80px; }
.field-error { border-color: var(--c-danger) !important; background: var(--c-danger-50); }
.error-hint { color: var(--c-danger); font-size: 10px; font-weight: 500; }

/* ── Checkboxes ─────────────────────────────────────────────── */
.checkbox-label { display: flex; align-items: center; gap: 6px; font-size: 12px; }
.checkbox-label input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--c-primary); }
.checkboxes { display: flex; flex-direction: column; gap: 5px; margin-top: 3px; }
.checkbox-item { display: flex; align-items: center; gap: 6px; font-size: 12px; }
input[type="checkbox"] { width: 16px; height: 16px; accent-color: var(--c-primary); cursor: pointer; flex-shrink: 0; }

/* ── Tables ─────────────────────────────────────────────────── */
table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  font-size: 12px;
  background: var(--c-surface);
  border-radius: var(--r-lg);
  overflow: hidden;
  box-shadow: var(--shadow-xs);
  border: 1px solid var(--c-border);
}
thead { background: var(--c-primary-50); }
thead th {
  padding: 8px 12px;
  text-align: left;
  font-weight: 700;
  color: var(--c-text-tertiary);
  white-space: nowrap;
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: .06em;
  border-bottom: 1px solid var(--c-border);
}
th, td { padding: 7px 12px; text-align: left; border-bottom: 1px solid var(--c-border-light); }
th { font-weight: 600; }
tbody td { vertical-align: middle; font-weight: 500; color: var(--c-text-secondary); }
tbody tr:last-child td { border-bottom: none; }
tbody tr { transition: background var(--duration-fast) var(--ease-out); }
tbody tr:nth-child(even) { background: var(--c-bg-warm); }
tbody tr:hover { background: #F5F9FF; }

/* ── Badges — Pill with dot ─────────────────────────────────── */
.badge {
  display: inline-flex;
  align-items: center;
  gap: 0;
  padding: 2px 8px 2px 12px;
  border-radius: 100px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .01em;
  position: relative;
}
.badge::before {
  content: '';
  position: absolute;
  left: 6px;
  top: 50%;
  transform: translateY(-50%);
  width: 4px;
  height: 4px;
  border-radius: 50%;
}
.badge-ok, .badge-valide { background: var(--c-success-50); color: var(--c-success-dark); }
.badge-ok::before, .badge-valide::before { background: #10B981; }
.badge-warn, .badge-en-cours { background: var(--c-warning-50); color: var(--c-warning-dark); }
.badge-warn::before, .badge-en-cours::before { background: #D97706; }
.badge-err, .badge-refuse { background: var(--c-danger-50); color: var(--c-danger-dark); }
.badge-err::before, .badge-refuse::before { background: #E1000F; }
.badge-info { background: var(--c-info-50); color: var(--c-primary); }
.badge-info::before { background: var(--c-primary); }

/* ── Stat cards ─────────────────────────────────────────────── */
.stats {
  display: flex;
  gap: var(--sp-4);
  margin-bottom: var(--sp-5);
  flex-wrap: wrap;
}
.stat {
  background: var(--c-surface);
  border: 1px solid var(--c-border);
  border-radius: var(--r-lg);
  padding: 12px 16px;
  min-width: 110px;
  font-size: 12px;
  box-shadow: var(--shadow-xs);
  position: relative;
  overflow: hidden;
  transition: transform var(--duration-fast) var(--ease-out),
              box-shadow var(--duration-fast) var(--ease-out);
}
.stat:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.stat::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 2px;
  background: var(--gradient-primary);
}
.stat strong {
  display: block;
  font-size: 22px;
  color: var(--c-text);
  font-weight: 700;
  letter-spacing: -.02em;
  font-variant-numeric: tabular-nums;
}
.stat span {
  color: var(--c-text-tertiary);
  font-size: 10px;
  font-weight: 500;
  text-transform: uppercase;
  letter-spacing: .05em;
}
.stat.en-cours strong, .stat.warning strong { color: var(--c-warning-dark); }
.stat.en-cours::before, .stat.warning::before { background: var(--c-warning); }
.stat.valide strong, .stat.success strong { color: var(--c-success-dark); }
.stat.valide::before, .stat.success::before { background: var(--c-success); }
.stat.refuse strong, .stat.danger strong { color: var(--c-danger-dark); }
.stat.refuse::before, .stat.danger::before { background: var(--c-danger); }

/* ── Stat cards (monitoring) ────────────────────────────────── */
.stat-card {
  background: var(--c-surface);
  border: 1px solid var(--c-border);
  border-radius: var(--r-lg);
  padding: var(--sp-5);
  text-align: center;
  box-shadow: var(--shadow-xs);
  position: relative;
  overflow: hidden;
}
.stat-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 2px;
  background: var(--gradient-primary);
}
.stat-card .stat-value {
  font-size: 22px;
  font-weight: 700;
  color: var(--c-text);
  letter-spacing: -.02em;
  font-variant-numeric: tabular-nums;
}
.stat-card .stat-label {
  font-size: 11px;
  color: var(--c-text-tertiary);
  margin-top: 2px;
  font-weight: 500;
}
.stat-card.success .stat-value { color: var(--c-success-dark); }
.stat-card.success::before { background: var(--gradient-success); }
.stat-card.danger .stat-value { color: var(--c-danger-dark); }
.stat-card.danger::before { background: linear-gradient(135deg, #E1000F, #C0000D); }
.stat-card.warning .stat-value { color: var(--c-warning-dark); }
.stat-card.warning::before { background: linear-gradient(135deg, #F59E0B, #D97706); }

/* ── Empty state ────────────────────────────────────────────── */
.empty-state {
  text-align: center;
  padding: var(--sp-12) var(--sp-4);
  color: var(--c-text-tertiary);
}
.empty-state .empty-icon { font-size: 2.5rem; margin-bottom: var(--sp-4); opacity: .4; }
.empty-state p { margin-bottom: var(--sp-4); font-size: 12px; }

/* ── Toolbar / Filters ─────────────────────────────────────── */
.toolbar { display: flex; gap: var(--sp-3); align-items: center; margin-bottom: var(--sp-5); flex-wrap: wrap; }
select.form-filter {
  padding: 5px 8px;
  border: 1px solid var(--c-border);
  border-radius: var(--r-md);
  font-size: 11px;
  font-family: inherit;
  background: var(--c-surface);
  color: var(--c-text);
}
.form-actions { display: flex; gap: var(--sp-2); margin-top: var(--sp-3); }

/* ── Grids ──────────────────────────────────────────────────── */
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: var(--sp-4); }
.grid-3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: var(--sp-4); margin-bottom: var(--sp-5); }

/* ── Timeline / Workflow ────────────────────────────────────── */
.timeline {
  display: flex;
  align-items: flex-start;
  margin-top: var(--sp-4);
  padding-top: var(--sp-4);
  border-top: 1px solid var(--c-border-light);
  overflow-x: auto;
}
.step-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  min-width: 80px;
  max-width: 140px;
  flex: 1;
  text-align: center;
  position: relative;
  padding: 0 4px;
}
.step-item:not(:last-child)::after {
  content: '';
  position: absolute;
  top: 12px; right: -50%;
  width: 100%; height: 2px;
  z-index: 0;
  border-radius: 1px;
}
.step-item.step-validated:not(:last-child)::after { background: #10B981; }
.step-item.step-current:not(:last-child)::after {
  background: linear-gradient(90deg, var(--c-primary), var(--c-primary-lighter));
}
.step-item.step-upcoming:not(:last-child)::after { background: var(--c-border); }
.step-icon {
  width: 24px; height: 24px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 10px; font-weight: 700; z-index: 1; margin-bottom: var(--sp-2);
}
.step-validated .step-icon {
  background: #10B981; color: #fff;
  box-shadow: 0 2px 6px rgba(16,185,129,.25);
}
.step-current .step-icon {
  background: var(--c-primary); color: #fff;
  box-shadow: 0 0 0 3px rgba(0,0,145,.15), 0 2px 6px rgba(0,0,145,.2);
}
.step-upcoming .step-icon {
  background: var(--c-primary-50); color: var(--c-sidebar-section);
  border: 1px solid var(--c-border);
}
.step-label { font-size: 9px; font-weight: 500; color: var(--c-text-tertiary); margin-bottom: 1px; line-height: 1.3; }
.step-validated .step-label { color: #0D6B40; font-weight: 600; }
.step-current .step-label { color: var(--c-primary); font-weight: 700; }
.step-detail { font-size: 9px; color: var(--c-text-tertiary); line-height: 1.3; }

/* ── Health dots ────────────────────────────────────────────── */
.health-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 4px; vertical-align: middle; }
.health-ok { background: var(--c-success); box-shadow: 0 0 4px rgba(16,185,129,.4); }
.health-err { background: var(--c-danger); box-shadow: 0 0 4px rgba(225,0,15,.4); }
.health-warn { background: var(--c-warning); box-shadow: 0 0 4px rgba(217,119,6,.4); }
.health-unknown { background: var(--c-text-tertiary); }

/* ── Action buttons (admin) ─────────────────────────────────── */
.actions { display: flex; gap: 4px; }
.action-btn {
  padding: 3px 7px;
  border: none;
  border-radius: var(--r-sm);
  font-size: 10px;
  cursor: pointer;
  font-weight: 600;
  transition: opacity var(--duration-fast);
}
.action-btn:hover { opacity: .85; }
.approve-btn, .toggle-btn { background: var(--c-success); color: #fff; }
.reject-btn, .delete-btn { background: var(--c-danger); color: #fff; }

/* ── Form layout ────────────────────────────────────────────── */
.form-group { margin-bottom: var(--sp-5); }
.form-selector { display: flex; align-items: center; gap: var(--sp-3); margin-bottom: var(--sp-5); }
.form-selector select {
  padding: 6px 8px;
  border: 1px solid var(--c-border);
  border-radius: var(--r-md);
  font-size: 12px;
  font-family: inherit;
}
.form-selector button {
  padding: 6px 12px;
  border: none;
  border-radius: 7px;
  background: var(--c-primary);
  color: #fff;
  cursor: pointer;
  font-weight: 600;
  font-size: 12px;
  transition: background var(--duration-fast);
}
.form-selector button:hover { background: var(--c-primary-dark); }
.form-section { margin-bottom: var(--sp-5); }
.form-section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--sp-3); }
.form-section-header h2 { margin: 0; }
.form-section-header a { text-decoration: none; }
.section-title {
  font-size: 12px;
  color: var(--c-text);
  margin-bottom: var(--sp-3);
  padding-bottom: var(--sp-2);
  border-bottom: 1px solid var(--c-border-light);
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .03em;
}

/* ── Pagination ─────────────────────────────────────────────── */
.pagination {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: 4px;
  margin-top: var(--sp-5);
  font-size: 11px;
}
.pagination a, .pagination span {
  padding: 4px 8px;
  border: 1px solid var(--c-border);
  border-radius: 7px;
  text-decoration: none;
  color: var(--c-primary);
  font-weight: 500;
  transition: all var(--duration-fast) var(--ease-out);
  font-size: 11px;
}
.pagination a:hover {
  background: var(--c-primary-50);
  border-color: var(--c-primary-lighter);
  text-decoration: none;
}
.pagination .current {
  background: var(--c-primary);
  color: #fff;
  border-color: var(--c-primary);
  font-weight: 700;
}
.pagination .disabled {
  color: var(--c-text-tertiary);
  border-color: var(--c-border-light);
  pointer-events: none;
}

/* ── Info / Warn boxes ──────────────────────────────────────── */
.info-box {
  background: var(--c-info-50);
  border-left: 3px solid var(--c-primary);
  padding: 10px 14px;
  margin-bottom: var(--sp-4);
  border-radius: 0 var(--r-md) var(--r-md) 0;
  font-size: 12px;
}
.info-box p { margin-bottom: 2px; }
.warn-box {
  background: var(--c-warning-50);
  border-left: 3px solid var(--c-warning);
  padding: 10px 14px;
  margin-bottom: var(--sp-4);
  border-radius: 0 var(--r-md) var(--r-md) 0;
  font-size: 12px;
}
.warn-box p { margin-bottom: 2px; }
.success-box {
  background: var(--c-success-50);
  border-left: 3px solid var(--c-success);
  padding: 10px 14px;
  margin-bottom: var(--sp-4);
  border-radius: 0 var(--r-md) var(--r-md) 0;
  font-size: 12px;
}
.success-box p { margin-bottom: 2px; }

/* ── Details / Summary ──────────────────────────────────────── */
details { margin-bottom: var(--sp-3); }
details > summary {
  cursor: pointer;
  font-weight: 600;
  color: var(--c-text);
  padding: 7px 10px;
  background: var(--c-primary-50);
  border: 1px solid var(--c-border-light);
  border-radius: var(--r-md);
  list-style: none;
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  transition: background var(--duration-fast) var(--ease-out);
}
details > summary::before {
  content: "";
  display: inline-block;
  width: 0; height: 0;
  border-left: 5px solid var(--c-primary);
  border-top: 4px solid transparent;
  border-bottom: 4px solid transparent;
  transition: transform var(--duration-fast) var(--ease-out);
}
details[open] > summary::before { transform: rotate(90deg); }
details[open] > summary { border-radius: var(--r-md) var(--r-md) 0 0; }
details > summary:hover { background: var(--c-primary-100); }
details > summary:focus-visible { outline: 2px solid var(--c-primary); outline-offset: 1px; }
details > div, details > .card { margin-top: var(--sp-2); }

/* ── Error pages ────────────────────────────────────────────── */
.error-page {
  display: flex;
  min-height: calc(100vh - 120px);
  align-items: center;
  justify-content: center;
  padding: var(--sp-8) var(--sp-4);
}
.error-card {
  background: var(--c-surface);
  border: 1px solid var(--c-border);
  border-radius: var(--r-xl);
  padding: 2.5rem 2rem;
  max-width: 480px;
  width: 100%;
  text-align: center;
  box-shadow: var(--shadow-lg);
}
.error-card .error-code {
  font-size: 4rem;
  font-weight: 900;
  line-height: 1;
  margin-bottom: 4px;
  letter-spacing: -2px;
  color: var(--c-primary);
}
.error-card .error-code.code-403 { color: var(--c-danger); }
.error-card .error-code.code-404 { color: var(--c-primary); }
.error-card .error-code.code-400 { color: var(--c-warning-dark); }
.error-card .error-code.code-401 { color: var(--c-primary); }
.error-card .error-code.code-500 { color: var(--c-danger); }
.error-card .error-illustration { margin-bottom: 1rem; }
.error-card .error-illustration svg { width: 80px; height: 80px; }
.error-card h1 { font-size: var(--text-lg); color: var(--c-text); margin-bottom: .5rem; border: none; padding: 0; }
.error-card .error-message { color: var(--c-text-secondary); font-size: 12px; line-height: 1.6; margin-bottom: 1rem; }
.error-card .error-hint {
  font-size: 11px;
  color: var(--c-text-secondary);
  background: var(--c-primary-50);
  border: 1px solid var(--c-border-light);
  border-radius: var(--r-md);
  padding: 10px 12px;
  margin-bottom: 1.25rem;
  text-align: left;
  line-height: 1.5;
}
.error-card .error-hint strong { color: var(--c-text); display: block; margin-bottom: 2px; }
.error-card .error-actions { display: flex; gap: var(--sp-2); justify-content: center; flex-wrap: wrap; }
.error-card .error-stamp {
  margin-top: 1.25rem;
  padding-top: .75rem;
  border-top: 1px solid var(--c-border-light);
  font-size: 10px;
  color: var(--c-text-tertiary);
}

/* ── Breadcrumb ─────────────────────────────────────────────── */
.breadcrumb {
  font-size: 11px;
  padding: 6px 0;
  color: var(--c-text-tertiary);
}
.breadcrumb a {
  color: var(--c-text-tertiary);
  font-weight: 500;
  text-decoration: none;
  transition: color var(--duration-fast);
}
.breadcrumb a:hover { color: var(--c-primary); text-decoration: underline; }
.breadcrumb .separator { color: var(--c-border); margin: 0 3px; }
.breadcrumb .current { color: var(--c-text); font-weight: 600; }

/* ── Footer ─────────────────────────────────────────────────── */
footer {
  text-align: center;
  padding: var(--sp-4) var(--sp-4);
  font-size: 10px;
  color: var(--c-text-tertiary);
  background: var(--c-bg-warm);
  border-top: 1px solid var(--c-border-light);
  margin-top: var(--sp-6);
}
footer a {
  color: var(--c-primary);
  text-decoration: none;
  font-weight: 600;
}
footer a:hover { color: var(--c-primary-dark); text-decoration: underline; }

/* ── Skip link (RGAA) ─────────────────────────────────────── */
.skip-link {
  position: absolute;
  left: -9999px;
  top: 0;
  background: var(--c-primary);
  color: #fff;
  padding: 6px 14px;
  z-index: 9999;
  font-size: 12px;
  font-weight: 600;
  border-radius: 0 0 var(--r-md) 0;
}
.skip-link:focus { left: 0; }

/* ── Focus visible (RGAA) ──────────────────────────────────── */
:focus-visible { outline: 2px solid var(--c-primary); outline-offset: 2px; }
a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible {
  outline: 2px solid var(--c-primary);
  outline-offset: 1px;
}

/* ── Visually hidden ────────────────────────────────────────── */
.sr-only {
  position: absolute;
  width: 1px; height: 1px;
  padding: 0; margin: -1px;
  overflow: hidden;
  clip: rect(0,0,0,0);
  white-space: nowrap;
  border: 0;
}

/* ── Print ──────────────────────────────────────────────────── */
@media print {
  .sidebar, .topbar, footer, .btn, .form-actions, .actions-bar, .action-btn,
  .toolbar, .filtres, .form-selector, .topbar-icon-btn, .topbar-cta { display: none !important; }
  .app-layout { display: block !important; }
  .main-area { margin-left: 0 !important; }
  .content { padding: 0 !important; }
  body { background: #fff !important; color: #000 !important; background-image: none !important; }
  .card, .section-card { border: none !important; box-shadow: none !important; }
  .container { max-width: 100% !important; padding: 0 !important; margin: 0 !important; }
  a[href]::after { content: " (" attr(href) ")"; font-size: .85em; color: #555; }
  a[href^="#"]::after, a[href^="javascript"]::after { content: ""; }
  table, th, td { border: 1px solid #999 !important; }
  thead { background: #eee !important; color: #000 !important; }
  th { background: #eee !important; color: #000 !important; }
  .card, .section-card { page-break-inside: avoid; }
}

/* ── Animations ─────────────────────────────────────────────── */
@keyframes fadeSlideIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}
@keyframes softPulse {
  0%, 100% { opacity: 1; }
  50% { opacity: .7; }
}

main > .card, main > .stats, main > .hero, main > h1, main > h2,
main > .quick-stats, main > .form-cards, main > .nav-tiles,
main > .empty-state, main > .sub-card, main > .validation-card {
  animation: fadeSlideIn .35s var(--ease-out) both;
}
main > .card:nth-child(2), main > .stats:nth-child(2),
main > .sub-card:nth-child(2), main > .validation-card:nth-child(2) { animation-delay: .04s; }
main > .card:nth-child(3), main > .sub-card:nth-child(3), main > .validation-card:nth-child(3) { animation-delay: .08s; }
main > .card:nth-child(4), main > .sub-card:nth-child(4) { animation-delay: .12s; }

.badge-warn { animation: softPulse 2.5s ease-in-out infinite; }

/* ── Selection ──────────────────────────────────────────────── */
::selection {
  background: var(--c-primary-100);
  color: var(--c-primary-dark);
}

/* ── Reduced motion ─────────────────────────────────────────── */
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: .01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: .01ms !important;
    scroll-behavior: auto !important;
  }
}

/* ═══════════════════════════════════════════════════════════════
   RESPONSIVE — Sidebar collapses to top bar on mobile
   ═══════════════════════════════════════════════════════════════ */
@media (max-width: 768px) {
  .app-layout { flex-direction: column; }
  .sidebar {
    width: 100%;
    height: auto;
    position: relative;
    border-right: none;
    border-bottom: 1px solid var(--c-border);
    flex-direction: column;
    overflow: visible;
  }
  .sidebar-brand { padding: 8px 12px; border-bottom: 1px solid var(--c-border-light); }
  .sidebar-nav {
    padding: 6px 8px;
    display: flex;
    flex-wrap: wrap;
    gap: 2px;
  }
  .sidebar-section-title { display: none; }
  .sidebar-item {
    padding: 5px 8px;
    font-size: 11px;
    border-radius: 5px;
  }
  .sidebar-item-icon { font-size: 12px; width: 14px; }
  .sidebar-user { display: none; }
  .main-area { margin-left: 0; }
  .content { padding: 12px 12px 24px; }
  .grid-2 { grid-template-columns: 1fr; }
  .grid-3 { grid-template-columns: 1fr 1fr; }
  table { font-size: 10px; display: block; overflow-x: auto; -webkit-overflow-scrolling: touch; }
  th, td { padding: 4px 6px; }
  h1 { font-size: var(--text-xl); }
  .stats { gap: var(--sp-2); }
  .stat { min-width: 80px; padding: 8px 10px; }
  .stat strong { font-size: 18px; }
  .form-actions { flex-direction: column; }
  .form-actions .btn { width: 100%; text-align: center; justify-content: center; }
  .toolbar { flex-direction: column; align-items: stretch; }
  .filtres { flex-wrap: wrap; }
  .topbar { padding: 0 12px; }
}
@media (max-width: 600px) {
  .grid-2 { grid-template-columns: 1fr; }
  .grid-3 { grid-template-columns: 1fr; }
  h1 { font-size: var(--text-lg); }
  h2 { font-size: var(--text-base); }
  .card { padding: 10px; }
  .btn { padding: 5px 8px; font-size: 10px; }
  .form-selector { flex-direction: column; align-items: stretch; }
  .form-selector select, .form-selector button { width: 100%; }
  .pagination { gap: 2px; }
  .pagination a, .pagination span { padding: 3px 6px; font-size: 10px; }
  .timeline { flex-direction: column; align-items: stretch; }
  .step-item { max-width: 100%; flex-direction: row; gap: 6px; text-align: left; }
  .step-item:not(:last-child)::after { display: none; }
  .stat-card { padding: var(--sp-3); }
  .stat-card .stat-value { font-size: 18px; }
  input[type="text"], input[type="date"], input[type="number"], input[type="password"], input[type="email"], select, textarea {
    font-size: 16px; /* Empêche le zoom iOS */
  }
  .breadcrumb { font-size: 10px; }
}

/* ── Legacy: keep .bandeau class defined for backward compat ── */
.bandeau { display: none; }
</style>
