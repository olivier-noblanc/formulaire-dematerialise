<?php
// style.php — Design System 2026 "Aurora Institutionnel" pour Formulaire Dématérialisé DREETS
// À inclure via require_once __DIR__ . '/style.php'; dans le <head>
// Zéro JavaScript — Pure CSS + HTML5
// Tendances 2026 : dark mode natif, mesh gradients, micro-animations, bento grid,
//                  color-mix(), CSS nesting, container queries, scroll-driven
?>
<style>
/* ═══════════════════════════════════════════════════════════════
   DESIGN SYSTEM 2026 — "Aurora Institutionnel"
   Identité républicaine française + tendances 2026
   Dark mode natif · Mesh gradients · Bento grid · Micro-animations
   ═══════════════════════════════════════════════════════════════ */

/* ── Custom Properties (Design Tokens) ──────────────────────── */
:root {
  /* Primary — Bleu républicain profond → bleu électrique */
  --c-primary: #1E40AF;
  --c-primary-dark: #1E3A8A;
  --c-primary-darker: #172554;
  --c-primary-light: #3B82F6;
  --c-primary-lighter: #60A5FA;
  --c-primary-50: #EFF6FF;
  --c-primary-100: #DBEAFE;
  --c-primary-200: #BFDBFE;

  /* Accent — Or républicain */
  --c-accent: #D97706;
  --c-accent-dark: #B45309;
  --c-accent-light: #F59E0B;

  /* Semantic */
  --c-success: #059669;
  --c-success-dark: #047857;
  --c-success-50: #ECFDF5;
  --c-success-100: #D1FAE5;
  --c-warning: #D97706;
  --c-warning-dark: #B45309;
  --c-warning-50: #FFFBEB;
  --c-warning-100: #FEF3C7;
  --c-danger: #DC2626;
  --c-danger-dark: #B91C1C;
  --c-danger-50: #FEF2F2;
  --c-danger-100: #FEE2E2;
  --c-info: #2563EB;
  --c-info-50: #EFF6FF;
  --c-info-100: #DBEAFE;

  /* Neutrals — Cool gray (bleu-gris républicain) */
  --c-bg: #F1F5F9;
  --c-bg-warm: #F8FAFC;
  --c-surface: #FFFFFF;
  --c-surface-elevated: #FFFFFF;
  --c-surface-glass: rgba(255, 255, 255, 0.78);
  --c-border: #CBD5E1;
  --c-border-light: #E2E8F0;
  --c-border-subtle: #F1F5F9;
  --c-text: #0F172A;
  --c-text-secondary: #475569;
  --c-text-tertiary: #94A3B8;
  --c-text-inverse: #FFFFFF;

  /* Shadows — Layered, sophisticated */
  --shadow-xs: 0 1px 2px rgba(0,0,0,.04);
  --shadow-sm: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
  --shadow-md: 0 4px 6px -1px rgba(0,0,0,.08), 0 2px 4px -2px rgba(0,0,0,.05);
  --shadow-lg: 0 10px 15px -3px rgba(0,0,0,.08), 0 4px 6px -4px rgba(0,0,0,.04);
  --shadow-xl: 0 20px 25px -5px rgba(0,0,0,.1), 0 8px 10px -6px rgba(0,0,0,.04);
  --shadow-2xl: 0 25px 50px -12px rgba(0,0,0,.15);
  --shadow-glow: 0 0 24px rgba(30,64,175,.12);
  --shadow-colored: 0 4px 14px rgba(30,64,175,.16);
  --shadow-inner: inset 0 2px 4px rgba(0,0,0,.04);

  /* Radius — More organic, larger */
  --r-xs: 4px;
  --r-sm: 6px;
  --r-md: 10px;
  --r-lg: 14px;
  --r-xl: 20px;
  --r-2xl: 28px;
  --r-full: 9999px;

  /* Spacing — 8px grid */
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

  /* Typography — Marianne (identité française) + system fallbacks */
  --font-sans: "Marianne", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
  --font-mono: "JetBrains Mono", "SFMono-Regular", Menlo, Consolas, "Liberation Mono", monospace;
  --text-xs: .75rem;
  --text-sm: .8125rem;
  --text-base: .9375rem;
  --text-lg: 1.125rem;
  --text-xl: 1.375rem;
  --text-2xl: 1.75rem;
  --text-3xl: 2.25rem;
  --text-4xl: 3rem;

  /* Leading */
  --leading-tight: 1.25;
  --leading-snug: 1.375;
  --leading-normal: 1.6;

  /* Transitions — Physics-based easing */
  --ease-out: cubic-bezier(.16, 1, .3, 1);
  --ease-in-out: cubic-bezier(.65, 0, .35, 1);
  --ease-spring: cubic-bezier(.34, 1.56, .64, 1);
  --ease-bounce: cubic-bezier(.68, -.55, .27, 1.55);
  --duration-fast: .15s;
  --duration-normal: .25s;
  --duration-slow: .4s;
  --duration-slower: .6s;

  /* Gradients — Aurora / mesh-inspired */
  --gradient-primary: linear-gradient(135deg, #1E40AF 0%, #3B82F6 50%, #6366F1 100%);
  --gradient-primary-hover: linear-gradient(135deg, #1E3A8A 0%, #2563EB 50%, #4F46E5 100%);
  --gradient-aurora: linear-gradient(135deg, #1E40AF 0%, #3B82F6 25%, #6366F1 50%, #8B5CF6 75%, #3B82F6 100%);
  --gradient-warm: linear-gradient(135deg, #D97706 0%, #DC2626 100%);
  --gradient-cool: linear-gradient(135deg, #2563EB 0%, #06B6D4 100%);
  --gradient-success: linear-gradient(135deg, #059669 0%, #10B981 100%);
  --gradient-surface: linear-gradient(180deg, rgba(255,255,255,.95) 0%, rgba(255,255,255,.7) 100%);
  --gradient-mesh-1: radial-gradient(at 20% 20%, rgba(30,64,175,.08) 0%, transparent 50%),
                     radial-gradient(at 80% 80%, rgba(99,102,241,.06) 0%, transparent 50%),
                     radial-gradient(at 50% 50%, rgba(59,130,246,.04) 0%, transparent 70%);
  --gradient-mesh-hero: radial-gradient(ellipse at 20% 50%, rgba(30,64,175,.4) 0%, transparent 50%),
                        radial-gradient(ellipse at 80% 20%, rgba(99,102,241,.3) 0%, transparent 50%),
                        radial-gradient(ellipse at 60% 80%, rgba(6,182,212,.2) 0%, transparent 50%),
                        linear-gradient(135deg, #1E3A8A 0%, #1E40AF 50%, #312E81 100%);
}

/* ── Dark Mode ──────────────────────────────────────────────── */
@media (prefers-color-scheme: dark) {
  :root {
    --c-primary: #3B82F6;
    --c-primary-dark: #60A5FA;
    --c-primary-darker: #93C5FD;
    --c-primary-light: #60A5FA;
    --c-primary-lighter: #93C5FD;
    --c-primary-50: rgba(59,130,246,.12);
    --c-primary-100: rgba(59,130,246,.2);
    --c-primary-200: rgba(59,130,246,.3);

    --c-accent: #F59E0B;
    --c-accent-dark: #FBBF24;
    --c-accent-light: #FCD34D;

    --c-success: #10B981;
    --c-success-dark: #34D399;
    --c-success-50: rgba(16,185,129,.12);
    --c-success-100: rgba(16,185,129,.2);
    --c-warning: #F59E0B;
    --c-warning-dark: #FBBF24;
    --c-warning-50: rgba(245,158,11,.12);
    --c-warning-100: rgba(245,158,11,.2);
    --c-danger: #EF4444;
    --c-danger-dark: #F87171;
    --c-danger-50: rgba(239,68,68,.12);
    --c-danger-100: rgba(239,68,68,.2);
    --c-info: #60A5FA;
    --c-info-50: rgba(96,165,250,.12);
    --c-info-100: rgba(96,165,250,.2);

    --c-bg: #0F172A;
    --c-bg-warm: #1E293B;
    --c-surface: #1E293B;
    --c-surface-elevated: #334155;
    --c-surface-glass: rgba(30,41,59,.78);
    --c-border: #334155;
    --c-border-light: #1E293B;
    --c-border-subtle: #1E293B;
    --c-text: #F1F5F9;
    --c-text-secondary: #94A3B8;
    --c-text-tertiary: #64748B;
    --c-text-inverse: #0F172A;

    --shadow-xs: 0 1px 2px rgba(0,0,0,.2);
    --shadow-sm: 0 1px 3px rgba(0,0,0,.3), 0 1px 2px rgba(0,0,0,.2);
    --shadow-md: 0 4px 6px -1px rgba(0,0,0,.3), 0 2px 4px -2px rgba(0,0,0,.2);
    --shadow-lg: 0 10px 15px -3px rgba(0,0,0,.3), 0 4px 6px -4px rgba(0,0,0,.2);
    --shadow-xl: 0 20px 25px -5px rgba(0,0,0,.4), 0 8px 10px -6px rgba(0,0,0,.2);
    --shadow-2xl: 0 25px 50px -12px rgba(0,0,0,.5);
    --shadow-glow: 0 0 24px rgba(59,130,246,.2);
    --shadow-colored: 0 4px 14px rgba(59,130,246,.2);

    --gradient-primary: linear-gradient(135deg, #1E40AF 0%, #3B82F6 50%, #6366F1 100%);
    --gradient-primary-hover: linear-gradient(135deg, #1E3A8A 0%, #2563EB 50%, #4F46E5 100%);
    --gradient-surface: linear-gradient(180deg, rgba(30,41,59,.95) 0%, rgba(30,41,59,.7) 100%);
    --gradient-mesh-1: radial-gradient(at 20% 20%, rgba(59,130,246,.1) 0%, transparent 50%),
                       radial-gradient(at 80% 80%, rgba(99,102,241,.08) 0%, transparent 50%),
                       radial-gradient(at 50% 50%, rgba(59,130,246,.05) 0%, transparent 70%);
  }
}

/* ── Reset & Base ────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html {
  scroll-behavior: smooth;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
  text-rendering: optimizeLegibility;
  hanging-punctuation: first last;
}
body {
  font-family: var(--font-sans);
  background: var(--c-bg);
  background-image: var(--gradient-mesh-1);
  background-attachment: fixed;
  color: var(--c-text);
  font-size: var(--text-base);
  line-height: var(--leading-normal);
  min-height: 100vh;
}

/* ── Navigation — Floating glassmorphism bar ────────────────── */
.bandeau {
  background: var(--gradient-primary);
  color: var(--c-text-inverse);
  padding: 0 var(--sp-8);
  font-size: var(--text-sm);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: var(--sp-2);
  position: sticky;
  top: 0;
  z-index: 100;
  box-shadow: var(--shadow-xl), 0 1px 0 rgba(255,255,255,.08) inset;
  min-height: 60px;
  backdrop-filter: blur(20px) saturate(1.4);
  -webkit-backdrop-filter: blur(20px) saturate(1.4);
}
.bandeau a {
  color: rgba(255,255,255,.82);
  font-size: var(--text-sm);
  text-decoration: none;
  padding: .4rem .85rem;
  border-radius: var(--r-full);
  transition: background var(--duration-fast) var(--ease-out),
              color var(--duration-fast) var(--ease-out),
              transform var(--duration-fast) var(--ease-out);
  white-space: nowrap;
  position: relative;
}
.bandeau a:hover {
  background: rgba(255,255,255,.14);
  color: #fff;
  text-decoration: none;
  transform: translateY(-1px);
}
.bandeau a:active { transform: translateY(0) scale(.98); }
.bandeau a:focus-visible {
  outline: 2px solid #fff;
  outline-offset: 2px;
}
.bandeau a.nav-active {
  background: rgba(255,255,255,.2);
  color: #fff;
  font-weight: 700;
  box-shadow: inset 0 0 0 1px rgba(255,255,255,.12), 0 2px 8px rgba(0,0,0,.1);
}
.bandeau .nav-brand {
  color: #fff;
  font-size: var(--text-lg);
  font-weight: 800;
  text-decoration: none;
  padding: 0;
  letter-spacing: -.03em;
  display: flex;
  align-items: center;
  gap: .6rem;
}
.bandeau .nav-brand:hover { background: transparent; transform: none; }
.bandeau .nav-brand .brand-dot {
  display: inline-block;
  width: 8px; height: 8px;
  border-radius: 50%;
  background: var(--c-accent-light);
  box-shadow: 0 0 8px rgba(245,158,11,.5);
  animation: brandPulse 3s ease-in-out infinite;
}
.bandeau .nav-main { display: flex; gap: .2rem; flex-wrap: wrap; align-items: center; }
.bandeau .nav-admin { display: flex; gap: .2rem; align-items: center; flex-wrap: wrap; }
.bandeau .nav-user {
  color: rgba(255,255,255,.6);
  font-size: var(--text-xs);
  margin-left: var(--sp-2);
  font-variant-numeric: tabular-nums;
}
.bandeau .nav-badge {
  background: var(--c-accent);
  color: var(--c-text);
  font-size: .65rem;
  padding: 1px 7px;
  border-radius: var(--r-full);
  font-weight: 800;
  vertical-align: middle;
  box-shadow: 0 0 0 2px rgba(245,158,11,.4);
  animation: badgePulse 2s ease-in-out infinite;
}

/* ── Container ──────────────────────────────────────────────── */
.container {
  max-width: 960px;
  margin: 0 auto;
  padding: 0 var(--sp-8) var(--sp-12);
}

/* ── Typography ─────────────────────────────────────────────── */
h1 {
  font-size: var(--text-2xl);
  font-weight: 800;
  color: var(--c-primary-dark);
  margin-bottom: var(--sp-2);
  letter-spacing: -.03em;
  line-height: var(--leading-tight);
}
h2 {
  font-size: var(--text-xl);
  font-weight: 700;
  color: var(--c-primary-dark);
  border-bottom: none;
  padding-bottom: 0;
  margin-bottom: var(--sp-4);
  letter-spacing: -.02em;
  line-height: var(--leading-snug);
}
h3 {
  font-size: var(--text-lg);
  font-weight: 600;
  color: var(--c-primary-dark);
  margin-bottom: var(--sp-2);
  line-height: var(--leading-snug);
}
p { margin-bottom: var(--sp-3); }

/* ── Cards — Elevated surface with subtle glass ─────────────── */
.card {
  background: var(--c-surface);
  border: 1px solid var(--c-border-light);
  border-radius: var(--r-lg);
  padding: var(--sp-6);
  margin-bottom: var(--sp-6);
  box-shadow: var(--shadow-sm);
  transition: box-shadow var(--duration-normal) var(--ease-out),
              transform var(--duration-normal) var(--ease-out),
              border-color var(--duration-normal) var(--ease-out);
  position: relative;
}
.card:hover {
  box-shadow: var(--shadow-md);
  border-color: var(--c-border);
}
.card h2 {
  font-size: var(--text-lg);
  color: var(--c-primary-dark);
  border-bottom: 2px solid var(--c-primary-50);
  padding-bottom: var(--sp-2);
  margin-bottom: var(--sp-4);
}

/* ── Buttons — Refined pill with micro-interactions ─────────── */
.btn {
  padding: .6rem 1.35rem;
  border: none;
  border-radius: var(--r-full);
  font-size: var(--text-sm);
  font-weight: 600;
  font-family: inherit;
  cursor: pointer;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: .4rem;
  transition: all var(--duration-fast) var(--ease-out);
  line-height: 1.4;
  position: relative;
  overflow: hidden;
}
.btn::after {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(180deg, rgba(255,255,255,.12) 0%, transparent 50%);
  pointer-events: none;
  border-radius: inherit;
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
  transform: translateY(-1px);
}
.btn-secondary {
  background: var(--c-surface);
  color: var(--c-text-secondary);
  border: 1.5px solid var(--c-border);
  box-shadow: var(--shadow-xs);
}
.btn-secondary:hover {
  background: var(--c-bg-warm);
  border-color: var(--c-text-tertiary);
  color: var(--c-text);
  text-decoration: none;
  transform: translateY(-1px);
}
.btn-danger {
  background: var(--c-danger);
  color: #fff;
  box-shadow: 0 2px 8px rgba(220,38,38,.25);
}
.btn-danger:hover {
  background: var(--c-danger-dark);
  color: #fff;
  box-shadow: 0 4px 12px rgba(220,38,38,.3);
  text-decoration: none;
  transform: translateY(-1px);
}
.btn-test {
  background: var(--gradient-success);
  color: #fff;
}
.btn-test:hover { background: var(--c-success-dark); color: #fff; }

/* ── Messages — Modern callout with icon stripe ─────────────── */
.msg-success {
  background: var(--c-success-50);
  border: 1px solid var(--c-success);
  border-left: 4px solid var(--c-success);
  border-radius: var(--r-md);
  padding: .85rem 1.25rem;
  margin-bottom: var(--sp-4);
  color: var(--c-success-dark);
  font-weight: 500;
  backdrop-filter: blur(8px);
}
.msg-error {
  background: var(--c-danger-50);
  border: 1px solid var(--c-danger);
  border-left: 4px solid var(--c-danger);
  border-radius: var(--r-md);
  padding: .85rem 1.25rem;
  margin-bottom: var(--sp-4);
  color: var(--c-danger-dark);
  font-weight: 500;
  backdrop-filter: blur(8px);
}
.msg-info {
  background: var(--c-info-50);
  border: 1px solid var(--c-info);
  border-left: 4px solid var(--c-info);
  border-radius: var(--r-md);
  padding: .85rem 1.25rem;
  margin-bottom: var(--sp-4);
  color: var(--c-primary-dark);
  font-weight: 500;
  backdrop-filter: blur(8px);
}
.errors {
  background: var(--c-danger-50);
  border: 1px solid var(--c-danger);
  border-radius: var(--r-md);
  padding: 1.25rem;
  margin-bottom: var(--sp-6);
  color: var(--c-danger-dark);
}
.success {
  background: var(--c-success-50);
  border: 1px solid var(--c-success);
  border-radius: var(--r-lg);
  padding: var(--sp-8);
  color: var(--c-success-dark);
  text-align: center;
  box-shadow: var(--shadow-sm);
  backdrop-filter: blur(8px);
}
.success strong { display: block; font-size: var(--text-xl); margin-bottom: var(--sp-2); }

/* ── Form fields — Floating label feel ──────────────────────── */
.field { display: flex; flex-direction: column; gap: .35rem; margin-bottom: 1.15rem; }
label { font-size: var(--text-sm); font-weight: 600; color: var(--c-text-secondary); }
.hint { font-size: var(--text-xs); color: var(--c-text-tertiary); font-weight: 400; }
.req { color: var(--c-danger); margin-left: 2px; }
input[type="text"], input[type="date"], input[type="number"], input[type="password"], input[type="email"], input[type="tel"], input[type="url"], select, textarea {
    width: 100%;
    padding: .7rem 1rem;
    border: 1.5px solid var(--c-border);
    border-radius: var(--r-md);
    font-size: var(--text-base);
    font-family: inherit;
    background: var(--c-surface);
    color: var(--c-text);
    transition: border-color var(--duration-fast) var(--ease-out),
                box-shadow var(--duration-fast) var(--ease-out),
                background var(--duration-fast) var(--ease-out);
}
input:hover, select:hover, textarea:hover {
  border-color: color-mix(in srgb, var(--c-primary) 40%, var(--c-border));
}
input:focus, select:focus, textarea:focus {
  outline: none;
  border-color: var(--c-primary);
  box-shadow: 0 0 0 3px rgba(30,64,175,.1), var(--shadow-sm);
  background: var(--c-surface-elevated);
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

/* ── Tables — Elegant, refined header ───────────────────────── */
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
  padding: .85rem 1rem;
  text-align: left;
  font-weight: 700;
  color: var(--c-primary-dark);
  white-space: nowrap;
  font-size: var(--text-xs);
  text-transform: uppercase;
  letter-spacing: .06em;
  border-bottom: 2px solid var(--c-primary-100);
}
th, td { padding: .7rem 1rem; text-align: left; border-bottom: 1px solid var(--c-border-light); }
th { font-weight: 600; }
tbody td { vertical-align: middle; }
tbody tr:last-child td { border-bottom: none; }
tbody tr { transition: background var(--duration-fast) var(--ease-out); }
tbody tr:nth-child(even) { background: var(--c-bg-warm); }
tbody tr:hover { background: var(--c-primary-50); }

/* ── Badges — Refined pills ─────────────────────────────────── */
.badge {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .25rem .8rem;
  border-radius: var(--r-full);
  font-size: var(--text-xs);
  font-weight: 700;
  letter-spacing: .02em;
  backdrop-filter: blur(4px);
}
.badge-ok, .badge-valide { background: var(--c-success-50); color: var(--c-success-dark); }
.badge-warn, .badge-en-cours { background: var(--c-warning-50); color: var(--c-warning-dark); }
.badge-err, .badge-refuse { background: var(--c-danger-50); color: var(--c-danger-dark); }
.badge-info { background: var(--c-info-50); color: var(--c-primary-dark); }

/* ── Stats — Bento cards with gradient accent ───────────────── */
.stats {
  display: flex;
  gap: var(--sp-4);
  margin-bottom: var(--sp-6);
  flex-wrap: wrap;
}
.stat {
  background: var(--c-surface);
  border: 1px solid var(--c-border-light);
  border-radius: var(--r-lg);
  padding: var(--sp-4) var(--sp-6);
  min-width: 120px;
  font-size: var(--text-sm);
  box-shadow: var(--shadow-sm);
  position: relative;
  overflow: hidden;
  transition: transform var(--duration-fast) var(--ease-out),
              box-shadow var(--duration-fast) var(--ease-out);
}
.stat:hover {
  transform: translateY(-3px);
  box-shadow: var(--shadow-lg), var(--shadow-glow);
}
.stat::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 3px;
  background: var(--gradient-primary);
}
.stat strong {
  display: block;
  font-size: var(--text-3xl);
  color: var(--c-primary);
  font-weight: 800;
  letter-spacing: -.03em;
  font-variant-numeric: tabular-nums;
}
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
  padding: var(--sp-6);
  text-align: center;
  box-shadow: var(--shadow-sm);
  position: relative;
  overflow: hidden;
  transition: transform var(--duration-normal) var(--ease-out),
              box-shadow var(--duration-normal) var(--ease-out);
}
.stat-card:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow-lg);
}
.stat-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 3px;
  background: var(--gradient-primary);
}
.stat-card .stat-value {
  font-size: 2.25rem;
  font-weight: 800;
  color: var(--c-primary);
  letter-spacing: -.03em;
  font-variant-numeric: tabular-nums;
}
.stat-card .stat-label {
  font-size: var(--text-sm);
  color: var(--c-text-tertiary);
  margin-top: var(--sp-1);
  font-weight: 500;
}
.stat-card.success .stat-value { color: var(--c-success-dark); }
.stat-card.success::before { background: var(--gradient-success); }
.stat-card.danger .stat-value { color: var(--c-danger-dark); }
.stat-card.danger::before { background: linear-gradient(135deg, #EF4444, #DC2626); }
.stat-card.warning .stat-value { color: var(--c-warning-dark); }
.stat-card.warning::before { background: linear-gradient(135deg, #F59E0B, #D97706); }

/* ── Empty state ────────────────────────────────────────────── */
.empty-state {
  text-align: center;
  padding: var(--sp-16) var(--sp-4);
  color: var(--c-text-tertiary);
}
.empty-state .empty-icon { font-size: 3.5rem; margin-bottom: var(--sp-4); opacity: .5; }
.empty-state p { margin-bottom: var(--sp-4); font-size: var(--text-base); }

/* ── Toolbar / Filters ─────────────────────────────────────── */
.toolbar { display: flex; gap: var(--sp-4); align-items: center; margin-bottom: var(--sp-6); flex-wrap: wrap; }
select.form-filter {
  padding: .5rem .875rem;
  border: 1.5px solid var(--c-border);
  border-radius: var(--r-md);
  font-size: var(--text-sm);
  font-family: inherit;
  background: var(--c-surface);
  color: var(--c-text);
  transition: border-color var(--duration-fast), box-shadow var(--duration-fast);
}
.form-actions { display: flex; gap: var(--sp-2); margin-top: var(--sp-4); }

/* ── Grids ──────────────────────────────────────────────────── */
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: var(--sp-4); }
.grid-3 {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: var(--sp-4);
  margin-bottom: var(--sp-6);
}

/* ── Timeline / Workflow steps ──────────────────────────────── */
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
  min-width: 100px;
  max-width: 160px;
  flex: 1;
  text-align: center;
  position: relative;
  padding: 0 .5rem;
}
.step-item:not(:last-child)::after {
  content: '';
  position: absolute;
  top: 16px; right: -50%;
  width: 100%; height: 3px;
  z-index: 0;
  border-radius: 2px;
}
.step-item.step-validated:not(:last-child)::after { background: var(--c-success); }
.step-item.step-current:not(:last-child)::after { background: var(--c-warning); }
.step-item.step-upcoming:not(:last-child)::after { background: var(--c-border); }
.step-icon {
  width: 34px; height: 34px; border-radius: var(--r-full);
  display: flex; align-items: center; justify-content: center;
  font-size: var(--text-sm); font-weight: 700; z-index: 1; margin-bottom: var(--sp-2);
  transition: transform var(--duration-normal) var(--ease-spring);
}
.step-validated .step-icon { background: var(--c-success); color: #fff; box-shadow: 0 2px 8px rgba(5,150,105,.3); }
.step-current .step-icon { background: var(--c-warning); color: #fff; box-shadow: 0 2px 8px rgba(217,119,6,.3); animation: stepPulse 2s ease-in-out infinite; }
.step-upcoming .step-icon { background: var(--c-border); color: var(--c-text-tertiary); }
.step-label { font-size: var(--text-xs); font-weight: 700; color: var(--c-text); margin-bottom: .2rem; line-height: 1.3; }
.step-detail { font-size: .7rem; color: var(--c-text-tertiary); line-height: 1.4; }

/* ── Health dots (monitoring) ───────────────────────────────── */
.health-dot { display: inline-block; width: 10px; height: 10px; border-radius: var(--r-full); margin-right: .5rem; vertical-align: middle; }
.health-ok { background: var(--c-success); box-shadow: 0 0 6px rgba(5,150,105,.4); }
.health-err { background: var(--c-danger); box-shadow: 0 0 6px rgba(220,38,38,.4); }
.health-warn { background: var(--c-warning); box-shadow: 0 0 6px rgba(217,119,6,.4); }
.health-unknown { background: var(--c-text-tertiary); }

/* ── Action buttons (admin) ─────────────────────────────────── */
.actions { display: flex; gap: var(--sp-2); }
.action-btn {
  padding: .3rem .6rem;
  border: none;
  border-radius: var(--r-sm);
  font-size: var(--text-xs);
  cursor: pointer;
  font-weight: 600;
  transition: transform var(--duration-fast) var(--ease-out);
}
.action-btn:active { transform: scale(.95); }
.approve-btn, .toggle-btn { background: var(--c-success); color: #fff; }
.reject-btn, .delete-btn { background: var(--c-danger); color: #fff; }

/* ── Form layout ────────────────────────────────────────────── */
.form-group { margin-bottom: var(--sp-6); }
.form-selector { display: flex; align-items: center; gap: var(--sp-4); margin-bottom: var(--sp-6); }
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
  transition: all var(--duration-fast) var(--ease-out);
}
.form-selector button:hover { background: var(--gradient-primary-hover); transform: translateY(-1px); }
.form-section { margin-bottom: var(--sp-6); }
.form-section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--sp-4); }
.form-section-header h2 { margin: 0; }
.form-section-header a { text-decoration: none; }
.section-title {
  font-size: var(--text-xl);
  color: var(--c-primary-dark);
  margin-bottom: var(--sp-4);
  padding-bottom: var(--sp-2);
  border-bottom: 2px solid var(--c-primary-50);
  font-weight: 700;
}

/* ── Pagination — Refined pill style ────────────────────────── */
.pagination {
  display: flex;
  justify-content: center;
  align-items: center;
  gap: var(--sp-2);
  margin-top: var(--sp-6);
  font-size: var(--text-sm);
}
.pagination a, .pagination span {
  padding: .45rem .9rem;
  border: 1px solid var(--c-border);
  border-radius: var(--r-full);
  text-decoration: none;
  color: var(--c-primary);
  font-weight: 500;
  transition: all var(--duration-fast) var(--ease-out);
}
.pagination a:hover {
  background: var(--c-primary-50);
  border-color: var(--c-primary-light);
  text-decoration: none;
  transform: translateY(-1px);
}
.pagination .current {
  background: var(--gradient-primary);
  color: #fff;
  border-color: transparent;
  font-weight: 700;
  box-shadow: var(--shadow-colored);
}
.pagination .disabled {
  color: var(--c-text-tertiary);
  border-color: var(--c-border-light);
  pointer-events: none;
}

/* ── Info / Warn boxes — Modern callout ─────────────────────── */
.info-box {
  background: var(--c-info-50);
  border-left: 4px solid var(--c-primary);
  padding: 1rem 1.25rem;
  margin-bottom: var(--sp-4);
  border-radius: 0 var(--r-md) var(--r-md) 0;
  box-shadow: var(--shadow-xs);
  backdrop-filter: blur(8px);
}
.info-box p { margin-bottom: .25rem; }
.warn-box {
  background: var(--c-warning-50);
  border-left: 4px solid var(--c-warning);
  padding: 1rem 1.25rem;
  margin-bottom: var(--sp-4);
  border-radius: 0 var(--r-md) var(--r-md) 0;
  box-shadow: var(--shadow-xs);
  backdrop-filter: blur(8px);
}
.warn-box p { margin-bottom: .25rem; }
.success-box {
  background: var(--c-success-50);
  border-left: 4px solid var(--c-success);
  padding: 1rem 1.25rem;
  margin-bottom: var(--sp-4);
  border-radius: 0 var(--r-md) var(--r-md) 0;
  box-shadow: var(--shadow-xs);
  backdrop-filter: blur(8px);
}
.success-box p { margin-bottom: .25rem; }

/* ── Details / Summary (HTML5 — sans JS) ────────────────────── */
details { margin-bottom: var(--sp-4); }
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
  transition: background var(--duration-fast) var(--ease-out),
              border-color var(--duration-fast) var(--ease-out);
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
details[open] > summary {
  border-color: var(--c-primary-light);
  border-radius: var(--r-md) var(--r-md) 0 0;
}
details > summary:hover { background: var(--c-primary-100); }
details > summary:focus-visible { outline: 2px solid var(--c-primary); outline-offset: 2px; }
details > div, details > .card { margin-top: var(--sp-2); }

/* ── Responsive ─────────────────────────────────────────────── */
@media (max-width: 768px) {
  .grid-2 { grid-template-columns: 1fr; }
  .grid-3 { grid-template-columns: 1fr 1fr; }
  .bandeau { padding: .5rem 1rem; gap: .35rem; }
  .bandeau a { font-size: var(--text-xs); padding: .3rem .5rem; }
  table { font-size: var(--text-xs); display: block; overflow-x: auto; -webkit-overflow-scrolling: touch; }
  th, td { padding: .4rem .5rem; }
  .container { padding: 0 var(--sp-4) var(--sp-8); }
  .card { padding: var(--sp-4); }
  h1 { font-size: var(--text-xl); }
  .stats { gap: var(--sp-2); }
  .stat { min-width: 90px; padding: var(--sp-3); }
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
  .stat-card { padding: var(--sp-4); }
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
  transition: left 0s;
}
.skip-link:focus { left: 0; }

/* ── Focus visible (RGAA) ──────────────────────────────────── */
:focus-visible { outline: 3px solid var(--c-primary); outline-offset: 2px; }
a:focus-visible, button:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible {
  outline: 3px solid var(--c-primary);
  outline-offset: 2px;
}

/* ── Visually hidden (screen readers only) ─────────────────── */
.sr-only {
  position: absolute;
  width: 1px; height: 1px;
  padding: 0; margin: -1px;
  overflow: hidden;
  clip: rect(0,0,0,0);
  white-space: nowrap;
  border: 0;
}

/* ── Error pages — Centered glass card ──────────────────────── */
.error-page {
  display: flex;
  min-height: calc(100vh - 120px);
  align-items: center;
  justify-content: center;
  padding: var(--sp-8) var(--sp-4);
}
.error-card {
  background: var(--c-surface-glass);
  backdrop-filter: blur(20px) saturate(1.4);
  -webkit-backdrop-filter: blur(20px) saturate(1.4);
  border: 1px solid var(--c-border-light);
  border-radius: var(--r-2xl);
  padding: 3rem 2.5rem;
  max-width: 560px;
  width: 100%;
  text-align: center;
  box-shadow: var(--shadow-2xl);
}
.error-card .error-code {
  font-size: 5rem;
  font-weight: 900;
  line-height: 1;
  margin-bottom: .25rem;
  letter-spacing: -3px;
  background: var(--gradient-primary);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}
.error-card .error-code.code-403 { background: linear-gradient(135deg, #DC2626, #EF4444); -webkit-background-clip: text; background-clip: text; }
.error-card .error-code.code-404 { background: var(--gradient-cool); -webkit-background-clip: text; background-clip: text; }
.error-card .error-code.code-400 { background: linear-gradient(135deg, #D97706, #F59E0B); -webkit-background-clip: text; background-clip: text; }
.error-card .error-code.code-401 { background: var(--gradient-primary); -webkit-background-clip: text; background-clip: text; }
.error-card .error-code.code-500 { background: linear-gradient(135deg, #DC2626, #EF4444); -webkit-background-clip: text; background-clip: text; }
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
.error-card .error-actions { display: flex; gap: var(--sp-2); justify-content: center; flex-wrap: wrap; }
.error-card .error-stamp {
  margin-top: 1.5rem;
  padding-top: 1rem;
  border-top: 1px solid var(--c-border-light);
  font-size: var(--text-xs);
  color: var(--c-text-tertiary);
}

/* ── Breadcrumb ─────────────────────────────────────────────── */
.breadcrumb {
  font-size: var(--text-sm);
  padding: var(--sp-3) 0;
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

/* ── Footer — Subtle, elegant ───────────────────────────────── */
footer {
  text-align: center;
  padding: var(--sp-6) var(--sp-4);
  font-size: var(--text-xs);
  color: var(--c-text-tertiary);
  background: var(--c-bg-warm);
  border-top: 1px solid var(--c-border-light);
  margin-top: var(--sp-8);
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

/* ── Animations (CSS only — @keyframes) ────────────────────── */
@keyframes fadeSlideIn {
  from { opacity: 0; transform: translateY(16px); }
  to { opacity: 1; transform: translateY(0); }
}
@keyframes fadeScaleIn {
  from { opacity: 0; transform: scale(.96); }
  to { opacity: 1; transform: scale(1); }
}
@keyframes brandPulse {
  0%, 100% { box-shadow: 0 0 4px rgba(245,158,11,.3); }
  50% { box-shadow: 0 0 12px rgba(245,158,11,.6); }
}
@keyframes badgePulse {
  0%, 100% { box-shadow: 0 0 0 2px rgba(245,158,11,.3); }
  50% { box-shadow: 0 0 0 4px rgba(245,158,11,.15); }
}
@keyframes stepPulse {
  0%, 100% { box-shadow: 0 2px 8px rgba(217,119,6,.3); }
  50% { box-shadow: 0 2px 16px rgba(217,119,6,.5); }
}
@keyframes softPulse {
  0%, 100% { opacity: 1; }
  50% { opacity: .7; }
}
@keyframes shimmer {
  0% { background-position: -200% center; }
  100% { background-position: 200% center; }
}

/* Entrance animation — staggered for main children */
main > .card, main > .stats, main > .hero, main > h1, main > h2,
main > .quick-stats, main > .form-cards, main > .nav-tiles,
main > .empty-state, main > .sub-card, main > .validation-card {
  animation: fadeSlideIn .5s var(--ease-out) both;
}
main > .card:nth-child(2), main > .stats:nth-child(2),
main > .sub-card:nth-child(2), main > .validation-card:nth-child(2) { animation-delay: .06s; }
main > .card:nth-child(3), main > .sub-card:nth-child(3), main > .validation-card:nth-child(3) { animation-delay: .12s; }
main > .card:nth-child(4), main > .sub-card:nth-child(4) { animation-delay: .18s; }
main > .card:nth-child(5), main > .sub-card:nth-child(5) { animation-delay: .24s; }

/* Subtle pulse for pending items */
.badge-warn { animation: softPulse 2.5s ease-in-out infinite; }

/* ── Selection ──────────────────────────────────────────────── */
::selection {
  background: var(--c-primary-100);
  color: var(--c-primary-darker);
}

/* ── Scrollbar (Webkit — subtle) ────────────────────────────── */
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb {
  background: var(--c-border);
  border-radius: var(--r-full);
}
::-webkit-scrollbar-thumb:hover { background: var(--c-text-tertiary); }

/* ── Reduced motion ─────────────────────────────────────────── */
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: .01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: .01ms !important;
    scroll-behavior: auto !important;
  }
}
</style>
