#!/usr/bin/env bash
# =============================================================================
# audit_undefined.sh — Wrapper bash autour de scripts/audit_undefined.php
#
# Le script PHP scanne les accès à des clés potentiellement undefined dans
# les tableaux issus de SELECT SQL. Sort avec code ≠ 0 si > 0 problèmes
# détectés, MAIS n'échoue pas la gate (informatif uniquement).
#
# Usage : bash scripts/audit_undefined.sh [path]
#   path : dossier à scanner (défaut : racine du projet)
# =============================================================================
set -euo pipefail

# ─── Se positionner à la racine du projet ────────────────────────────────────
cd "$(dirname "$0")/.."
PROJECT_ROOT="$(pwd)"

# ─── Couleurs ANSI ───────────────────────────────────────────────────────────
if [[ -t 1 ]]; then
    C_GREEN='\033[32m'; C_RED='\033[31m'; C_YELLOW='\033[33m'
    C_CYAN='\033[36m';  C_BOLD='\033[1m'; C_RESET='\033[0m'
else
    C_GREEN=''; C_RED=''; C_YELLOW=''; C_CYAN=''; C_BOLD=''; C_RESET=''
fi

info() { printf "${C_CYAN}[INFO]${C_RESET} %s\n" "$*"; }
warn() { printf "${C_YELLOW}[WARN]${C_RESET} %s\n" "$*"; }
err()  { printf "${C_RED}[ERREUR]${C_RESET} %s\n" "$*" >&2; }
ok()   { printf "${C_GREEN}[OK]${C_RESET} %s\n" "$*"; }

# ─── Vérifier que PHP est disponible ─────────────────────────────────────────
if ! command -v php >/dev/null 2>&1; then
    err "PHP introuvable dans le PATH — audit impossible."
    exit 2
fi

# ─── Cible du scan ───────────────────────────────────────────────────────────
TARGET="${1:-.}"
if [[ ! -d "$TARGET" ]]; then
    err "Le dossier cible n'existe pas : $TARGET"
    exit 1
fi

# ─── Vérifier que audit_undefined.php est présent ────────────────────────────
AUDIT_PHP="$PROJECT_ROOT/scripts/audit_undefined.php"
if [[ ! -f "$AUDIT_PHP" ]]; then
    err "Script PHP introuvable : $AUDIT_PHP"
    exit 1
fi

# ─── Exécuter l'audit ────────────────────────────────────────────────────────
printf "${C_BOLD}══════════════════════════════════════════════════════════════════════${C_RESET}\n"
printf "${C_BOLD}  AUDIT STATIQUE — Variables / Clés potentiellement undefined${C_RESET}\n"
printf "${C_BOLD}══════════════════════════════════════════════════════════════════════${C_RESET}\n"
info "Cible : $TARGET"
info "Lancement : php $AUDIT_PHP $TARGET"
echo

set +e
php "$AUDIT_PHP" "$TARGET"
rc=$?
set -e

echo
if [[ $rc -eq 0 ]]; then
    ok "Audit terminé : aucun problème détecté."
    exit 0
elif [[ $rc -eq 2 ]]; then
    # audit_undefined.php retourne 2 si des problèmes ont été détectés
    warn "Audit terminé : des problèmes ont été détectés (code retour 2)."
    warn "⚠️  Cet audit est INFORMATIF — il n'échoue pas la gate."
    warn "    Corrigez les accès suspectés (utilisez ?? isset() ou array_key_exists())."
    # On retourne 0 pour ne PAS casser la gate (informatif uniquement).
    # Si l'appelant veut le code exact, il peut appeler php audit_undefined.php directement.
    exit 0
else
    err "Audit terminé avec une erreur inattendue (code $rc)."
    exit $rc
fi
