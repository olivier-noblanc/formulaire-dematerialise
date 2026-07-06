#!/usr/bin/env bash
# scripts/run_phpstan.sh — wrapper PHPStan pour la gate locale + CI Woodpecker.
#
# - Si vendor/bin/phpstan.phar existe, l'utilise.
# - Sinon, télécharge le phar officiel depuis GitHub releases.
# - Exécute `php vendor/bin/phpstan.phar analyse --memory-limit=512M` à la racine.
# - Sort avec un code ≠ 0 si PHPStan détecte des erreurs hors baseline.
set -euo pipefail

# Résolution du répertoire projet (le script est dans scripts/)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_DIR"

PHP_BIN="${PHP:-php}"
VENDOR_BIN_DIR="$PROJECT_DIR/vendor/bin"
PHAR_PATH="$VENDOR_BIN_DIR/phpstan.phar"
PHAR_URL="https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar"

mkdir -p "$VENDOR_BIN_DIR"

# ── 1. Résolution du binaire PHPStan ──
PHPSTAN_CMD=""
if [[ -x "$VENDOR_BIN_DIR/phpstan" ]]; then
    PHPSTAN_CMD="$PHP_BIN $VENDOR_BIN_DIR/phpstan"
elif [[ -f "$PHAR_PATH" ]]; then
    PHPSTAN_CMD="$PHP_BIN $PHAR_PATH"
else
    echo "[run_phpstan] Téléchargement de phpstan.phar depuis GitHub..."
    if curl --fail --silent --show-error --max-time 90 -L "$PHAR_URL" -o "$PHAR_PATH" 2>/dev/null \
        || wget --quiet --timeout=90 -O "$PHAR_PATH" "$PHAR_URL" 2>/dev/null; then
        chmod +x "$PHAR_PATH" 2>/dev/null || true
        PHPSTAN_CMD="$PHP_BIN $PHAR_PATH"
        echo "[run_phpstan] Phar téléchargé : $PHAR_PATH"
    else
        echo "[run_phpstan] ❌ Impossible de télécharger phpstan.phar (pas d'accès Internet ?)."
        echo "[run_phpstan] Installe-le manuellement : wget $PHAR_URL -O $PHAR_PATH"
        exit 2
    fi
fi

# ── 2. Exécution de l'analyse ──
echo "[run_phpstan] Exécution : $PHPSTAN_CMD analyse --memory-limit=512M"
# shellcheck disable=SC2086
$PHPSTAN_CMD analyse --memory-limit=512M --no-progress
