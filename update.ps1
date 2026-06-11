# update.ps1 — Script de mise a jour automatique du Workflow DREETS
# Utilise git (deja configure avec proxy/credentials sur le poste)
#
# Usage :
#   .\update.ps1              # Mise a jour normale (sauvegarde automatique)
#   .\update.ps1 -DryRun      # Simule la mise a jour sans rien modifier
#   .\update.ps1 -SkipBackup  # Pas de sauvegarde (dangereux)
#
# Prerequis : PowerShell 5.1+, git installe et configure
# Compatible Windows Server / IIS

param(
    [switch]$DryRun     = $false,
    [switch]$SkipBackup = $false
)

# Fichiers a proteger (jamais ecrases par git pull)
$ProtectedFiles = @(
    "config.php"
)

# Repertoire du script = racine de l'application
$AppRoot = $PSScriptRoot
if (-not $AppRoot) { $AppRoot = (Get-Location).Path }

# ── Fonctions utilitaires ──────────────────────────────────────

function Write-Status {
    param([string]$Icon, [string]$Message, [string]$Color = "White")
    Write-Host "  $Icon $Message" -ForegroundColor $Color
}

function Write-Section {
    param([string]$Title)
    Write-Host ""
    Write-Host "  -- $Title --" -ForegroundColor Cyan
}

function Get-LocalVersion {
    $configPath = Join-Path $AppRoot "config.php"
    if (Test-Path $configPath) {
        $content = Get-Content $configPath -Raw -ErrorAction SilentlyContinue
        if ($content -match "APP_VERSION.*?'([^']+)'") {
            return $Matches[1]
        }
    }
    return "inconnue"
}

function Create-Backup {
    param([string]$SourceDir)

    $timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $backupDir = Join-Path $SourceDir "backups\backup-$timestamp"

    try {
        New-Item -ItemType Directory -Path $backupDir -Force | Out-Null

        # Copier les fichiers PHP, MD, CSS, JS, ps1 (pas db/, sessions/, .git/, etc.)
        $extensions = @("*.php", "*.md", "*.css", "*.js", "*.ps1")
        foreach ($ext in $extensions) {
            $files = Get-ChildItem -Path $SourceDir -Filter $ext -Recurse -File |
                Where-Object { $_.FullName -notmatch '\\(db|sessions|vendor|logs|\.git|\.history|backups)\\' }

            foreach ($file in $files) {
                $relativePath = $file.FullName.Substring($SourceDir.Length + 1)
                $destPath = Join-Path $backupDir $relativePath
                $destDir = Split-Path -Parent $destPath
                if (-not (Test-Path $destDir)) {
                    New-Item -ItemType Directory -Path $destDir -Force | Out-Null
                }
                Copy-Item -Path $file.FullName -Destination $destPath -Force
            }
        }

        Write-Status "OK" "Sauvegarde creee : backups\backup-$timestamp" "Green"
        return $backupDir
    }
    catch {
        Write-Status "X" "Echec de la sauvegarde : $_" "Red"
        return $null
    }
}

# ── Programme principal ────────────────────────────────────────

Clear-Host
Write-Host ""
Write-Host "  =====================================================" -ForegroundColor DarkBlue
Write-Host "    Workflow DREETS -- Mise a jour automatique (git)" -ForegroundColor DarkBlue
Write-Host "  =====================================================" -ForegroundColor DarkBlue
Write-Host ""

# 1. Verifier que git est disponible
Write-Section "Verification de l'environnement"
try {
    $gitVersion = & git --version 2>&1
    if ($LASTEXITCODE -ne 0) { throw "git non trouve" }
    Write-Status "OK" "Git detecte : $gitVersion" "Green"
}
catch {
    Write-Status "X" "Git n'est pas installe ou pas dans le PATH. Abandon." "Red"
    exit 1
}

# Verifier qu'on est dans un depot git
if (-not (Test-Path (Join-Path $AppRoot ".git"))) {
    Write-Status "X" "Ce repertoire n'est pas un depot git. Abandon." "Red"
    Write-Status "!" "Clonez d'abord le depot : git clone https://github.com/olivier-noblanc/formulaire-dematerialise.git" "Yellow"
    exit 1
}

# 2. Version locale
Write-Section "Version locale"
$localVersion = Get-LocalVersion
Write-Status ">" "Version installee : v$localVersion"

# 3. git fetch pour voir les nouveautes
Write-Section "Verification des mises a jour distantes"
Push-Location $AppRoot

try {
    & git fetch origin 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "git fetch echoue" }
    Write-Status "OK" "git fetch origin : OK" "Green"
}
catch {
    Write-Status "X" "Impossible de contacter le depot distant. Verifiez la connexion/proxy." "Red"
    Pop-Location
    exit 1
}

# Comparer HEAD avec origin/master
$branch = & git rev-parse --abbrev-ref HEAD 2>&1
if ($branch -ne "master") {
    Write-Status "!" "Branche actuelle : $branch (master attendu)" "Yellow"
}

$localHash  = & git rev-parse HEAD 2>&1
$remoteHash = & git rev-parse "origin/master" 2>&1

