#!/usr/bin/env bash
# move_page.sh — Déplace une page de la racine vers pages/ et met à jour les liens.
#
# Usage : bash scripts/move_page.sh <page_name>
# Exemple : bash scripts/move_page.sh dashboard
#
# Ce script :
#   1. Déplace <page_name>.php vers pages/<page_name>.php
#   2. Corrige __DIR__ → dirname(__DIR__) dans le fichier déplacé
#   3. Met à jour tous les liens href/action/'xxx.php' → 'index.php?p=xxx'
#   4. Supprime le fichier original de la racine
set -euo pipefail

PAGE="$1"
if [[ -z "$PAGE" ]]; then
    echo "Usage: bash scripts/move_page.sh <page_name>"
    exit 1
fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

SRC="${PAGE}.php"
DST="pages/${PAGE}.php"

if [[ ! -f "$SRC" ]]; then
    echo "❌ $SRC n'existe pas"
    exit 1
fi

echo "=== Déplacement $SRC → $DST ==="

# 1. Copier vers pages/
cp "$SRC" "$DST"

# 2. Corriger les chemins __DIR__ → dirname(__DIR__)
sed -i "s|__DIR__ \. '/helpers\.php'|dirname(__DIR__) . '/helpers.php'|g" "$DST"
sed -i "s|__DIR__ \. '/lib/|dirname(__DIR__) . '/lib/|g" "$DST"
sed -i "s|__DIR__ \. '/classes/|dirname(__DIR__) . '/classes/|g" "$DST"
sed -i "s|__DIR__ \. '/config\.php'|dirname(__DIR__) . '/config.php'|g" "$DST"
sed -i "s|__DIR__ \. '/assets\.php'|dirname(__DIR__) . '/assets.php'|g" "$DST"
sed -i "s|__DIR__ \. '/vendor/|dirname(__DIR__) . '/vendor/|g" "$DST"

# 3. Mettre à jour tous les liens internes vers cette page
# href="dashboard.php" → href="index.php?p=dashboard"
# action="dashboard.php" → action="index.php?p=dashboard"
# 'dashboard.php' → 'index.php?p=dashboard'
# "dashboard.php" → "index.php?p=dashboard"
# Mais NE PAS remplacer dans le fichier pages/<page>.php lui-même pour les require
files=$(grep -rl "href=\"${PAGE}\.php\|action=\"${PAGE}\.php\|'${PAGE}\.php\|\"${PAGE}\.php" \
    --include="*.php" --include="*.js" . 2>/dev/null \
    | grep -v vendor/ | grep -v "/tests/" | grep -v "pages/${PAGE}.php" || true)

for f in $files; do
    sed -i "s|href=\"${PAGE}\.php|href=\"index.php?p=${PAGE}|g" "$f"
    sed -i "s|action=\"${PAGE}\.php|action=\"index.php?p=${PAGE}|g" "$f"
    sed -i "s|'${PAGE}\.php'|'index.php?p=${PAGE}'|g" "$f"
    sed -i "s|\"${PAGE}\.php\"|\"index.php?p=${PAGE}\"|g" "$f"
    echo "  Liens mis à jour: $f"
done

# Aussi mettre à jour les liens dans pages/<page>.php lui-même (self-references)
sed -i "s|href=\"${PAGE}\.php|href=\"index.php?p=${PAGE}|g" "$DST"
sed -i "s|action=\"${PAGE}\.php|action=\"index.php?p=${PAGE}|g" "$DST"

# 4. Supprimer l'original
rm "$SRC"
echo "  ✅ $SRC → $DST"
