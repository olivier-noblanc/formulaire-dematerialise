#!/usr/bin/env bash
# =============================================================================
# gate.sh — Orchestrateur de la "gate" qualité (exécuté avant chaque push).
#
# Exécute, dans l'ordre, avec fail-fast et timeout global de 5 minutes :
#   1. Lint PHP (php -l) sur tous les fichiers PHP modifiés
#   2. PHPStan (analyse statique niveau 6 + baseline autorisée)
#   3. tests/test_all.php           (51 tests, ~60s)
#   4. tests/test_form_render_html.php (8 tests, ~30s)
#   5. tests/StructuralHtmlTest.php (si existe — sinon skip avec warning)
#   6. tests/regression/run_all.php (si existe — sinon skip)
#   7. tests/test_e2e_full_flow.js  (5 tests Playwright, ~60s — skip si node/playwright manquants)
#
# Sortie : code 0 si tout passe, 1 si un test échoue, 2 si une dépendance
# critique (php) manque. Les dépendances optionnelles (node, playwright)
# génèrent un warning mais ne font pas échouer la gate.
#
# Compatible Linux + Git-for-Windows (bash). N'utilise que `php`, `node`
# dans le PATH (pas de chemins absolus).
#
# Usage : bash scripts/gate.sh
# =============================================================================
set -euo pipefail

# ─── Se positionner à la racine du projet ────────────────────────────────────
cd "$(dirname "$0")/.."
PROJECT_ROOT="$(pwd)"

# ─── Couleurs ANSI ───────────────────────────────────────────────────────────
if [[ -t 1 ]]; then
    C_GREEN='\033[32m'
    C_RED='\033[31m'
    C_YELLOW='\033[33m'
    C_CYAN='\033[36m'
    C_BOLD='\033[1m'
    C_RESET='\033[0m'
else
    C_GREEN=''; C_RED=''; C_YELLOW=''; C_CYAN=''; C_BOLD=''; C_RESET=''
fi

# ─── Helpers d'affichage ─────────────────────────────────────────────────────
info()  { printf "${C_CYAN}[INFO]${C_RESET} %s\n" "$*"; }
warn()  { printf "${C_YELLOW}[WARN]${C_RESET} %s\n" "$*"; }
err()   { printf "${C_RED}[ERREUR]${C_RESET} %s\n" "$*" >&2; }
ok()    { printf "${C_GREEN}[OK]${C_RESET} %s\n" "$*"; }

# ─── Récapitulatif (tableau) ─────────────────────────────────────────────────
# Stocke les résultats : "ÉTAPE|DURÉE|STATUT"
declare -a RESULTS=()
add_result() {
    # $1 = étape, $2 = durée (ex: "12.3s"), $3 = statut ("OK"|"ÉCHEC"|"SKIP")
    RESULTS+=("$1|$2|$3")
}

print_summary() {
    printf "\n${C_BOLD}═══════════════════════════════════════════════════════════════════════════${C_RESET}\n"
    printf "${C_BOLD}  RÉCAPITULATIF DE LA GATE QUALITÉ${C_RESET}\n"
    printf "${C_BOLD}═══════════════════════════════════════════════════════════════════════════${C_RESET}\n"
    printf "  %-45s | %-10s | %-10s\n" "ÉTAPE" "DURÉE" "STATUT"
    printf "  ---------------------------------------------+------------+------------\n"
    local global_failed=0
    for r in "${RESULTS[@]}"; do
        IFS='|' read -r step dur status <<< "$r"
        local color="$C_GREEN"
        if [[ "$status" == "ÉCHEC" ]]; then
            color="$C_RED"; global_failed=1
        elif [[ "$status" == "SKIP" ]]; then
            color="$C_YELLOW"
        fi
        printf "  %-45s | %-10s | ${color}%-10s${C_RESET}\n" "$step" "$dur" "$status"
    done
    printf "  ---------------------------------------------+------------+------------\n"
    if [[ $global_failed -eq 0 ]]; then
        printf "${C_BOLD}${C_GREEN}  GATE : SUCCÈS — push autorisé${C_RESET}\n"
    else
        printf "${C_BOLD}${C_RED}  GATE : ÉCHEC — push BLOQUÉ${C_RESET}\n"
    fi
    printf "${C_BOLD}═══════════════════════════════════════════════════════════════════════════${C_RESET}\n"
}

