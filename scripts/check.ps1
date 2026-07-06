# =============================================================================
# check.ps1 — Miroir Windows de gate.sh (PowerShell)
#
# Orchestrateur de la "gate" qualité (exécuté avant chaque push sur Windows).
# Même logique que scripts/gate.sh : 6 étapes, fail-fast, récapitulatif final.
#
# Usage :
#   powershell -ExecutionPolicy Bypass -File scripts\check.ps1
#   # ou depuis une fenêtre PowerShell :
#   PS> .\scripts\check.ps1
# =============================================================================
# PowerShell 5.1+ (fourni avec Windows 10/11) ou PowerShell 7+.
# -----------------------------------------------------------------------------
[CmdletBinding()]
param()

# ─── Strict mode + arrêt sur erreur ──────────────────────────────────────────
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version 3.0

# ─── Se positionner à la racine du projet ────────────────────────────────────
Set-Location -Path (Join-Path $PSScriptRoot '..')
$ProjectRoot = (Get-Location).Path

# ─── Couleurs ANSI (PowerShell 5.1+ supporte les escapes ANSI) ───────────────
$ESC = [char]27
$C_GREEN  = "$ESC[32m"
$C_RED    = "$ESC[31m"
$C_YELLOW = "$ESC[33m"
$C_CYAN   = "$ESC[36m"
$C_BOLD   = "$ESC[1m"
$C_RESET  = "$ESC[0m"

# ─── Helpers ─────────────────────────────────────────────────────────────────
function Info { param([string]$Msg) Write-Host "$C_CYAN[INFO]$C_RESET $Msg" }
function Warn { param([string]$Msg) Write-Host "$C_YELLOW[WARN]$C_RESET $Msg" }
function Err  { param([string]$Msg) Write-Host "$C_RED[ERREUR]$C_RESET $Msg" }
function Ok   { param([string]$Msg) Write-Host "$C_GREEN[OK]$C_RESET $Msg" }

# Stockage des résultats
$script:Results = New-Object System.Collections.Generic.List[object]

function Add-Result {
    param([string]$Step, [string]$Duration, [string]$Status)
    $script:Results.Add([pscustomobject]@{
        Step     = $Step
        Duration = $Duration
        Status   = $Status
    })
}

function Print-Summary {
    Write-Host ""
    Write-Host "$C_BOLD$([string]::new('=', 75))$C_RESET"
    Write-Host "$C_BOLD  RÉCAPITULATIF DE LA GATE QUALITÉ$C_RESET"
    Write-Host "$C_BOLD$([string]::new('=', 75))$C_RESET"
    Write-Host ("  {0,-45} | {1,-10} | {2,-10}" -f "ÉTAPE", "DURÉE", "STATUT")
    Write-Host "  ---------------------------------------------+------------+------------"

    $globalFailed = $false
    foreach ($r in $script:Results) {
        $color = $C_GREEN
        if ($r.Status -eq "ÉCHEC") { $color = $C_RED; $globalFailed = $true }
        elseif ($r.Status -eq "SKIP") { $color = $C_YELLOW }
        Write-Host ("  {0,-45} | {1,-10} | {2}{3,-10}{4}" -f $r.Step, $r.Duration, $color, $r.Status, $C_RESET)
    }
    Write-Host "  ---------------------------------------------+------------+------------"
    if (-not $globalFailed) {
        Write-Host "$C_BOLD$C_GREEN  GATE : SUCCÈS — push autorisé$C_RESET"
    } else {
        Write-Host "$C_BOLD$C_RED  GATE : ÉCHEC — push BLOQUÉ$C_RESET"
    }
    Write-Host "$C_BOLD$([string]::new('=', 75))$C_RESET"
}

# ─── Détection des dépendances ───────────────────────────────────────────────
$PhpBin = (Get-Command php -ErrorAction SilentlyContinue).Source
$NodeBin = (Get-Command node -ErrorAction SilentlyContinue).Source
$PlaywrightAvailable = $false

if (-not $PhpBin) {
    Err "PHP introuvable dans le PATH — dépendance critique manquante."
    Err "Installez PHP 8.4+ et ajoutez-le au PATH."
    exit 2
}
Info "PHP détecté : $(& $PhpBin -v | Select-Object -First 1)"

if (-not $NodeBin) {
    Warn "Node.js introuvable dans le PATH — tests Playwright e2e seront skippés."
    $NodeBin = $null
} else {
    Info "Node.js détecté : $(& $NodeBin --version)"
    # Playwright : on tente require('playwright') dans le dossier courant
    & $NodeBin -e "require.resolve('playwright')" 2>$null
    if ($LASTEXITCODE -eq 0) {
        $PlaywrightAvailable = $true
        Info "Playwright détecté (module résolvable)."
    } elseif (Get-Command playwright -ErrorAction SilentlyContinue) {
        $PlaywrightAvailable = $true
        Info "Playwright détecté (CLI global)."
    } else {
        Warn "Playwright introuvable — tests e2e Playwright seront skippés."
    }
}

