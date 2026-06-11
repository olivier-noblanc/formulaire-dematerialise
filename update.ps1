# update.ps1 — Script de mise à jour automatique du Workflow DREETS
# Télécharge les derniers fichiers depuis le dépôt GitHub
#
# Usage :
#   .\update.ps1              # Mise à jour normale (sauvegarde automatique)
#   .\update.ps1 -DryRun      # Simule la mise à jour sans rien modifier
#   .\update.ps1 -SkipBackup  # Pas de sauvegarde (dangereux)
#
# Prérequis : PowerShell 5.1+, connexion Internet
# Compatible Windows Server / IIS

param(
    [switch]$DryRun     = $false,
    [switch]$SkipBackup = $false
)

# ── Configuration ──────────────────────────────────────────────
$RepoOwner  = "olivier-noblanc"
$RepoName   = "formulaire-dematerialise"
$Branch     = "master"
$ApiBaseUrl = "https://api.github.com/repos/$RepoOwner/$RepoName/contents"
$RawBaseUrl = "https://raw.githubusercontent.com/$RepoOwner/$RepoName/$Branch"

# Fichiers à ignorer (configuration locale, données, cache)
$ExcludeFiles = @(
    "config.php",
    "update.ps1",
    ".gitignore"
)

# Fichiers/dossiers protégés (jamais écrasés)
$ExcludeDirs = @(
    "db",
    "sessions",
    "vendor",
    "logs",
    ".git",
    ".history"
)

# Répertoire du script = racine de l'application
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
    Write-Host "  ── $Title ──" -ForegroundColor Cyan
}

function Get-RemoteFileList {
    param([string]$Path = "")
    
    $url = if ($Path) { "$ApiBaseUrl/$Path?ref=$Branch" } else { "$ApiBaseUrl`?ref=$Branch" }
    
    try {
        $headers = @{
            "User-Agent" = "DREETS-Workflow-Updater/1.0"
        }
        $response = Invoke-RestMethod -Uri $url -Headers $headers -Method Get -ErrorAction Stop
        return $response
    }
    catch {
        Write-Status "❌" "Impossible d'acceder a l'API GitHub : $_" "Red"
        return $null
    }
}

function Download-File {
    param(
        [string]$RemotePath,
        [string]$LocalPath
    )
    
    $url = "$RawBaseUrl/$RemotePath"
    
    try {
        # Créer le dossier parent si nécessaire
        $parentDir = Split-Path -Parent $LocalPath
        if (-not (Test-Path $parentDir)) {
            New-Item -ItemType Directory -Path $parentDir -Force | Out-Null
        }
        
        Invoke-WebRequest -Uri $url -OutFile $LocalPath -ErrorAction Stop
        return $true
    }
    catch {
        Write-Status "❌" "Echec du telechargement de $RemotePath : $_" "Red"
        return $false
    }
}

function Create-Backup {
    param([string]$SourceDir)
    
    $timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $backupDir = Join-Path $SourceDir "backups\backup-$timestamp"
    
    try {
        # Créer le dossier de backup
        New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
        
        # Copier les fichiers PHP, MD, CSS (pas db/, sessions/, .git/, etc.)
        $extensions = @("*.php", "*.md", "*.css", "*.js")
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
        
        Write-Status "✅" "Sauvegarde creee : backups\backup-$timestamp" "Green"
        return $backupDir
    }
    catch {
        Write-Status "❌" "Echec de la sauvegarde : $_" "Red"
        return $null
    }
}

# ── Programme principal ────────────────────────────────────────

Clear-Host
Write-Host ""
Write-Host "  ╔═══════════════════════════════════════════════════════════╗" -ForegroundColor DarkBlue
Write-Host "  ║          Workflow DREETS — Mise a jour automatique        ║" -ForegroundColor DarkBlue
Write-Host "  ╚═══════════════════════════════════════════════════════════╝" -ForegroundColor DarkBlue
Write-Host ""