# ─── Détection des dépendances ───────────────────────────────────────────────
PHP_BIN="php"
NODE_BIN="node"
PLAYWRIGHT_AVAILABLE="no"

if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
    err "PHP introuvable dans le PATH — dépendance critique manquante."
    err "Installez PHP 8.4+ et ajoutez-le au PATH."
    exit 2
fi
info "PHP détecté : $("$PHP_BIN" -v | head -1)"

if ! command -v "$NODE_BIN" >/dev/null 2>&1; then
    warn "Node.js introuvable dans le PATH — tests Playwright e2e seront skippés."
    NODE_BIN=""
else
    info "Node.js détecté : $("$NODE_BIN" --version)"
    # Vérifie Playwright : soit en global, soit en local (node_modules/.bin)
    if "$NODE_BIN" -e "require.resolve('playwright')" >/dev/null 2>&1; then
        PLAYWRIGHT_AVAILABLE="yes"
        info "Playwright détecté (module résolvable)."
    elif command -v playwright >/dev/null 2>&1; then
        PLAYWRIGHT_AVAILABLE="yes"
        info "Playwright détecté (CLI global)."
    else
        warn "Playwright introuvable — tests e2e Playwright seront skippés."
    fi
fi

# ─── Timeout global : 5 minutes (300s) ───────────────────────────────────────
GATE_TIMEOUT=300
# On lance un watcher en arrière-plan qui tuera le shell courant après timeout
( sleep "$GATE_TIMEOUT" && err "Timeout global de ${GATE_TIMEOUT}s atteint — gate interrompue." && kill -TERM "$$" 2>/dev/null ) &
WATCHER_PID=$!
trap 'kill "$WATCHER_PID" 2>/dev/null || true' EXIT

# ─── Fonction utilitaire : exécuter une étape et mesurer sa durée ────────────
# run_step "NOM ÉTAPE" "CHECK_SKIP_CMD" "REAL_CMD"
# - Si CHECK_SKIP_CMD (évalué) retourne non-zéro, l'étape est skippée.
# - Sinon, REAL_CMD est exécuté. Si son code de retour est 0 → OK, sinon ÉCHEC.
run_step() {
    local name="$1"
    local check_skip="$2"
    local cmd="$3"

    printf "\n${C_BOLD}─── %s ───${C_RESET}\n" "$name"

    # Vérifie si on doit skipper
    if ! eval "$check_skip" >/dev/null 2>&1; then
        warn "Étape skippée (précondition non remplie ou fichier manquant)."
        add_result "$name" "—" "SKIP"
        return 0
    fi

    local start_ts end_ts duration
    start_ts=$(date +%s.%N)

    # Exécute la commande ; ne sort pas immédiatement sur échec (on capture le code)
    set +e
    eval "$cmd"
    local rc=$?
    set -e

    end_ts=$(date +%s.%N)
    duration=$(awk -v s="$start_ts" -v e="$end_ts" 'BEGIN{printf "%.1f", e - s}')

    if [[ $rc -eq 0 ]]; then
        ok "Étape réussie en ${duration}s"
        add_result "$name" "${duration}s" "OK"
        return 0
    else
        err "Étape échouée (code $rc) après ${duration}s"
        add_result "$name" "${duration}s" "ÉCHEC"
        # Fail-fast : on arrête tout et on affiche le récap
        print_summary
        err "Gate interrompue par fail-fast à l'étape : $name"
        exit 1
    fi
}

