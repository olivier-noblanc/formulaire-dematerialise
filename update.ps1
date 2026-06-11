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
#
# Fonctionne dans 2 scenarios :
#   1. Le depot git existe deja -> git pull
#   2. Pas de depot git -> clone puis copie (sans ecraser config.php, db/, etc.)

param(
    [switch]$DryRun     = $false,
    [switch]$SkipBackup = $false
)

# ── Configuration ──────────────────────────────────────────────
$RepoUrl     = "https://github.com/olivier-noblanc/formulaire-dematerialise.git"
$RepoBranch  = "master"

# Fichiers a proteger (jamais ecrases)
$ProtectedFiles = @(
    "config.php"
)

# Dossiers a proteger (jamais ecrases)
$ProtectedDirs = @(
    "db",
    "sessions",
    "logs"
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

function Get-RemoteVersion {
    param([string]$GitDir)
    $configPath = Join-Path $GitDir "config.php"
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

function Safe-Copy {
    param(
        [string]$SourceDir,
        [string]$DestDir
    )
    # Copie les fichiers du clone vers l'application
    # en protegeant config.php, db/, sessions/, etc.

    $copiedCount = 0
    $skippedCount = 0

    # Lister les fichiers du clone (sauf .git, .history)
    $files = Get-ChildItem -Path $SourceDir -Recurse -File |
        Where-Object { $_.FullName -notmatch '\\\.git\\' -and $_.FullName -notmatch '\\\.history\\' }

    foreach ($file in $files) {
        $relativePath = $file.FullName.Substring($SourceDir.Length + 1)

        # Verifier si le fichier est protege
        $fileName = $file.Name
        $isProtected = $false

        # Fichiers proteges
        foreach ($pf in $ProtectedFiles) {
            if ($fileName -eq $pf) {
                $isProtected = $true
                break
            }
        }

        # Dossiers proteges
        if (-not $isProtected) {
            foreach ($pd in $ProtectedDirs) {
                if ($relativePath -like "$pd\*" -or $relativePath -like "$pd/*") {
                    $isProtected = $true
                    break
                }
            }
        }

        # Ne pas ecraser le script lui-meme en cours d'execution
        if ($fileName -eq "update.ps1" -and $relativePath -eq "update.ps1") {
            $isProtected = $true
        }

        if ($isProtected) {
            Write-Status ">>" "Protege : $relativePath" "DarkGray"
            $skippedCount++
            continue
        }

        # Copier le fichier
        $destPath = Join-Path $DestDir $relativePath
        $destDir2 = Split-Path -Parent $destPath
        if (-not (Test-Path $destDir2)) {
            New-Item -ItemType Directory -Path $destDir2 -Force | Out-Null
        }
        Copy-Item -Path $file.FullName -Destination $destPath -Force
        Write-Status "->" "Mis a jour : $relativePath" "Green"
        $copiedCount++
    }

    return @{ Copied = $copiedCount; Skipped = $skippedCount }
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

# 2. Version locale
Write-Section "Version locale"
$localVersion = Get-LocalVersion
Write-Status ">" "Version installee : v$localVersion"

# 3. Determiner le mode : git pull ou clone + copie
$hasGit = Test-Path (Join-Path $AppRoot ".git")

if ($hasGit) {
    # ── MODE 1 : git pull (depot deja cloné) ───────────────────

    Write-Section "Mode : depot git existant (git pull)"
    Push-Location $AppRoot

    # git fetch
    try {
        & git fetch origin 2>&1 | Out-Null
        if ($LASTEXITCODE -ne 0) { throw "git fetch echoue" }
        Write-Status "OK" "git fetch origin : OK" "Green"
    }
    catch {
        Write-Status "X" "Impossible de contacter le depot distant. Verifiez connexion/proxy." "Red"
        Pop-Location
        exit 1
    }

    # Comparer HEAD avec origin/master
    $localHash  = & git rev-parse HEAD 2>&1
    $remoteHash = & git rev-parse "origin/$RepoBranch" 2>&1

    if ($localHash -eq $remoteHash) {
        Write-Status "OK" "Vous etes deja a jour (v$localVersion)." "Green"
        Pop-Location
        exit 0
    }

    # Afficher les nouveaux commits
    $behindCount = & git rev-list --count "HEAD..origin/$RepoBranch" 2>&1
    Write-Status "!" "Votre version est en retard de $behindCount commit(s)" "Yellow"

    Write-Section "Nouveaux commits disponibles"
    & git log --oneline "HEAD..origin/$RepoBranch" 2>&1 | ForEach-Object {
        Write-Status "  " $_ "DarkGray"
    }

    # DryRun
    if ($DryRun) {
        Write-Host ""
        Write-Status "?" "Mode simulation (DryRun) : aucune modification effectuee." "Yellow"
        Pop-Location
        exit 0
    }

    # Sauvegarde
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

    # Proteger les fichiers locaux
    Write-Section "Protection des fichiers locaux"
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

    # git pull
    Write-Section "Mise a jour (git pull)"
    try {
        $pullOutput = & git pull origin $RepoBranch 2>&1
        if ($LASTEXITCODE -ne 0) { throw $pullOutput }
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

    # Restaurer les fichiers proteges
    Write-Section "Restauration des fichiers locaux"
    foreach ($file in $ProtectedFiles) {
        $tmp = Join-Path $tempDir $file
        if (Test-Path $tmp) {
            Copy-Item -Path $tmp -Destination (Join-Path $AppRoot $file) -Force
            Write-Status "OK" "Restaure : $file (version locale conservee)" "Green"
        }
    }
    Remove-Item -Path $tempDir -Recurse -Force -ErrorAction SilentlyContinue

    Pop-Location
}
else {
    # ── MODE 2 : clone + copie (pas de depot git) ──────────────

    Write-Section "Mode : pas de depot git (clone + copie)"
    Write-Status "!" "Pas de dossier .git detecte." "Yellow"
    Write-Status ">" "Le depot va etre clone puis les fichiers copies." "White"

    # Cloner dans un dossier temporaire
    $cloneDir = Join-Path $env:TEMP "formulaire-dematerialise-update"

    # Nettoyer un ancien clone s'il existe
    if (Test-Path $cloneDir) {
        Remove-Item -Path $cloneDir -Recurse -Force -ErrorAction SilentlyContinue
    }

    Write-Section "Clonage du depot"
    try {
        & git clone --branch $RepoBranch --single-branch $RepoUrl $cloneDir 2>&1 | ForEach-Object {
            Write-Status "  " $_ "DarkGray"
        }
        if ($LASTEXITCODE -ne 0) { throw "git clone echoue" }
        Write-Status "OK" "Clone reussi dans $cloneDir" "Green"
    }
    catch {
        Write-Status "X" "Impossible de cloner le depot. Verifiez connexion/proxy." "Red"
        exit 1
    }

    # Version distante
    $remoteVersion = Get-RemoteVersion -GitDir $cloneDir
    Write-Status ">" "Version disponible : v$remoteVersion"

    if ($remoteVersion -eq $localVersion -and -not $DryRun) {
        Write-Host ""
        Write-Status "OK" "Vous etes deja a jour (v$localVersion)." "Yellow"
        $answer = Read-Host "  Continuer quand meme ? (o/N)"
        if ($answer -notmatch "^[oO]$") {
            Remove-Item -Path $cloneDir -Recurse -Force -ErrorAction SilentlyContinue
            Write-Status ">" "Mise a jour annulee." "Yellow"
            exit 0
        }
    }

    # Afficher les fichiers qui vont etre mis a jour
    Write-Section "Fichiers a mettre a jour"
    $remoteFiles = Get-ChildItem -Path $cloneDir -Recurse -File |
        Where-Object { $_.FullName -notmatch '\\\.git\\' -and $_.FullName -notmatch '\\\.history\\' }

    $updateCount = 0
    $newCount = 0
    foreach ($file in $remoteFiles) {
        $relativePath = $file.FullName.Substring($cloneDir.Length + 1)
        $destPath = Join-Path $AppRoot $relativePath

        # Sauter les fichiers proteges
        $skip = $false
        foreach ($pf in $ProtectedFiles) {
            if ($file.Name -eq $pf) { $skip = $true; break }
        }
        foreach ($pd in $ProtectedDirs) {
            if ($relativePath -like "$pd\*" -or $relativePath -like "$pd/*") { $skip = $true; break }
        }
        if ($file.Name -eq "update.ps1" -and $relativePath -eq "update.ps1") { $skip = $true }

        if ($skip) { continue }

        if (Test-Path $destPath) {
            # Comparer le hash pour voir si le fichier a change
            $localHash2  = (Get-FileHash -Path $destPath -Algorithm SHA256).Hash
            $remoteHash2 = (Get-FileHash -Path $file.FullName -Algorithm SHA256).Hash
            if ($localHash2 -ne $remoteHash2) {
                Write-Status "~" "Modifie : $relativePath" "Yellow"
                $updateCount++
            }
        }
        else {
            Write-Status "+" "Nouveau : $relativePath" "Green"
            $newCount++
        }
    }

    $totalChanges = $updateCount + $newCount
    if ($totalChanges -eq 0) {
        Write-Host ""
        Write-Status "OK" "Aucune mise a jour necessaire." "Green"
        Remove-Item -Path $cloneDir -Recurse -Force -ErrorAction SilentlyContinue
        exit 0
    }

    Write-Host ""
    Write-Status ">" "Resume : $updateCount fichier(s) modifie(s), $newCount nouveau(x) fichier(s)" "Cyan"

    # DryRun
    if ($DryRun) {
        Write-Host ""
        Write-Status "?" "Mode simulation (DryRun) : aucune modification effectuee." "Yellow"
        Remove-Item -Path $cloneDir -Recurse -Force -ErrorAction SilentlyContinue
        exit 0
    }

    # Sauvegarde
    if (-not $SkipBackup) {
        Write-Section "Sauvegarde"
        $backupPath = Create-Backup -SourceDir $AppRoot
        if (-not $backupPath) {
            Write-Status "X" "Sauvegarde echouee. Mise a jour annulee." "Red"
            Remove-Item -Path $cloneDir -Recurse -Force -ErrorAction SilentlyContinue
            exit 1
        }
    }
    else {
        Write-Status "!" "Sauvegarde ignoree (-SkipBackup)" "Yellow"
    }

    # Copie des fichiers
    Write-Section "Mise a jour des fichiers"
    $result = Safe-Copy -SourceDir $cloneDir -DestDir $AppRoot

    # Nettoyer le clone
    Remove-Item -Path $cloneDir -Recurse -Force -ErrorAction SilentlyContinue

    Write-Section "Resultat copie"
    Write-Status "OK" "$($result.Copied) fichier(s) copie(s)" "Green"
    Write-Status ">>" "$($result.Skipped) fichier(s) protege(s) ignores" "DarkGray"
}

# ── Resultat final ─────────────────────────────────────────────

Write-Section "Resultat final"
$newVersion = Get-LocalVersion
if ($newVersion -ne $localVersion) {
    Write-Status "OK" "Version mise a jour : v$localVersion -> v$newVersion" "Green"
}
else {
    Write-Status "OK" "Mise a jour appliquee (v$newVersion)" "Green"
}

# Instructions post-mise a jour
Write-Host ""
Write-Section "Post-mise a jour"
Write-Status "!" "Verifiez que l'application fonctionne correctement." "White"
Write-Status "!" "En cas de probleme, restaurez la sauvegarde dans backups/" "White"
Write-Status "!" "config.php n'a PAS ete ecrase (version locale conservee)." "White"
Write-Status "!" "Si de nouveaux parametres existent dans config.php, ajoutez-les manuellement." "White"

# Nettoyage des anciens backups
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

Write-Host ""
Write-Status ">" "Fin du script de mise a jour." "White"