# 1. Vérifier la connectivité
Write-Section "Verification de la connectivite"
try {
    $null = Invoke-RestMethod -Uri "https://api.github.com" -TimeoutSec 10 -ErrorAction Stop
    Write-Status "✅" "Connexion Internet OK" "Green"
}
catch {
    Write-Status "❌" "Pas de connexion Internet. Abandon." "Red"
    exit 1
}

# 2. Lire la version locale
Write-Section "Version locale"
$localVersion = "inconnue"
$configPath = Join-Path $AppRoot "config.php"
if (Test-Path $configPath) {
    $configContent = Get-Content $configPath -Raw
    if ($configContent -match "APP_VERSION.*?'([^']+)'") {
        $localVersion = $Matches[1]
    }
}
Write-Status "📌" "Version installee : v$localVersion"

# 3. Récupérer la version distante
Write-Section "Version distante"
$remoteConfig = Invoke-RestMethod -Uri "$RawBaseUrl/config.php" -ErrorAction SilentlyContinue
$remoteVersion = "inconnue"
if ($remoteConfig -match "APP_VERSION.*?'([^']+)'") {
    $remoteVersion = $Matches[1]
}
Write-Status "🌐" "Version disponible : v$remoteVersion"

if ($remoteVersion -eq $localVersion -and -not $DryRun) {
    Write-Host ""
    Write-Status "ℹ️" "Vous etes deja a jour (v$localVersion)." "Yellow"
    $answer = Read-Host "  Continuer quand meme ? (o/N)"
    if ($answer -notmatch "^[oO]$") {
        Write-Status "👋" "Mise a jour annulee." "Yellow"
        exit 0
    }
}

# 4. Lister les fichiers distants
Write-Section "Analyse des fichiers distants"
$remoteFiles = Get-RemoteFileList
if (-not $remoteFiles) {
    Write-Status "❌" "Impossible de recuperer la liste des fichiers." "Red"
    exit 1
}

$toUpdate = @()
$toAdd = @()

foreach ($item in $remoteFiles) {
    if ($item.type -ne "file") { continue }
    
    $fileName = $item.name
    
    # Ignorer les fichiers exclus
    if ($ExcludeFiles -contains $fileName) {
        Write-Status "⏭️" "Ignore (protege) : $fileName" "DarkGray"
        continue
    }
    
    $localPath = Join-Path $AppRoot $fileName
    
    if (Test-Path $localPath) {
        # Fichier existant — comparer la taille comme indicateur rapide
        $localSize = (Get-Item $localPath).Length
        $remoteSize = $item.size
        
        if ($localSize -ne $remoteSize) {
            $toUpdate += $item
            Write-Status "📝" "A mettre a jour : $fileName" "Yellow"
        }
    }
    else {
        # Nouveau fichier
        $toAdd += $item
        Write-Status "➕" "Nouveau fichier : $fileName" "Green"
    }
}

# Aussi vérifier les sous-dossiers PHPMailer
$subDirs = @("PHPMailer")
foreach ($subDir in $subDirs) {
    $subFiles = Get-RemoteFileList -Path $subDir
    if ($subFiles) {
        foreach ($item in $subFiles) {
            if ($item.type -ne "file") { continue }
            $relativePath = "$subDir/$($item.name)"
            $localPath = Join-Path $AppRoot "$subDir\$($item.name)"
            
            if (-not (Test-Path $localPath)) {
                $toAdd += $item
                Write-Status "➕" "Nouveau fichier : $relativePath" "Green"
            }
            else {
                $localSize = (Get-Item $localPath).Length
                if ($localSize -ne $item.size) {
                    $toUpdate += $item
                    Write-Status "📝" "A mettre a jour : $relativePath" "Yellow"
                }
            }
        }
    }
}

$totalChanges = $toUpdate.Count + $toAdd.Count

if ($totalChanges -eq 0) {
    Write-Host ""
    Write-Status "✅" "Aucune mise a jour necessaire. Tous les fichiers sont a jour." "Green"
    exit 0
}

Write-Host ""
Write-Status "📊" "Resume : $($toUpdate.Count) fichier(s) a mettre a jour, $($toAdd.Count) nouveau(x) fichier(s)" "Cyan"

# 5. DryRun — arrêt ici
if ($DryRun) {
    Write-Host ""
    Write-Status "🔍" "Mode simulation (DryRun) : aucune modification effectuee." "Yellow"
    exit 0
}