if ($localHash -eq $remoteHash) {
    Write-Status "OK" "Vous etes deja a jour (v$localVersion)." "Green"
    Pop-Location
    exit 0
}

# Afficher le nombre de commits de retard
$behindCount = & git rev-list --count "HEAD..origin/master" 2>&1
Write-Status "!" "Votre version est en retard de $behindCount commit(s)" "Yellow"

# Afficher les nouveaux commits
Write-Section "Nouveaux commits disponibles"
& git log --oneline "HEAD..origin/master" 2>&1 | ForEach-Object {
    Write-Status "  " $_ "DarkGray"
}

# 4. DryRun
if ($DryRun) {
    Write-Host ""
    Write-Status "?" "Mode simulation (DryRun) : aucune modification effectuee." "Yellow"
    Pop-Location
    exit 0
}

# 5. Sauvegarde
if (-not $SkipBackup) {
    Write-Section "Sauvegarde"
    $backupPath = Create-Backup -SourceDir $AppRoot
    if (-not $backupPath) {
        Write-Status "X" "Sauvegarde echouee. Mise a jour annulee." "Red"
        Pop-Location
        exit 1
    }
}
else {
    Write-Status "!" "Sauvegarde ignoree (-SkipBackup)" "Yellow"
}

# 6. Protéger les fichiers locaux (config.php, etc.)
Write-Section "Protection des fichiers locaux"

# Sauvegarder les fichiers proteges dans un dossier temporaire
$tempDir = Join-Path $AppRoot ".update_tmp"
if (Test-Path $tempDir) { Remove-Item -Path $tempDir -Recurse -Force }
New-Item -ItemType Directory -Path $tempDir -Force | Out-Null

foreach ($file in $ProtectedFiles) {
    $src = Join-Path $AppRoot $file
    if (Test-Path $src) {
        Copy-Item -Path $src -Destination (Join-Path $tempDir $file) -Force
        Write-Status "OK" "Protege : $file" "Green"
    }
}

# 7. git pull
Write-Section "Mise a jour (git pull)"
try {
    $pullOutput = & git pull origin master 2>&1
    if ($LASTEXITCODE -ne 0) { throw $pullOutput }

    # Afficher le resultat du pull
    $pullOutput | ForEach-Object {
        Write-Status "  " $_ "DarkGray"
    }
    Write-Status "OK" "git pull : reussi" "Green"
}
catch {
    Write-Status "X" "git pull echoue : $_" "Red"

    # Restaurer les fichiers proteges quand meme
    foreach ($file in $ProtectedFiles) {
        $tmp = Join-Path $tempDir $file
        if (Test-Path $tmp) {
            Copy-Item -Path $tmp -Destination (Join-Path $AppRoot $file) -Force
        }
    }
    Remove-Item -Path $tempDir -Recurse -Force -ErrorAction SilentlyContinue
    Pop-Location
    exit 1
}

# 8. Restaurer les fichiers proteges
Write-Section "Restauration des fichiers locaux"
foreach ($file in $ProtectedFiles) {
    $tmp = Join-Path $tempDir $file
    $dest = Join-Path $AppRoot $file
    if (Test-Path $tmp) {
        Copy-Item -Path $tmp -Destination $dest -Force
        Write-Status "OK" "Restaure : $file (version locale conservee)" "Green"
    }
}

# Nettoyer le dossier temporaire
Remove-Item -Path $tempDir -Recurse -Force -ErrorAction SilentlyContinue

# 9. Verifier la nouvelle version
Write-Section "Resultat"
$newVersion = Get-LocalVersion
if ($newVersion -ne $localVersion) {
    Write-Status "OK" "Version mise a jour : v$localVersion -> v$newVersion" "Green"
}
else {
    Write-Status "OK" "Mise a jour appliquee (v$newVersion)" "Green"
}

# 10. Instructions post-mise a jour
Write-Host ""
Write-Section "Post-mise a jour"
Write-Status "!" "Verifiez que l'application fonctionne correctement." "White"
Write-Status "!" "En cas de probleme, restaurez la sauvegarde dans backups/" "White"
Write-Status "!" "config.php n'a PAS ete ecrase (version locale conservee)." "White"
Write-Status "!" "Si de nouveaux parametres existent dans config.php, ajoutez-les manuellement." "White"

# 11. Nettoyage des anciens backups
$backupsDir = Join-Path $AppRoot "backups"
if (Test-Path $backupsDir) {
    $oldBackups = Get-ChildItem -Path $backupsDir -Directory | Sort-Object CreationTime -Descending | Select-Object -Skip 5
    if ($oldBackups.Count -gt 0) {
        Write-Host ""
        Write-Status "!" "$($oldBackups.Count) ancienne(s) sauvegarde(s) trouvee(s) (5 conservees)." "Yellow"
        $clean = Read-Host "  Supprimer les anciennes sauvegardes ? (o/N)"
        if ($clean -match "^[oO]$") {
            foreach ($old in $oldBackups) {
                Remove-Item -Path $old.FullName -Recurse -Force
                Write-Status "  " "Supprime : $($old.Name)" "DarkGray"
            }
        }
    }
}

Pop-Location
Write-Host ""
Write-Status ">" "Fin du script de mise a jour." "White"
