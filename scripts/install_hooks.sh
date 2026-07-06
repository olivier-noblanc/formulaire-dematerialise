#!/usr/bin/env bash
# =============================================================================
# install_hooks.sh — Installe le hook pre-push dans .git/hooks/.
#
# Copie scripts/pre-push vers .git/hooks/pre-push et le rend exécutable.
# Idempotent : écrase si existe déjà.
#
# Compatible Linux + Git-for-Windows.
#
# Usage : bash scripts/install_hooks.sh
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

# ─── Trouver le dossier .git ─────────────────────────────────────────────────
# Cas 1 : .git est un dossier (clone classique)
# Cas 2 : .git est un fichier (worktree) — on lit gitdir: <path>
# Cas 3 : on utilise `git rev-parse --git-dir` qui gère tout ça.
GIT_DIR="$(git rev-parse --git-dir 2>/dev/null || true)"
if [[ -z "$GIT_DIR" ]]; then
    err "Aucun dépôt git trouvé. Lancez ce script depuis un clone du projet."
    exit 1
fi
# Résout en chemin absolu (relatif au working tree)
GIT_DIR="$(cd "$GIT_DIR" && pwd)"
HOOKS_DIR="$GIT_DIR/hooks"
info "Dossier hooks git : $HOOKS_DIR"

# ─── Créer le dossier hooks s'il n'existe pas ────────────────────────────────
if [[ ! -d "$HOOKS_DIR" ]]; then
    mkdir -p "$HOOKS_DIR"
    info "Dossier hooks créé."
fi

# ─── Source du hook ──────────────────────────────────────────────────────────
SOURCE_HOOK="$PROJECT_ROOT/scripts/pre-push"
if [[ ! -f "$SOURCE_HOOK" ]]; then
    err "Source du hook introuvable : $SOURCE_HOOK"
    exit 1
fi

# ─── Copier (écrase si existe) ───────────────────────────────────────────────
DEST_HOOK="$HOOKS_DIR/pre-push"
if [[ -f "$DEST_HOOK" ]]; then
    warn "Un hook pre-push existait déjà — il sera écrasé."
    rm -f "$DEST_HOOK"
fi
cp "$SOURCE_HOOK" "$DEST_HOOK"

# ─── Rendre exécutable (chmod +x) ────────────────────────────────────────────
# Sous Git-for-Windows, chmod +x est compris par Git (le bit est stocké dans
# l'index). Sous Linux/Mac, c'est un vrai chmod.
chmod +x "$DEST_HOOK" 2>/dev/null || warn "chmod +x a échoué (continuons quand même)."

# ─── Vérification finale ─────────────────────────────────────────────────────
if [[ ! -x "$DEST_HOOK" ]]; then
    # Sur certains systèmes de fichiers Windows, -x est peu fiable.
    # On accepte si le fichier existe et est lisible.
    if [[ -f "$DEST_HOOK" ]]; then
        warn "Le hook n'apparaît pas comme exécutable (-x), mais le fichier est bien en place."
        warn "Sous Windows, Git exécutera le hook via bash s'il porte l'en-tête #!/usr/bin/env bash."
    else
        err "Échec de l'installation du hook."
        exit 1
    fi
fi

ok "Hook pre-push installé : $DEST_HOOK"
ok "Le hook s'exécutera AVANT chaque 'git push' vers origin/master ou origin/dev."
info "Pour tester : bash scripts/gate.sh (sans push)"
info "Pour bypasser (déconseillé) : git push --no-verify"
exit 0