# 6. Sauvegarde
if (-not $SkipBackup) {
    Write-Section "Sauvegarde"
    $backupPath = Create-Backup -SourceDir $AppRoot
    if (-not $backupPath) {
        Write-Status "❌" "Sauvegarde echouee. Mise a jour annulee." "Red"
        exit 1
    }
}
else {
    Write-Status "⚠️" "Sauvegarde ignoree (--SkipBackup)" "Yellow"
}

# 7. Téléchargement et mise à jour
Write-Section "Mise a jour en cours"
$successCount = 0
$errorCount = 0

foreach ($item in ($toUpdate + $toAdd)) {
    $remotePath = $item.path
    $fileName = $item.name
    $localPath = Join-Path $AppRoot ($remotePath -replace '/', '\')
    
    # Ne pas écraser les fichiers protégés
    if ($ExcludeFiles -contains $fileName) {
        Write-Status "⏭️" "Protege : $fileName" "DarkGray"
        continue
    }
    
    # Ne pas toucher aux dossiers protégés
    $skip = $false
    foreach ($exDir in $ExcludeDirs) {
        if ($remotePath -like "$exDir/*" -or $remotePath -eq $exDir) {
            $skip = $true
            break
        }
    }
    if ($skip) { continue }
    
    if (Download-File -RemotePath $remotePath -LocalPath $localPath) {
        Write-Status "✅" "Telecharge : $remotePath" "Green"
        $successCount++
    }
    else {
        $errorCount++
    }
}

# 8. Résultat
Write-Section "Resultat"
if ($errorCount -eq 0) {
    Write-Status "✅" "Mise a jour terminee avec succes !" "Green"
    Write-Status "📊" "$successCount fichier(s) mis a jour" "Green"
}
else {
    Write-Status "⚠️" "Mise a jour terminee avec $errorCount erreur(s)" "Yellow"
    Write-Status "📊" "$successCount fichier(s) reussi(s), $errorCount echoue(s)" "Yellow"
}

# 9. Vérifier la nouvelle version
$newConfigPath = Join-Path $AppRoot "config.php"
if (Test-Path $newConfigPath) {
    $newConfigContent = Get-Content $newConfigPath -Raw
    $newVersion = "inconnue"
    if ($newConfigContent -match "APP_VERSION.*?'([^']+)'") {
        $newVersion = $Matches[1]
    }
    if ($newVersion -ne $localVersion) {
        Write-Status "🎉" "Version mise a jour : v$localVersion → v$newVersion" "Green"
    }
}

# 10. Instructions post-mise à jour
Write-Host ""
Write-Host "  ── Post-mise a jour ──" -ForegroundColor Cyan
Write-Status "💡" "Verifiez que l'application fonctionne correctement." "White"
Write-Status "💡" "En cas de probleme, restaurez la sauvegarde dans backups/" "White"
Write-Status "💡" "Le fichier config.php n'a PAS ete ecrase (protege)." "White"
Write-Status "💡" "Si de nouveaux parametres existent, ajoutez-les manuellement dans config.php" "White"

# 11. Proposition de nettoyage des anciens backups
$backupsDir = Join-Path $AppRoot "backups"
if (Test-Path $backupsDir) {
    $oldBackups = Get-ChildItem -Path $backupsDir -Directory | Sort-Object CreationTime -Descending | Select-Object -Skip 5
    if ($oldBackups.Count -gt 0) {
        Write-Host ""
        Write-Status "🗑️" "$($oldBackups.Count) ancienne(s) sauvegarde(s) trouvee(s) (5 conservees)." "Yellow"
        $clean = Read-Host "  Supprimer les anciennes sauvegardes ? (o/N)"
        if ($clean -match "^[oO]$") {
            foreach ($old in $oldBackups) {
                Remove-Item -Path $old.FullName -Recurse -Force
                Write-Status "🗑️" "Supprime : $($old.Name)" "DarkGray"
            }
        }
    }
}

Write-Host ""
Write-Status "👋" "Fin du script de mise a jour." "White"