# ─── Fonction : exécuter une étape ───────────────────────────────────────────
function Invoke-Step {
    param(
        [string]$Name,
        [scriptblock]$Precondition,
        [scriptblock]$Command
    )
    Write-Host ""
    Write-Host "$C_BOLD--- $Name ---$C_RESET"

    # Vérifie la précondition
    $canRun = $true
    if ($Precondition) {
        try {
            $result = & $Precondition
            if (-not $result) { $canRun = $false }
        } catch {
            $canRun = $false
        }
    }
    if (-not $canRun) {
        Warn "Étape skippée (précondition non remplie ou fichier manquant)."
        Add-Result -Step $Name -Duration "—" -Status "SKIP"
        return $true
    }

    $start = Get-Date
    & $Command
    $rc = $LASTEXITCODE
    $duration = ((Get-Date) - $start).TotalSeconds
    $durStr = "{0:N1}s" -f $duration

    if ($rc -eq 0) {
        Ok "Étape réussie en $durStr"
        Add-Result -Step $Name -Duration $durStr -Status "OK"
        return $true
    } else {
        Err "Étape échouée (code $rc) après $durStr"
        Add-Result -Step $Name -Duration $durStr -Status "ÉCHEC"
        Print-Summary
        Err "Gate interrompue par fail-fast à l'étape : $Name"
        exit 1
    }
}

# ═════════════════════════════════════════════════════════════════════════════
# ÉTAPE 1 — Lint PHP sur fichiers modifiés
# ═════════════════════════════════════════════════════════════════════════════
$lintScript = {
    Info "Collecte des fichiers PHP modifiés (git diff --name-only HEAD + staged)…"
    $files = @()
    $files += git diff --name-only HEAD 2>$null
    $files += git diff --name-only --cached 2>$null

    $phpFiles = $files | Where-Object { $_ -and $_ -match '\.php$' } | Sort-Object -Unique
    $phpFiles = $phpFiles | Where-Object { Test-Path $_ -PathType Leaf }

    if (-not $phpFiles -or $phpFiles.Count -eq 0) {
        Info "Aucun fichier PHP modifié — rien à lint (étape triviale OK)."
        return
    }

    Info "Lint sur $($phpFiles.Count) fichier(s) PHP modifié(s)."
    $total = 0; $errors = 0
    foreach ($f in $phpFiles) {
        $total++
        $out = & $PhpBin -l $f 2>&1
        if ($LASTEXITCODE -ne 0) {
            Err "$f :"
            $out | ForEach-Object { Write-Host "    $_" }
            $errors++
        }
    }
    Info "Lint PHP terminé : $total fichier(s) vérifié(s), $errors erreur(s) de syntaxe."
    if ($errors -ne 0) { exit 1 }
}

Invoke-Step -Name "1. Lint PHP (php -l sur fichiers modifiés)" `
            -Precondition { $true } `
            -Command $lintScript

# ═════════════════════════════════════════════════════════════════════════════
# ÉTAPE 2 — Tests PHP existants
# ═════════════════════════════════════════════════════════════════════════════
Invoke-Step -Name "2. Tests PHP existants (tests/test_all.php)" `
            -Precondition { Test-Path 'tests/test_all.php' } `
            -Command { & $PhpBin tests/test_all.php }

# ═════════════════════════════════════════════════════════════════════════════
# ÉTAPE 3 — Tests de rendu HTML
# ═════════════════════════════════════════════════════════════════════════════
Invoke-Step -Name "3. Tests de rendu HTML (tests/test_form_render_html.php)" `
            -Precondition { Test-Path 'tests/test_form_render_html.php' } `
            -Command { & $PhpBin tests/test_form_render_html.php }

# ═════════════════════════════════════════════════════════════════════════════
# ÉTAPE 4 — Tests structurels HTML
# ═════════════════════════════════════════════════════════════════════════════
Invoke-Step -Name "4. Tests structurels HTML (tests/StructuralHtmlTest.php)" `
            -Precondition { Test-Path 'tests/StructuralHtmlTest.php' } `
            -Command { & $PhpBin tests/StructuralHtmlTest.php }

# ═════════════════════════════════════════════════════════════════════════════
# ÉTAPE 5 — Tests de non-régression
# ═════════════════════════════════════════════════════════════════════════════
Invoke-Step -Name "5. Tests de non-régression (tests/regression/run_all.php)" `
            -Precondition { Test-Path 'tests/regression/run_all.php' } `
            -Command { & $PhpBin tests/regression/run_all.php }

# ═════════════════════════════════════════════════════════════════════════════
# ÉTAPE 6 — Tests e2e Playwright
# ═════════════════════════════════════════════════════════════════════════════
if (-not $NodeBin) {
    Write-Host ""
    Write-Host "$C_BOLD--- 6. Tests e2e Playwright (tests/test_e2e_full_flow.js) ---$C_RESET"
    Warn "Node.js indisponible — étape skippée."
    Add-Result -Step "6. Tests e2e Playwright (tests/test_e2e_full_flow.js)" -Duration "—" -Status "SKIP"
} elseif (-not $PlaywrightAvailable) {
    Write-Host ""
    Write-Host "$C_BOLD--- 6. Tests e2e Playwright (tests/test_e2e_full_flow.js) ---$C_RESET"
    Warn "Playwright indisponible — étape skippée."
    Add-Result -Step "6. Tests e2e Playwright (tests/test_e2e_full_flow.js)" -Duration "—" -Status "SKIP"
} else {
    Invoke-Step -Name "6. Tests e2e Playwright (tests/test_e2e_full_flow.js)" `
                -Precondition { Test-Path 'tests/test_e2e_full_flow.js' } `
                -Command { & $NodeBin tests/test_e2e_full_flow.js }
}

# ─── Récapitulatif final ─────────────────────────────────────────────────────
Print-Summary
exit 0