# ═════════════════════════════════════════════════════════════════════════════
# ÉTAPE 1 — Lint PHP sur les fichiers modifiés
# ═════════════════════════════════════════════════════════════════════════════
step_lint_php() {
    info "Collecte des fichiers PHP modifiés (git diff --name-only HEAD + staged)…"
    local files=()
    # Fichiers stagés ou récemment modifiés
    while IFS= read -r line; do
        [[ -z "$line" ]] && continue
        [[ "$line" == *.php ]] || continue
        [[ -f "$line" ]] || continue
        files+=("$line")
    done < <(git diff --name-only HEAD 2>/dev/null; git diff --name-only --cached 2>/dev/null)

    # Déduplique (au cas où git renvoie des doublons)
    local unique_files=()
    if [[ ${#files[@]} -gt 0 ]]; then
        while IFS= read -r line; do
            [[ -n "$line" ]] && unique_files+=("$line")
        done < <(printf '%s\n' "${files[@]}" | sort -u)
    fi

    if [[ ${#unique_files[@]} -eq 0 ]]; then
        info "Aucun fichier PHP modifié — rien à lint (étape triviale OK)."
        return 0
    fi

    info "Lint sur ${#unique_files[@]} fichier(s) PHP modifié(s)."
    local total=0 errors=0
    for f in "${unique_files[@]}"; do
        total=$((total + 1))
        local out
        out=$("$PHP_BIN" -l "$f" 2>&1) || {
            err "$f :"
            printf '%s\n' "$out" | sed 's/^/    /' >&2
            errors=$((errors + 1))
        }
    done

    info "Lint PHP terminé : $total fichier(s) vérifié(s), $errors erreur(s) de syntaxe."
    [[ $errors -eq 0 ]]
}

run_step "1. Lint PHP (php -l sur fichiers modifiés)" \
    "true" \
    "step_lint_php"

# ═════════════════════════════════════════════════════════════════════════════
# ÉTAPE 2 — PHPStan (analyse statique niveau 6 + baseline)
# ═════════════════════════════════════════════════════════════════════════════
# PHPStan = "compilateur" pour PHP. Détecte variables undefined, types
# incorrects, null derefs, etc. SANS exécuter le code. C'est ce qui aurait
# attrapé le bug validate.php L22 (extra }) et $tk['step_id'] absent du SELECT.
run_step "2. PHPStan (analyse statique niveau 6)" \
    "test -f phpstan.neon" \
    "bash scripts/run_phpstan.sh"

# ═════════════════════════════════════════════════════════════════════════════
# ÉTAPE 3 — Tests PHP existants (tests/test_all.php)
# ═════════════════════════════════════════════════════════════════════════════
run_step "3. Tests PHP existants (tests/test_all.php)" \
    "test -f tests/test_all.php" \
    "$PHP_BIN tests/test_all.php"

# ═════════════════════════════════════════════════════════════════════════════
# ÉTAPE 3b — Tests d'échappement des emails (tests/test_mail_escaping.php)
# Vérifie qu'il n'y a pas de double-escaping (&#039; → &amp;#039;) dans les
# emails — bug historique qui affichait des &#039; littéraux aux utilisateurs.
# ═════════════════════════════════════════════════════════════════════════════
run_step "3b. Tests échappement emails (test_mail_escaping.php)" \
    "test -f tests/test_mail_escaping.php" \
    "$PHP_BIN tests/test_mail_escaping.php"

# ═════════════════════════════════════════════════════════════════════════════
# ÉTAPE 3e — Tests URLs d'email (tests/test_email_urls.php)
# Vérifie que les liens d'email utilisent index.php?p=xxx et non xxx.php
# ═════════════════════════════════════════════════════════════════════════════
run_step "3e. Tests URLs d'email (test_email_urls.php)" \
    "test -f tests/test_email_urls.php" \
    "$PHP_BIN tests/test_email_urls.php"

# ═════════════════════════════════════════════════════════════════════════════
# ÉTAPE 3f — Audit exhaustif : AUCUN lien cassé dans tout le code
# Scanne TOUS les fichiers PHP et JS pour détecter :
# - href="xxx.php" au lieu de href="index.php?p=xxx"
# - action="xxx.php" cassés
# - header Location vers xxx.php
# - resolve_base_url() avec /xxx.php
# - __DIR__ cassé dans pages/
# - URLs .php dans fonctions email
# - JS fetch/location vers xxx.php
# - href="?xxx" (relatifs qui perdent p=)
# - index.php?p=xxx?yyy (? au lieu de &)
# ═════════════════════════════════════════════════════════════════════════════
run_step "3f. Audit exhaustif liens cassés (test_no_broken_urls.php)" \
    "test -f tests/test_no_broken_urls.php" \
    "$PHP_BIN tests/test_no_broken_urls.php"

# ═════════════════════════════════════════════════════════════════════════════
# ÉTAPE 3c — Tests PHPMailer warnings (tests/test_phpmailer_warnings.php)
# Vérifie que l'instanciation de PHPMailer ne génère AUCUN warning/deprecated
# PHP (ex: propriété dynamique Timelimit en PHP 8.4).
# ═════════════════════════════════════════════════════════════════════════════
run_step "3c. Tests PHPMailer warnings (test_phpmailer_warnings.php)" \
    "test -f tests/test_phpmailer_warnings.php" \
    "$PHP_BIN tests/test_phpmailer_warnings.php"

# ═════════════════════════════════════════════════════════════════════════════
# ÉTAPE 3d — Tests assets + cache HTTP (tests/test_assets_cache.php)
# Vérifie qu'aucun asset online n'est référencé, que assets.php renvoie les
# bons Content-Type + ETag + Cache-Control, et que le 304 Not Modified marche.
# ═════════════════════════════════════════════════════════════════════════════
run_step "3d. Tests assets + cache HTTP (test_assets_cache.php)" \
    "test -f tests/test_assets_cache.php" \
    "$PHP_BIN tests/test_assets_cache.php"

# ═════════════════════════════════════════════════════════════════════════════
# ÉTAPE 4 — Tests de rendu HTML (tests/test_form_render_html.php)
# ═════════════════════════════════════════════════════════════════════════════
run_step "4. Tests de rendu HTML (tests/test_form_render_html.php)" \
    "test -f tests/test_form_render_html.php" \
    "$PHP_BIN tests/test_form_render_html.php"

# ═════════════════════════════════════════════════════════════════════════════
# ÉTAPE 5 — Tests structurels HTML (tests/StructuralHtmlTest.php)
# ═════════════════════════════════════════════════════════════════════════════
run_step "5. Tests structurels HTML (tests/StructuralHtmlTest.php)" \
    "test -f tests/StructuralHtmlTest.php" \
    "$PHP_BIN tests/StructuralHtmlTest.php"

# ═════════════════════════════════════════════════════════════════════════════
# ÉTAPE 6 — Tests de non-régression (tests/regression/run_all.php)
# ═════════════════════════════════════════════════════════════════════════════
run_step "6. Tests de non-régression (tests/regression/run_all.php)" \
    "test -f tests/regression/run_all.php" \
    "$PHP_BIN tests/regression/run_all.php"

# ═════════════════════════════════════════════════════════════════════════════
# ÉTAPE 7 — Tests e2e Playwright (tests/test_e2e_full_flow.js)
# ═════════════════════════════════════════════════════════════════════════════
if [[ -z "$NODE_BIN" ]]; then
    printf "\n${C_BOLD}─── 7. Tests e2e Playwright (tests/test_e2e_full_flow.js) ───${C_RESET}\n"
    warn "Node.js indisponible — étape skippée."
    add_result "7. Tests e2e Playwright (tests/test_e2e_full_flow.js)" "—" "SKIP"
elif [[ "$PLAYWRIGHT_AVAILABLE" != "yes" ]]; then
    printf "\n${C_BOLD}─── 7. Tests e2e Playwright (tests/test_e2e_full_flow.js) ───${C_RESET}\n"
    warn "Playwright indisponible — étape skippée."
    add_result "7. Tests e2e Playwright (tests/test_e2e_full_flow.js)" "—" "SKIP"
else
    run_step "7. Tests e2e Playwright (tests/test_e2e_full_flow.js)" \
        "test -f tests/test_e2e_full_flow.js" \
        "$NODE_BIN tests/test_e2e_full_flow.js"
fi

# ─── Récapitulatif final ─────────────────────────────────────────────────────
print_summary
exit 0
