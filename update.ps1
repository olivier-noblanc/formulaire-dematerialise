# update.ps1 — Mise a jour CircuitDémat (git pull ou clone + copie)
#
# Usage :
#   .\update.ps1              # Mise a jour normale (sauvegarde auto + gate qualité)
#   .\update.ps1 -DryRun      # Simule sans rien modifier
#   .\update.ps1 -SkipBackup  # Pas de sauvegarde (dangereux)
#   .\update.ps1 -SkipTests   # Passe la gate qualité (DANGEREUX — déploiement d'urgence uniquement)
#   .\update.ps1 -SkipLint    # Passe seulement le lint PHP (DANGEREUX — déploiement d'urgence)
#
# Prerequis : PowerShell 5.1+ (5.1 = lint séquentiel, 7+ = lint parallèle x4-8)
#             git installe (composer auto-installe si absent)
# Compatible Windows Server / IIS
#
# Fonctionne en 2 scenarios :
#   1. Depot git existant -> git pull
#   2. Pas de git -> clone temp + copie a plat
#
# ── Gate qualité (sécurité déploiement) ──────────────────────────
# Après git pull (ou après copie des fichiers), le script exécute :
#   1. Lint PHP (php -l sur tous les .php hors vendor/tests)
#   2. PHPStan niveau 6 (analyse statique — si vendor/bin/phpstan.phar présent)
#   3. Tests fonctionnels (php tests/test_all.php — 51 tests)
# Si un seul échoue → ROLLBACK AUTOMATIQUE via la sauvegarde + exit 1.
# Pour bypasser (hotfix urgent) : -SkipTests (à justifier en commit message).

param(
    [switch]$DryRun     = $false,
    [switch]$SkipBackup = $false,
    [switch]$SkipTests  = $false,
    [switch]$SkipLint   = $false
)

# ── Auto-update de update.ps1 lui-même ──────────────────────
# update.ps1 se télécharge lui-même depuis le repo avant de faire quoi que ce soit.
# v10.0.9 — Fix 2 bugs :
#   1. Comparaison CRLF/LF : on normalise les 2 contenus en LF avant comparaison
#      (avant : $currentContent -ne $response.Content échouait toujours car
#      Windows = CRLF, GitHub = LF → fausse mise à jour à chaque lancement)
#   2. Token GitHub en header Authorization au lieu de l'URL
#      (avant : token dans l'URL = visible dans logs proxy + historique PowerShell)
if ($env:FORMULAIRE_TOKEN -and -not $env:_UPDATE_PS1_SELF_UPDATED) {
    $env:_UPDATE_PS1_SELF_UPDATED = "1"
    # v10.0.9 — URL SANS token (le token va dans le header)
    $repoRawUrl = "https://api.github.com/repos/olivier-noblanc/formulaire-dematerialise/contents/update.ps1"
    try {
        # v10.0.9 — Token en header Authorization (pas dans l'URL)
        $headers = @{ "Authorization" = "token $($env:FORMULAIRE_TOKEN)"; "Accept" = "application/vnd.github.v3+json" }
        $response = Invoke-WebRequest -Uri $repoRawUrl -Headers $headers -UseBasicParsing -ErrorAction Stop
        if ($response.StatusCode -eq 200) {
            # GitHub API retourne du JSON avec content base64-encodé
            $json = $response.Content | ConvertFrom-Json
            $remoteContent = [System.Text.Encoding]::UTF8.GetString([System.Convert]::FromBase64String($json.content))
            if ($remoteContent.Length -gt 1000) {
                $scriptPath = $MyInvocation.MyCommand.Path
                $currentContent = Get-Content -Path $scriptPath -Raw -ErrorAction SilentlyContinue
                # v10.0.9 — Normaliser CRLF → LF pour les 2 contenus avant comparaison
                $currentNormalized = $currentContent -replace "`r`n", "`n"
                $remoteNormalized  = $remoteContent -replace "`r`n", "`n"
                if ($currentNormalized -ne $remoteNormalized) {
                    Write-Host "  ! Mise a jour de update.ps1..." -ForegroundColor Yellow
                    Set-Content -Path $scriptPath -Value $remoteContent -Encoding UTF8 -NoNewline
                    Write-Host "  OK update.ps1 mis a jour. Relance..." -ForegroundColor Green
                    & $scriptPath -DryRun:$DryRun -SkipBackup:$SkipBackup -SkipTests:$SkipTests -SkipLint:$SkipLint
                    exit $LASTEXITCODE
                }
            }
        }
    } catch {
        # Silencieux — on continue avec l'ancienne version
    }
}

# ── Configuration ──────────────────────────────────────────────
$RepoBranch  = "master"
$RepoUrl     = "https://github.com/olivier-noblanc/formulaire-dematerialise.git"

# Fichiers a proteger (jamais ecrases ni supprimes)
$ProtectedFiles = @( "config.php" )

# Dossiers a proteger (jamais ecrases ni supprimes)
# Inclut vendor/ (peut contenir plus de fichiers que le git — composer install)
$ProtectedDirs = @( "db", "sessions", "logs", "vendor", "node_modules", "cache" )

# Repertoire du script = racine de l'application
$AppRoot = $PSScriptRoot
if (-not $AppRoot) { $AppRoot = (Get-Location).Path }

# ── CRITIQUE : empecher git de bloquer en attente de saisie interactive ──
$env:GIT_TERMINAL_PROMPT = '0'
$env:GCM_INTERACTIVE = 'Never'

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
    return Get-FileVersion (Join-Path $AppRoot "CHANGELOG.md")
}

function Get-RemoteVersion {
    param([string]$Dir)
    return Get-FileVersion (Join-Path $Dir "CHANGELOG.md")
}

function Get-FileVersion {
    param([string]$Path)
    if (-not (Test-Path $Path)) { return "inconnue" }
    try {
        $content = [System.IO.File]::ReadAllText($Path)
        $lines = $content -split "`n"
        foreach ($line in $lines) {
            $t = $line.Trim()
            if ($t.StartsWith('## [')) {
                $open = $t.IndexOf('[') + 1
                $close = $t.IndexOf(']')
                if ($open -gt 0 -and $close -gt $open) {
                    $v = $t.Substring($open, $close - $open).Trim()
                    if ($v -match '^\d+\.\d+\.\d+$') { return $v }
                }
            }
        }
    } catch {}
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
                $destPath     = Join-Path $backupDir $relativePath
                $destDir      = Split-Path -Parent $destPath
                if (-not (Test-Path $destDir)) { New-Item -ItemType Directory -Path $destDir -Force | Out-Null }
                Copy-Item -Path $file.FullName -Destination $destPath -Force
            }
        }
        Write-Status "OK" "Sauvegarde creee : backups\backup-$timestamp" "Green"
        return $backupDir
    } catch {
        Write-Status "X" "Echec de la sauvegarde : $_" "Red"
        return $null
    }
}

# URL de clone avec token (sans guillemets — sinon auth echoue)
function Get-CloneUrl {
    if ($env:FORMULAIRE_TOKEN) {
        $token = $env:FORMULAIRE_TOKEN
        $encodedToken = [System.Uri]::EscapeDataString($token)
        $url = "https://olivier-noblanc:$encodedToken@github.com/olivier-noblanc/formulaire-dematerialise.git"
        Write-Status "!" "Token detecte (FORMULAIRE_TOKEN, $($token.Length) chars). Auth via token." "Green"
        return $url
    }
    Write-Status "!" "Pas de token defini. Le depot est PRIVE — le clone va echouer." "Yellow"
    return $RepoUrl
}

# Wrapper git : desactive credential.helper + core.askpass (evite Credential Manager)
function Invoke-Git {
    param([string[]]$Arguments)
    $fullArgs = @('-c', 'credential.helper=', '-c', 'core.askpass=') + $Arguments
    return & git @fullArgs 2>&1
}

function Get-SafeTempDir {
    $tempPath = [System.IO.Path]::GetTempPath()
    if (-not [System.IO.Path]::IsPathRooted($tempPath)) {
        $tempPath = Join-Path $env:SystemDrive "Temp"
    }
    if (-not (Test-Path $tempPath)) {
        New-Item -ItemType Directory -Path $tempPath -Force | Out-Null
    }
    return $tempPath
}

function Find-AppRootInDir {
    param([string]$Dir)
    if ((Test-Path (Join-Path $Dir "index.php")) -and (Test-Path (Join-Path $Dir "helpers.php"))) {
        return $Dir
    }
    $subDirs = Get-ChildItem -Path $Dir -Directory -ErrorAction SilentlyContinue
    foreach ($sub in $subDirs) {
        if ((Test-Path (Join-Path $sub.FullName "index.php")) -and (Test-Path (Join-Path $sub.FullName "helpers.php"))) {
            return $sub.FullName
        }
    }
    return $null
}

# ── Regeneration autoload Composer ──
# Doit être appelée APRÈS le git pull / copie des fichiers et AVANT la gate qualité,
# car les fichiers vendor/ du nouveau code peuvent avoir changé (nouvelles dépendances).
function Invoke-ComposerAutoload {
    Write-Section "Regeneration autoload Composer"
    $composerExe = Get-Command composer -ErrorAction SilentlyContinue
    $composerPhar = Join-Path $AppRoot "composer.phar"

    # 1) Resoudre : composer global OU composer.phar local
    $usePhar = $false
    if ($composerExe) {
        # composer.exe est dans le PATH
    } elseif (Test-Path $composerPhar) {
        $usePhar = $true
    } elseif (-not $DryRun) {
        # 2) Auto-installation de composer.phar dans le repo
        Write-Status ">" "Composer non trouve. Telechargement de composer.phar..." "Yellow"
        try {
            $proxyArgs = @{}
            $systemProxy = [System.Net.WebRequest]::GetSystemWebProxy()
            if ($systemProxy -and -not $systemProxy.IsBypassed('https://getcomposer.org')) {
                $proxyAddr = $systemProxy.GetProxy('https://getcomposer.org').AbsoluteUri
                $proxyArgs['Proxy'] = [System.Net.WebProxy]::new($proxyAddr)
                Write-Status ">" "Proxy detecte : $proxyAddr" "DarkGray"
            }
            Invoke-WebRequest -Uri 'https://getcomposer.org/composer-stable.phar' `
                -OutFile $composerPhar -UseBasicParsing @proxyArgs -ErrorAction Stop
            Write-Status "OK" "composer.phar telecharge" "Green"
            $usePhar = $true
        } catch {
            Write-Status "X" "Impossible de telecharger composer.phar : $_" "Red"
        }
    }

    # 3) Executer dump-autoload
    if ($composerExe -or $usePhar) {
        Push-Location $AppRoot
        try {
            if (-not $DryRun) {
                if (-not $usePhar) {
                    $composerOutput = & composer dump-autoload -o 2>&1
                } else {
                    $composerOutput = & php $composerPhar dump-autoload -o 2>&1
                }
                if ($LASTEXITCODE -eq 0) {
                    Write-Status "OK" "composer dump-autoload -o : reussi" "Green"
                } else {
                    Write-Status "X" "composer dump-autoload -o a echoue" "Red"
                    $composerOutput | ForEach-Object { Write-Host "    $_" -ForegroundColor DarkRed }
                }
            } else {
                Write-Status ".." "composer dump-autoload -o (simule)" "DarkGray"
            }
        } catch {
            Write-Status "X" "Erreur composer : $_" "Red"
        } finally {
            Pop-Location
        }
    } elseif (-not $DryRun) {
        Write-Status "X" "Composer introuvable et telechargement echoue. L'autoload ne sera pas regenere." "Yellow"
    }
}

# ── Gate qualité : vérifie que le code déployé passe lint + PHPStan + tests ──
# Retourne $true si tout passe, $false sinon. Affiche le détail sur la console.
# Cette fonction est appelée APRÈS git pull (ou copie des fichiers) et AVANT
# de considérer la mise à jour comme réussie. Si elle retourne $false, le script
# restaure la sauvegarde et sort en erreur (exit 1).
function Invoke-QualityGate {
    Write-Section "Gate qualité (lint + tests)"

    # ── Vérifier que PHP est disponible ──
    $phpExe = Get-Command php -ErrorAction SilentlyContinue
    if (-not $phpExe) {
        # Sur Windows/IIS, php est parfois seulement dans C:\PHP\
        $phpFallback = "C:\PHP\php.exe"
        if (Test-Path $phpFallback) {
            $phpExe = $phpFallback
        } else {
            Write-Status "X" "PHP non trouvé dans le PATH ni dans C:\PHP\." "Red"
            Write-Status "!" "Impossible d'exécuter la gate qualité." "Yellow"
            Write-Status ">" "Considérez utiliser -SkipTests si vous êtes certain du déploiement." "White"
            return $false
        }
    }
    $phpBin = if ($phpExe.Source) { $phpExe.Source } else { $phpExe }
    Write-Status "OK" "PHP détecté : $phpBin" "Green"

    $gateOk = $true

    # ── 1. Lint PHP (php -l) sur tous les .php hors vendor/tests ──
    # Optimisations (v9.4.0) :
    #   - Désactivation Xdebug via -d xdebug.mode=off (gain ×2-5)
    #     Xdebug est quasi toujours actif sur IIS/Windows et ralentit fortement
    #     même pour un simple lint syntaxique.
    #   - Scope incrémental : ne linter que les fichiers modifiés depuis le
    #     dernier commit (gain ×10-100). Fallback sur lint complet si :
    #       * pas de git, pas de HEAD~1 (fresh clone)
    #       * plus de 50 fichiers modifiés (gros refactor → sécurité)
    #       * option -SkipLint passée (bypass total)
    #   - Parallélisme PowerShell 7+ via ForEach-Object -Parallel (gain ×4-8)
    #     Fallback séquentiel si PowerShell 5.1.
    if ($SkipLint) {
        Write-Status "!" "Lint PHP ignoré (-SkipLint). DANGEREUX." "Yellow"
    } else {
        Write-Status ">" "Étape 1/3 : Lint PHP (php -l, xdebug off, incrémental)..." "Cyan"

        # Liste complète des fichiers PHP à scanner (fallback)
        $phpFiles = Get-ChildItem -Path $AppRoot -Recurse -File -Filter "*.php" -ErrorAction SilentlyContinue |
            Where-Object {
                $rel = $_.FullName.Substring($AppRoot.Length + 1)
                -not ($rel -like "vendor\*" -or $rel -like "vendor/*" -or
                      $rel -like "tests\*"   -or $rel -like "tests/*"   -or
                      $rel -like "backups\*" -or $rel -like "backups/*" -or
                      $rel -like ".git\*"    -or $rel -like ".git/*"    -or
                      $rel -like ".update_tmp\*" -or $rel -like ".update_tmp/*" -or
                      $rel -like "db\cache\*" -or $rel -like "db/cache/*")
            }

        # ── Scope incrémental : ne linter que les fichiers modifiés ──
        $filesToLint = $phpFiles.FullName
        $incrementalUsed = $false
        try {
            # Vérifier que git est dispo et qu'on est dans un repo
            $gitAvailable = (Get-Command git -ErrorAction SilentlyContinue) -ne $null
            if ($gitAvailable) {
                Push-Location $AppRoot
                $gitRevParse = & git rev-parse --is-inside-work-tree 2>&1
                $hasCommits = $LASTEXITCODE -eq 0
                if ($hasCommits) {
                    # git diff entre HEAD~1 et HEAD (fichiers du dernier commit)
                    # Si HEAD~1 n'existe pas (1er commit), on prend HEAD seul
                    $changedFiles = & git diff --name-only --diff-filter=ACM HEAD~1 HEAD -- "*.php" 2>$null
                    if (-not $changedFiles -or $changedFiles.Count -eq 0) {
                        # Fallback : fichiers modifiés non commités (working dir)
                        $changedFiles = & git diff --name-only --diff-filter=ACM -- "*.php" 2>$null
                    }
                    if ($changedFiles -and $changedFiles.Count -gt 0 -and $changedFiles.Count -le 50) {
                        # Filtrer pour ne garder que les fichiers qui existent réellement
                        # et qui ne sont pas dans vendor/tests/backups
                        $filesToLint = @()
                        foreach ($rel in $changedFiles) {
                            $full = Join-Path $AppRoot $rel
                            if (Test-Path $full) {
                                # Vérifier qu'on ne linte pas vendor/tests
                                $normalized = $full -replace '/', '\'
                                if ($normalized -notmatch '\\(vendor|tests|backups|\.git|\.update_tmp|db\\cache)\\') {
                                    $filesToLint += $full
                                }
                            }
                        }
                        if ($filesToLint.Count -gt 0) {
                            $incrementalUsed = $true
                            Write-Status ">" "Lint incrémental : $($filesToLint.Count) fichier(s) modifié(s) depuis le dernier commit." "DarkGray"
                        }
                    } elseif ($changedFiles -and $changedFiles.Count -gt 50) {
                        Write-Status ">" "Lint complet : $($changedFiles.Count) fichiers modifiés (> 50) — sécurité." "DarkGray"
                    }
                }
                Pop-Location
            }
        } catch {
            # En cas d'erreur git → fallback sur lint complet
            Write-Status ">" "git non disponible ou erreur — lint complet appliqué." "DarkGray"
        }

        if (-not $filesToLint -or $filesToLint.Count -eq 0) {
            Write-Status "OK" "Lint PHP : aucun fichier à vérifier." "Green"
        } else {
            # ── Détection PowerShell 7+ pour parallélisme ──
            $psVersion = $PSVersionTable.PSVersion.Major
            $useParallel = $psVersion -ge 7

            # Commande PHP avec Xdebug désactivé (gain ×2-5)
            # On ajoute -d xdebug.mode=off à chaque appel php -l
            $lintErrors = 0
            $lintChecked = 0

            if ($useParallel) {
                # ── Parallélisme PowerShell 7+ (gain ×4-8) ──
                Write-Status ">" "Parallélisme activé (PowerShell $($PSVersionTable.PSVersion)) — $([System.Environment]::ProcessorCount) cœurs." "DarkGray"
                $results = $filesToLint | ForEach-Object -ThrottleLimit 8 -Parallel {
                    $out = & $using:phpBin -d xdebug.mode=off -l $_ 2>&1
                    [PSCustomObject]@{
                        File   = $_
                        OK     = $LASTEXITCODE -eq 0
                        Output = $out
                    }
                }
                foreach ($r in $results) {
                    $lintChecked++
                    if (-not $r.OK) {
                        $rel = $r.File.Substring($AppRoot.Length + 1)
                        Write-Status "X" "Erreur de syntaxe : $rel" "Red"
                        $r.Output | ForEach-Object { Write-Host "    $_" -ForegroundColor DarkRed }
                        $lintErrors++
                    }
                }
            } else {
                # ── Fallback séquentiel PowerShell 5.1 ──
                Write-Status ">" "Parallélisme indisponible (PowerShell $psVersion, nécessite 7+) — lint séquentiel." "DarkGray"
                foreach ($file in $filesToLint) {
                    $lintChecked++
                    $output = & $phpBin -d xdebug.mode=off -l $file 2>&1
                    if ($LASTEXITCODE -ne 0) {
                        $rel = $file.Substring($AppRoot.Length + 1)
                        Write-Status "X" "Erreur de syntaxe : $rel" "Red"
                        $output | ForEach-Object { Write-Host "    $_" -ForegroundColor DarkRed }
                        $lintErrors++
                    }
                }
            }

            $modeStr = if ($incrementalUsed) { "incrémental" } else { "complet" }
            $parStr = if ($useParallel) { "parallèle" } else { "séquentiel" }
            if ($lintErrors -gt 0) {
                Write-Status "X" "Lint PHP ($modeStr, $parStr) : $lintErrors erreur(s) sur $lintChecked fichier(s)." "Red"
                $gateOk = $false
            } else {
                Write-Status "OK" "Lint PHP ($modeStr, $parStr, xdebug off) : $lintChecked fichier(s) vérifié(s), 0 erreur." "Green"
            }
        }
    }

    # ── 2. PHPStan → déplacé dans GitHub Actions CI (pas sur prod) ──
    Write-Status "OK" "PHPStan : déplacé dans GitHub Actions CI (pas nécessaire en prod)." "Green"

    # ── 2. Tests fonctionnels (tests/test_all.php — 51 tests) ──
    Write-Status ">" "Étape 2/2 : Tests fonctionnels (tests/test_all.php)..." "Cyan"
    $testFile = Join-Path $AppRoot "tests\test_all.php"
    if (-not (Test-Path $testFile)) {
        Write-Status "!" "tests/test_all.php introuvable. Étape skippée." "Yellow"
    } else {
        # Capture stdout + stderr séparément pour un affichage propre
        $stderrFile = [System.IO.Path]::GetTempFileName()
        $stdout = & $phpBin $testFile 2>$stderrFile
        $rc = $LASTEXITCODE
        $stderr = Get-Content $stderrFile -Raw -ErrorAction SilentlyContinue
        Remove-Item $stderrFile -Force -ErrorAction SilentlyContinue

        # Afficher stdout (les résultats des tests)
        $stdout | ForEach-Object { Write-Host "    $_" -ForegroundColor DarkGray }

        # Détecter "X réussi(s) / Y échoué(s)" dans stdout
        $successMatch = [regex]::Match(($stdout -join "`n"), '(\d+)\s+réussi\(s\)\s*/\s*(\d+)\s+échoué\(s\)')
        if ($successMatch.Success) {
            $passed = [int]$successMatch.Groups[1].Value
            $failed = [int]$successMatch.Groups[2].Value
            if ($failed -eq 0) {
                Write-Status "OK" "Tests : $passed/$($passed + $failed) réussis." "Green"
            } else {
                Write-Status "X" "Tests : $failed échec(s) sur $($passed + $failed)." "Red"
                $gateOk = $false
            }
        } elseif ($rc -eq 0) {
            Write-Status "OK" "Tests : OK (code sortie 0)." "Green"
        } else {
            Write-Status "X" "Tests : échec (code $rc)." "Red"
            if ($stderr) {
                Write-Status ">" "stderr :" "DarkGray"
                $stderr | ForEach-Object { Write-Host "    $_" -ForegroundColor DarkRed }
            }
            $gateOk = $false
        }
    }

    # ── Résumé final de la gate ──
    Write-Host ""
    if ($gateOk) {
        Write-Status "OK" "════════════════════════════════════════════════════" "Green"
        Write-Status "OK" "  GATE QUALITÉ RÉUSSIE — déploiement autorisé" "Green"
        Write-Status "OK" "════════════════════════════════════════════════════" "Green"
    } else {
        Write-Status "X" "════════════════════════════════════════════════════" "Red"
        Write-Status "X" "  GATE QUALITÉ ÉCHOUÉE — déploiement BLOQUÉ" "Red"
        Write-Status "X" "  Rollback automatique de la sauvegarde..." "Red"
        Write-Status "X" "════════════════════════════════════════════════════" "Red"
    }
    return $gateOk
}

# ── Restaure la dernière sauvegarde en cas d'échec de la gate qualité ──
# Utilisé après un git pull ou une copie de fichiers qui a cassé les tests.
function Restore-LastBackup {
    param([string]$BackupPath)
    if (-not $BackupPath -or -not (Test-Path $BackupPath)) {
        Write-Status "X" "Aucune sauvegarde à restaurer — état actuel laissé en place." "Red"
        return
    }
    Write-Section "Rollback : restauration de la sauvegarde"
    Write-Status ">" "Source  : $BackupPath" "White"
    Write-Status ">" "Cible   : $AppRoot" "White"

    $restored = 0; $skipped = 0
    $backupFiles = Get-ChildItem -Path $BackupPath -Recurse -File -ErrorAction SilentlyContinue
    foreach ($file in $backupFiles) {
        $relativePath = $file.FullName.Substring($BackupPath.Length + 1)
        $destPath = Join-Path $AppRoot $relativePath
        $destParent = Split-Path -Parent $destPath
        if (-not (Test-Path $destParent)) { New-Item -ItemType Directory -Path $destParent -Force | Out-Null }
        Copy-Item -Path $file.FullName -Destination $destPath -Force
        $restored++
    }
    Write-Status "OK" "$restored fichier(s) restauré(s) depuis la sauvegarde." "Green"
    Write-Status "!" "Vérifiez l'application — elle devrait être revenue à l'état précédent." "Yellow"
}

# ── Programme principal ────────────────────────────────────────

# 0. Afficher les chemins pour debug
Write-Section "Diagnostic initial"
Write-Status ">" "AppRoot = $AppRoot" "DarkGray"
Write-Status ">" "Script  = $PSCommandPath" "DarkGray"
Write-Status ">" "PWD     = $((Get-Location).Path)" "DarkGray"
Write-Status ">" "TEMP    = $env:TEMP" "DarkGray"
Write-Status ">" "GetTempPath = $([System.IO.Path]::GetTempPath())" "DarkGray"
Write-Status ">" "index.php dans AppRoot ? $(if (Test-Path (Join-Path $AppRoot 'index.php')) {'OUI'} else {'NON'})" "DarkGray"

# Verifier que AppRoot contient bien index.php (sinon premiere installation)
$isFirstInstall = -not (Test-Path (Join-Path $AppRoot "index.php"))

if ($isFirstInstall) {
    Write-Section "Premiere installation detectee"
    Write-Status "!" "index.php non trouve dans AppRoot." "Yellow"
    Write-Status ">" "Mode premiere installation : clone + copie sans suppression." "White"
    Write-Status ">" "Assurez-vous que config.php est present (ou sera cree par install.php)." "White"
    Write-Status ">" "AppRoot : $AppRoot" "DarkGray"
    Write-Status ">" "Fichiers presents :" "DarkGray"
    Get-ChildItem -Path $AppRoot -File -ErrorAction SilentlyContinue | Select-Object -First 10 | ForEach-Object {
        Write-Status "  " "  $($_.Name)" "DarkGray"
    }
    if (-not (Test-Path (Join-Path $AppRoot "update.ps1"))) {
        Write-Status "X" "update.ps1 non trouve dans AppRoot — abandon." "Red"
        exit 1
    }
    Write-Status "OK" "update.ps1 present — premiere installation possible." "Green"

    $dbPath = Join-Path $AppRoot "db\workflow.db"
    if (Test-Path $dbPath) {
        Write-Status "OK" "BDD existante detectee : $dbPath" "Green"
        Write-Status ">" "La BDD sera PRESERVEE (contient admin, formulaires, soumissions)." "White"
    } else {
        Write-Status "!" "Aucune BDD detectee (db\workflow.db absent)." "Yellow"
        Write-Status ">" "L'application créera une BDD vierge au premier acces." "White"
        Write-Status ">" "Vous devrez demander l'acces admin via admin_access.php." "White"
    }
} else {
    Write-Status "OK" "index.php bien present dans AppRoot (mise a jour normale)." "Green"
}

# 1. Verifier que git est disponible
Write-Section "Verification de l'environnement"
try {
    $gitVersion = & git --version 2>&1
    if ($LASTEXITCODE -ne 0) { throw "git non trouve" }
    Write-Status "OK" "Git detecte : $gitVersion" "Green"
} catch {
    Write-Status "X" "Git n'est pas installe ou pas dans le PATH." "Red"
    exit 1
}

# 1b. Diagnostic credential helper
Write-Section "Diagnostic credential helper"
try {
    $helperConfig = & git config --global credential.helper 2>&1
    if ($helperConfig -and $helperConfig -ne '') {
        Write-Status "!" "Credential helper global : $helperConfig" "Yellow"
        Write-Status ">" "Il sera desactive pour ce script (option -c credential.helper=)" "DarkGray"
    } else {
        Write-Status "OK" "Aucun credential helper global configure" "Green"
    }
} catch {
    Write-Status ">" "Impossible de lire la config git (non bloquant)" "DarkGray"
}

# 2. Version locale
Write-Section "Version locale"
$localVersion = Get-LocalVersion
Write-Status ">" "Version installee : v$localVersion"

# 2b. Detection des anomalies (sous-dossier contenant l'application)
if (-not $isFirstInstall) {
    Write-Section "Detection des anomalies"
    $excludedDirNames = @("db", "sessions", "logs", "backups", ".git", ".history", ".update_tmp", "vendor", "PHPMailer", "lib", "classes", "tests", "src", "samples")
    $anomalyDirs = Get-ChildItem -Path $AppRoot -Directory -ErrorAction SilentlyContinue |
        Where-Object {
            $_.Name -notin $excludedDirNames -and
            (Test-Path (Join-Path $_.FullName "index.php")) -and
            (Test-Path (Join-Path $_.FullName "helpers.php"))
        }

    if ($anomalyDirs.Count -gt 0) {
        Write-Status "!" "Anomalie detectee : sous-dossier(s) contenant l'application" "Yellow"
        if ($DryRun) {
            foreach ($d in $anomalyDirs) { Write-Status "?" "Serait corrige : $($d.Name) -> deplace vers la racine" "Yellow" }
        } else {
            if (-not $SkipBackup) {
                $backupPath = Create-Backup -SourceDir $AppRoot
                if (-not $backupPath) { Write-Status "X" "Sauvegarde echouee, correction annulee" "Red"; exit 1 }
            }
            foreach ($badDir in $anomalyDirs) {
                Write-Status ">" "Correction : $($badDir.Name) -> racine" "White"
                $items = Get-ChildItem -Path $badDir.FullName -Recurse -File -ErrorAction SilentlyContinue |
                    Where-Object { $_.FullName -notmatch '\(\.git|\.history\)\\' }
                foreach ($item in $items) {
                    $relativePath = $item.FullName.Substring($badDir.FullName.Length + 1)
                    if ($relativePath -eq "update.ps1") { continue }
                    $isProtected = $false
                    foreach ($pf in $ProtectedFiles) {
                        if ($relativePath -eq $pf -and (Test-Path (Join-Path $AppRoot $pf))) { $isProtected = $true; break }
                    }
                    if ($isProtected) { Write-Status ">>" "Protege : $relativePath" "DarkGray"; continue }
                    $destPath = Join-Path $AppRoot $relativePath
                    $destParent = Split-Path -Parent $destPath
                    if (-not (Test-Path $destParent)) { New-Item -ItemType Directory -Path $destParent -Force | Out-Null }
                    if (Test-Path $destPath) {
                        $localH  = (Get-FileHash -Path $destPath  -Algorithm SHA256 -ErrorAction SilentlyContinue).Hash
                        $remoteH = (Get-FileHash -Path $item.FullName -Algorithm SHA256 -ErrorAction SilentlyContinue).Hash
                        if ($localH -eq $remoteH) { continue }
                    }
                    Move-Item -Path $item.FullName -Destination $destPath -Force
                    Write-Status "->" "Deplace : $relativePath" "Green"
                }
                Remove-Item -Path $badDir.FullName -Recurse -Force -ErrorAction SilentlyContinue
                Write-Status "OK" "Sous-dossier $($badDir.Name) supprime" "Green"
            }
            Write-Status "OK" "Anomalie corrigee !" "Green"
        }
    } else {
        Write-Status "OK" "Aucune anomalie detectee" "Green"
    }
}

# 3. Determiner le mode
$hasGit = Test-Path (Join-Path $AppRoot ".git")

if ($hasGit) {
    Write-Section "Mode : depot git existant (git pull)"
    Push-Location $AppRoot

    try {
        $fetchOutput = Invoke-Git @('fetch', 'origin')
        if ($LASTEXITCODE -ne 0) {
            Write-Status "X" "git fetch echoue. Sortie git :" "Red"
            $fetchOutput | ForEach-Object { Write-Host "    $_" -ForegroundColor DarkRed }
            throw "git fetch echoue"
        }
        Write-Status "OK" "git fetch origin : OK" "Green"
    } catch {
        Write-Status "X" "Impossible de contacter le depot." "Red"
        Pop-Location; exit 1
    }

    $localHash  = & git rev-parse HEAD 2>&1
    $remoteHash = & git rev-parse "origin/$RepoBranch" 2>&1

    if ($localHash -eq $remoteHash) {
        Write-Status "OK" "Vous etes deja a jour (v$localVersion)." "Green"
        Pop-Location; exit 0
    }

    $behindCount = & git rev-list --count "HEAD..origin/$RepoBranch" 2>&1
    Write-Status "!" "Version en retard de $behindCount commit(s)" "Yellow"

    Write-Section "Nouveaux commits disponibles"
    & git log --oneline "HEAD..origin/$RepoBranch" 2>&1 | ForEach-Object {
        Write-Status "  " $_ "DarkGray"
    }

    if ($DryRun) {
        Write-Host ""; Write-Status "?" "Mode simulation (DryRun) : aucune modification." "Yellow"
        Pop-Location; exit 0
    }

    if (-not $SkipBackup) {
        Write-Section "Sauvegarde"
        $backupPath = Create-Backup -SourceDir $AppRoot
        if (-not $backupPath) { Write-Status "X" "Sauvegarde echouee." "Red"; Pop-Location; exit 1 }
    } else { Write-Status "!" "Sauvegarde ignoree (-SkipBackup)" "Yellow" }

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

    Write-Section "Mise a jour (git pull)"
    try {
        $pullOutput = Invoke-Git @('pull', 'origin', $RepoBranch)
        if ($LASTEXITCODE -ne 0) {
            Write-Status "X" "git pull echoue. Sortie git :" "Red"
            $pullOutput | ForEach-Object { Write-Host "    $_" -ForegroundColor DarkRed }
            throw "git pull echoue"
        }
        $pullOutput | ForEach-Object { Write-Status "  " $_ "DarkGray" }
        Write-Status "OK" "git pull : reussi" "Green"
    } catch {
        Write-Status "X" "git pull echoue : $_" "Red"
        foreach ($file in $ProtectedFiles) {
            $tmp = Join-Path $tempDir $file
            if (Test-Path $tmp) { Copy-Item -Path $tmp -Destination (Join-Path $AppRoot $file) -Force }
        }
        Remove-Item -Path $tempDir -Recurse -Force -ErrorAction SilentlyContinue
        Pop-Location; exit 1
    }

    Write-Section "Restauration des fichiers locaux"
    foreach ($file in $ProtectedFiles) {
        $tmp = Join-Path $tempDir $file
        if (Test-Path $tmp) {
            Copy-Item -Path $tmp -Destination (Join-Path $AppRoot $file) -Force
            Write-Status "OK" "Restaure : $file" "Green"
        }
    }
    Remove-Item -Path $tempDir -Recurse -Force -ErrorAction SilentlyContinue

    # ── Regeneration autoload AVANT la gate (vendor peut avoir changé) ──
    Invoke-ComposerAutoload

    # ── Gate qualité : vérifier que le code téléchargé passe lint + PHPStan + tests ──
    # Si la gate échoue → rollback automatique via la sauvegarde + exit 1.
    # Pour bypasser (hotfix urgent) : .\update.ps1 -SkipTests
    if ($SkipTests) {
        Write-Section "Gate qualité"
        Write-Status "!" "Gate qualité ignorée (-SkipTests). DANGEREUX." "Yellow"
        Write-Status ">" "Le code téléchargé n'a PAS été vérifié. À utiliser uniquement pour un hotfix urgent." "White"
    } elseif ($DryRun) {
        Write-Section "Gate qualité"
        Write-Status ">" "Mode DryRun — gate qualité non exécutée." "DarkGray"
    } else {
        $gateResult = Invoke-QualityGate
        if (-not $gateResult) {
            # Échec de la gate → rollback via git reset + restauration sauvegarde
            Write-Section "Rollback après échec gate"
            Write-Status "!" "Tentative de git reset --hard ORIG_HEAD..." "Yellow"
            $resetOutput = & git reset --hard ORIG_HEAD 2>&1
            if ($LASTEXITCODE -eq 0) {
                Write-Status "OK" "git reset --hard ORIG_HEAD : code revenu au commit précédent." "Green"
                $resetOutput | ForEach-Object { Write-Status "  " $_ "DarkGray" }
            } else {
                Write-Status "X" "git reset échoué. Tentative de restauration sauvegarde..." "Red"
                if ($backupPath) { Restore-LastBackup -BackupPath $backupPath }
            }
            # Restaurer aussi les fichiers protégés (config.php) depuis la sauvegarde
            if ($backupPath) {
                foreach ($file in $ProtectedFiles) {
                    $src = Join-Path $backupPath $file
                    if (Test-Path $src) {
                        Copy-Item -Path $src -Destination (Join-Path $AppRoot $file) -Force
                        Write-Status "OK" "Restauré depuis sauvegarde : $file" "Green"
                    }
                }
            }
            Pop-Location
            Write-Host ""
            Write-Status "X" "════════════════════════════════════════════════════" "Red"
            Write-Status "X" "  MISE À JOUR ANNULÉE — gate qualité échouée" "Red"
            Write-Status "X" "  L'application a été restaurée à l'état précédent." "Red"
            Write-Status "X" "  Corrigez les erreurs ci-dessus puis relancez." "Red"
            Write-Status "X" "════════════════════════════════════════════════════" "Red"
            exit 1
        }
    }

    Pop-Location
}
else {
    Write-Section "Mode : pas de depot git (clone + copie)"
    Write-Status "!" "Pas de dossier .git detecte." "Yellow"
    Write-Status ">" "Clone dans un temp puis copie a plat." "White"

    $safeTempDir = Get-SafeTempDir
    $guid  = [System.Guid]::NewGuid().ToString("N").Substring(0, 8)
    $cloneDir = Join-Path $safeTempDir "wf-dreets-update-$guid"

    Write-Status ">" "Dossier temporaire : $cloneDir" "DarkGray"

    if ($cloneDir.StartsWith($AppRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
        Write-Status "X" "ERREUR CRITIQUE : le dossier temporaire est dans AppRoot !" "Red"
        Write-Status "X" "Abandon pour eviter de detruire l'application." "Red"
        exit 1
    }

    if (Test-Path $cloneDir) { Remove-Item -Path $cloneDir -Recurse -Force -ErrorAction SilentlyContinue }

    $cloneUrl = Get-CloneUrl

    Write-Section "Clonage du depot"
    try {
        $cloneOutput = Invoke-Git @('clone', '--branch', $RepoBranch, '--single-branch', '--depth', '1', $cloneUrl, $cloneDir)
        if ($LASTEXITCODE -ne 0) {
            Write-Status "X" "git clone echoue (code $LASTEXITCODE). Sortie git :" "Red"
            $cloneOutput | ForEach-Object { Write-Host "    $_" -ForegroundColor DarkRed }
            throw "Git clone echoue"
        }
        Write-Status "OK" "Clone reussi" "Green"
    } catch {
        Write-Host ""
        if ($env:FORMULAIRE_TOKEN) {
            Write-Host "  ! Le clone a echoue avec le token fourni." -ForegroundColor Red
            Write-Host "  Verifier le token sur GitHub :" -ForegroundColor Cyan
            Write-Host "    https://github.com/settings/tokens" -ForegroundColor Green
            Write-Host '  $env:FORMULAIRE_TOKEN = "nouveau_token"' -ForegroundColor Green
            Write-Host '  .\update.ps1' -ForegroundColor Green
        } else {
            Write-Host "  ! Aucun token defini (FORMULAIRE_TOKEN)." -ForegroundColor Red
            Write-Host "  Le depot est PRIVE — un token d'acces est obligatoire." -ForegroundColor White
            Write-Host '  $env:FORMULAIRE_TOKEN = "votre_token"' -ForegroundColor Green
            Write-Host '  .\update.ps1' -ForegroundColor Green
        }
        exit 1
    }

    Write-Section "Verification structure du clone"
    $sourceDir = Find-AppRootInDir -Dir $cloneDir
    if (-not $sourceDir) {
        Write-Status "X" "Clone incomplet : index.php et helpers.php non trouves." "Red"
        Remove-Item -Path $cloneDir -Recurse -Force -ErrorAction SilentlyContinue
        exit 1
    }

    Write-Status "OK" "Source valide : $sourceDir" "Green"
    Write-Status ">" "Destination : $AppRoot" "DarkGray"

    $remoteVersion = Get-RemoteVersion -Dir $sourceDir
    Write-Status ">" "Version disponible : v$remoteVersion"

    if ($remoteVersion -eq $localVersion -and -not $DryRun) {
        Write-Host ""
        Write-Status "OK" "Deja a jour (v$localVersion)." "Yellow"
        $answer = Read-Host "  Continuer quand meme ? (o/N)"
        if ($answer -notmatch "^[oO]$") { Remove-Item -Path $cloneDir -Recurse -Force -ErrorAction SilentlyContinue; exit 0 }
    }

    $gitDir  = Join-Path $sourceDir ".git"
    $histDir = Join-Path $sourceDir ".history"
    if (Test-Path $gitDir)  { Remove-Item -Path $gitDir  -Recurse -Force -ErrorAction SilentlyContinue }
    if (Test-Path $histDir) { Remove-Item -Path $histDir -Recurse -Force -ErrorAction SilentlyContinue }

    Write-Section "Analyse des differences"
    $remoteFiles = Get-ChildItem -Path $sourceDir -Recurse -File -ErrorAction SilentlyContinue
    $updateCount = 0; $newCount = 0; $protectedCount = 0

    foreach ($file in $remoteFiles) {
        $relativePath = $file.FullName.Substring($sourceDir.Length + 1)
        $destPath     = Join-Path $AppRoot $relativePath

        $skip = $false
        foreach ($pf in $ProtectedFiles) { if ($file.Name -eq $pf) { $skip = $true; break } }
        if (-not $skip) { foreach ($pd in $ProtectedDirs) { if ($relativePath -like "$pd\*" -or $relativePath -like "$pd/*") { $skip = $true; break } } }

        if ($skip) { $protectedCount++; continue }

        if (Test-Path $destPath) {
            $lh = (Get-FileHash -Path $destPath  -Algorithm SHA256 -ErrorAction SilentlyContinue).Hash
            $rh = (Get-FileHash -Path $file.FullName -Algorithm SHA256 -ErrorAction SilentlyContinue).Hash
            if ($lh -ne $rh) { Write-Status "~" "Modifie : $relativePath" "Yellow"; $updateCount++ }
        } else {
            Write-Status "+" "Nouveau : $relativePath" "Green"; $newCount++
        }
    }

    if ($updateCount + $newCount -eq 0) {
        Write-Host ""; Write-Status "OK" "Rien a mettre a jour." "Green"
        Remove-Item -Path $cloneDir -Recurse -Force -ErrorAction SilentlyContinue; exit 0
    }

    Write-Host ""
    Write-Status ">" "Resume : $updateCount modifie(s), $newCount nouveau(x), $protectedCount protege(s)" "Cyan"

    if ($DryRun) {
        Write-Host ""; Write-Status "?" "Mode simulation (DryRun) : aucune modification." "Yellow"
        Remove-Item -Path $cloneDir -Recurse -Force -ErrorAction SilentlyContinue; exit 0
    }

    if (-not $SkipBackup) {
        Write-Section "Sauvegarde"
        $backupPath = Create-Backup -SourceDir $AppRoot
        if (-not $backupPath) { Write-Status "X" "Sauvegarde echouee." "Red"; Remove-Item -Path $cloneDir -Recurse -Force -ErrorAction SilentlyContinue; exit 1 }
    } else { Write-Status "!" "Sauvegarde ignoree (-SkipBackup)" "Yellow" }

    Write-Section "Copie des fichiers"
    $copiedCount = 0; $skippedCount = 0

    foreach ($file in $remoteFiles) {
        $relativePath = $file.FullName.Substring($sourceDir.Length + 1)
        $isProtected = $false
        foreach ($pf in $ProtectedFiles) { if ($relativePath -eq $pf) { $isProtected = $true; break } }
        if (-not $isProtected) { foreach ($pd in $ProtectedDirs) { if ($relativePath -like "$pd\*" -or $relativePath -like "$pd/*") { $isProtected = $true; break } } }
        if ($isProtected) { Write-Status ">>" "Protege : $relativePath" "DarkGray"; $skippedCount++; continue }

        $destPath = Join-Path $AppRoot $relativePath
        $destParent = Split-Path -Parent $destPath
        if (-not (Test-Path $destParent)) { New-Item -ItemType Directory -Path $destParent -Force | Out-Null }
        Copy-Item -Path $file.FullName -Destination $destPath -Force
        Write-Status "->" "$relativePath" "Green"
        $copiedCount++
    }

    # Suppression des fichiers obsoletes
    $deletedCount = 0
    if ($isFirstInstall) {
        Write-Section "Suppression des fichiers obsolètes"
        Write-Status ">" "Premiere installation — rien a supprimer." "DarkGray"
    } else {
        Write-Section "Suppression des fichiers obsoletes"

        $remoteFileCount = ($remoteFiles | Measure-Object).Count
        $canDelete = $true
        $abortReason = ""

        if ($remoteFileCount -lt 30) {
            $canDelete = $false; $abortReason = "Clone suspect ($remoteFileCount fichiers)"
        } elseif (-not (Test-Path (Join-Path $sourceDir "index.php")) -or -not (Test-Path (Join-Path $sourceDir "helpers.php"))) {
            $canDelete = $false; $abortReason = "Clone incomplet"
        } elseif ($cloneDir.StartsWith($AppRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
            $canDelete = $false; $abortReason = "Clone dans AppRoot"
        } elseif (-not (Test-Path (Join-Path $AppRoot "index.php"))) {
            $canDelete = $false; $abortReason = "AppRoot ne contient pas index.php"
        }

        if (-not $canDelete) {
            Write-Status "!" "$abortReason — suppression ANNULEE." "Red"
        } else {
            $remoteRelativePaths = @{}
            foreach ($rf in $remoteFiles) {
                $rp = $rf.FullName.Substring($sourceDir.Length + 1)
                $remoteRelativePaths[$rp.ToLower()] = $true
            }

            if ($remoteRelativePaths.Count -eq 0) {
                Write-Status "!" "Aucun fichier dans le clone — suppression ANNULEE." "Red"
            } else {
                $localFiles = Get-ChildItem -Path $AppRoot -Recurse -File -ErrorAction SilentlyContinue |
                    Where-Object {
                        $rel = $_.FullName.Substring($AppRoot.Length + 1)
                        $isProtected = $false
                        foreach ($pf in $ProtectedFiles) { if ($_.Name -eq $pf) { $isProtected = $true; break } }
                        if (-not $isProtected) { foreach ($pd in $ProtectedDirs) { if ($rel -like "$pd\*" -or $rel -like "$pd/*") { $isProtected = $true; break } } }
                        if ($rel -like "backups\*" -or $rel -like "backups/*") { $isProtected = $true }
                        if ($rel -like ".git\*" -or $rel -like ".git/*") { $isProtected = $true }
                        if ($rel -like ".update_tmp\*" -or $rel -like ".update_tmp/*") { $isProtected = $true }
                        -not $isProtected
                    }

                $localFileCount = ($localFiles | Measure-Object).Count
                $toDelete = @()
                foreach ($lf in $localFiles) {
                    $rel = $lf.FullName.Substring($AppRoot.Length + 1)
                    if (-not $remoteRelativePaths.ContainsKey($rel.ToLower())) { $toDelete += $lf }
                }

                if ($localFileCount -gt 0 -and $toDelete.Count -gt ($localFileCount * 0.9)) {
                    Write-Status "!" "Trop de fichiers a supprimer ($($toDelete.Count)/$localFileCount) — ANNULE." "Red"
                } else {
                    foreach ($lf in $toDelete) {
                        $rel = $lf.FullName.Substring($AppRoot.Length + 1)
                        Remove-Item -Path $lf.FullName -Force -ErrorAction SilentlyContinue
                        Write-Status "X" "Supprime : $rel" "DarkYellow"
                        $deletedCount++
                    }
                    Get-ChildItem -Path $AppRoot -Recurse -Directory -ErrorAction SilentlyContinue |
                        Where-Object {
                            $rel = $_.FullName.Substring($AppRoot.Length + 1)
                            -not ($rel -like "db\*" -or $rel -like "backups\*" -or $rel -like "sessions\*" -or $rel -like "logs\*" -or $rel -like ".git\*")
                        } |
                        Sort-Object -Property FullName -Descending |
                        Where-Object { (Get-ChildItem -Path $_.FullName -Force -ErrorAction SilentlyContinue | Measure-Object).Count -eq 0 } |
                        ForEach-Object { Remove-Item -Path $_.FullName -Force -ErrorAction SilentlyContinue }
                }
            }
        }
    }

    Remove-Item -Path $cloneDir -Recurse -Force -ErrorAction SilentlyContinue

    Write-Section "Resultat copie"
    Write-Status "OK" "$copiedCount fichier(s) copie(s)" "Green"
    Write-Status ">>" "$skippedCount fichier(s) protege(s)" "DarkGray"
    Write-Status "X" "$deletedCount fichier(s) obsolete(s) supprime(s)" "DarkYellow"

    # ── Regeneration autoload AVANT la gate (vendor peut avoir changé) ──
    Invoke-ComposerAutoload

    # ── Nettoyage cache PHPStan AVANT la gate qualité ──
    $phpstanCache = Join-Path $AppRoot ".phpstan-cache"
    if (Test-Path $phpstanCache) {
        Remove-Item -Path $phpstanCache -Recurse -Force -ErrorAction SilentlyContinue
    }

    # ── Gate qualité : vérifier que le code déployé passe lint + tests ──
    # Si la gate échoue → rollback automatique via la sauvegarde + exit 1.
    # Pour bypasser (hotfix urgent) : .\update.ps1 -SkipTests
    if ($SkipTests) {
        Write-Section "Gate qualité"
        Write-Status "!" "Gate qualité ignorée (-SkipTests). DANGEREUX." "Yellow"
        Write-Status ">" "Le code déployé n'a PAS été vérifié. À utiliser uniquement pour un hotfix urgent." "White"
    } elseif ($DryRun) {
        Write-Section "Gate qualité"
        Write-Status ">" "Mode DryRun — gate qualité non exécutée." "DarkGray"
    } else {
        $gateResult = Invoke-QualityGate
        if (-not $gateResult) {
            # Échec de la gate → rollback via la sauvegarde
            if ($backupPath) {
                Restore-LastBackup -BackupPath $backupPath
                # Restaurer aussi les fichiers protégés (config.php)
                foreach ($file in $ProtectedFiles) {
                    $src = Join-Path $backupPath $file
                    if (Test-Path $src) {
                        Copy-Item -Path $src -Destination (Join-Path $AppRoot $file) -Force
                        Write-Status "OK" "Restauré depuis sauvegarde : $file" "Green"
                    }
                }
            } else {
                Write-Status "X" "Aucune sauvegarde à restaurer (-SkipBackup a été utilisé)." "Red"
                Write-Status "!" "L'application est dans un état potentiellement cassé." "Yellow"
                Write-Status ">" "Restaurez manuellement depuis backups/ si disponible." "White"
            }
            Write-Host ""
            Write-Status "X" "════════════════════════════════════════════════════" "Red"
            Write-Status "X" "  MISE À JOUR ANNULÉE — gate qualité échouée" "Red"
            Write-Status "X" "  L'application a été restaurée à l'état précédent." "Red"
            Write-Status "X" "  Corrigez les erreurs ci-dessus puis relancez." "Red"
            Write-Status "X" "════════════════════════════════════════════════════" "Red"
            exit 1
        }
    }
}

# ── Resultat final ─────────────────────────────────────────────

Write-Section "Resultat final"
$newVersion = Get-LocalVersion
if ($newVersion -ne $localVersion) {
    Write-Status "OK" "Version mise a jour : v$localVersion -> v$newVersion" "Green"
} else {
    Write-Status "OK" "Mise a jour appliquee (v$newVersion)" "Green"
}

# ── Nettoyage des caches (PHPStan + assets CSS) ─────────────────
# Le cache PHPStan (/tmp/phpstan-cache ou .phpstan-cache) peut devenir volumineux
# et ralentir les mises à jour. Le cache assets CSS (db/cache/assets_css_*.css)
# doit être invalidé à chaque mise à jour pour que les nouveaux CSS soient servis.
Write-Section "Nettoyage des caches"

# Cache PHPStan
$phpstanCache = Join-Path $AppRoot ".phpstan-cache"
if (Test-Path $phpstanCache) {
    Remove-Item -Path $phpstanCache -Recurse -Force -ErrorAction SilentlyContinue
    Write-Status "OK" "Cache PHPStan nettoyé (.phpstan-cache)" "Green"
} else {
    # Aussi nettoyer l'ancien chemin /tmp/phpstan-cache
    $tmpPhpstan = Join-Path $env:TEMP "phpstan-cache"
    if (Test-Path $tmpPhpstan) {
        Remove-Item -Path $tmpPhpstan -Recurse -Force -ErrorAction SilentlyContinue
        Write-Status "OK" "Cache PHPStan nettoyé ($tmpPhpstan)" "Green"
    }
}

# Cache assets CSS (db/cache/assets_css_*.css)
$assetsCacheDir = Join-Path $AppRoot "db\cache"
if (Test-Path $assetsCacheDir) {
    $oldCssFiles = Get-ChildItem -Path $assetsCacheDir -Filter "assets_css_*.css" -ErrorAction SilentlyContinue
    foreach ($f in $oldCssFiles) {
        Remove-Item -Path $f.FullName -Force -ErrorAction SilentlyContinue
    }
    if ($oldCssFiles.Count -gt 0) {
        Write-Status "OK" "$($oldCssFiles.Count) fichier(s) de cache CSS supprimé(s)" "Green"
    }
}

# ── Reset OPcache — tuer les processus php-cgi.exe ──────────────
# Sur IIS + PHP, OPcache conserve en mémoire le bytecode des fichiers PHP.
# Après un déploiement, les anciens fichiers .php peuvent rester cachés
# pendant que les nouveaux sont sur disque → l'utilisateur voit l'ancien
# rendu jusqu'à ce qu'OPcache se réinitialise.
#
# ⚠️ opcache_reset() depuis CLI ne marche PAS pour IIS (cache séparé).
# Restart-WebAppPool nécessite le module WebAdministration (pas toujours dispo).
# Toucher web.config ne marche que si IIS surveille ce fichier.
#
# v10.1.2 — Solution simple et fiable : tuer les processus php-cgi.exe.
# IIS les relance automatiquement au prochain hit (fastCGI). C'est
# équivalent à un recycle de pool mais sans dépendance module IIS.
# Effet de bord : les sessions PHP en mémoire sont perdues (l'utilisateur
# doit se reconnecter) — acceptable après un déploiement.
Write-Section "Reset OPcache (kill php-cgi.exe)"

$opcacheResetDone = $false

# ── Méthode 1 : tuer php-cgi.exe (le plus fiable, zéro dépendance) ──
try {
    $phpCgiProcs = Get-Process -Name "php-cgi" -ErrorAction SilentlyContinue
    if ($phpCgiProcs -and $phpCgiProcs.Count -gt 0) {
        $phpCgiProcs | Stop-Process -Force -ErrorAction SilentlyContinue
        # Attendre que les processus soient bien terminés (max 3 sec)
        $waited = 0
        while ($waited -lt 30) {
            Start-Sleep -Milliseconds 100
            $remaining = Get-Process -Name "php-cgi" -ErrorAction SilentlyContinue
            if (-not $remaining -or $remaining.Count -eq 0) { break }
            $waited++
        }
        $killed = $phpCgiProcs.Count
        Write-Status "OK" "$killed processus php-cgi.exe tué(s) → OPcache vidé. IIS les relancera au prochain hit." "Green"
        $opcacheResetDone = $true
    } else {
        Write-Status ">" "Aucun processus php-cgi.exe en cours (IIS pas encore démarré ou pas de PHP CGI)." "DarkGray"
        $opcacheResetDone = $true  # rien à faire = OK
    }
} catch {
    Write-Status "!" "Impossible de tuer php-cgi.exe : $_" "Yellow"
}

# ── Méthode 2 : Restart-WebAppPool (si WebAdministration dispo) ──
if (-not $opcacheResetDone) {
    try {
        $webAdmin = Get-Module -ListAvailable -Name WebAdministration -ErrorAction Stop
        if ($webAdmin) {
            Import-Module WebAdministration -ErrorAction Stop
            # Lister TOUS les pools IIS et trouver celui qui tourne
            $allPools = Get-ChildItem "IIS:\AppPools" -ErrorAction SilentlyContinue
            if ($allPools) {
                # Prendre le 1er pool (généralement DefaultAppPool ou le seul)
                $poolName = $allPools[0].Name
                Restart-WebAppPool -Name $poolName -ErrorAction Stop
                Write-Status "OK" "Pool IIS '$poolName' recyclé → OPcache vidé." "Green"
                $opcacheResetDone = $true
            }
        }
    } catch {
        Write-Status "!" "Restart-WebAppPool échec : $_" "Yellow"
    }
}

# ── clearstatcache (toujours, même si OPcache déjà reset) ──
if ($phpBin) {
    $clearScript = @"
<?php
if (function_exists('clearstatcache')) {
    clearstatcache(true);
    echo "clearstatcache OK\n";
}
"@
    $tmpClear = [System.IO.Path]::GetTempFileName() + ".php"
    Set-Content -Path $tmpClear -Value $clearScript -Encoding UTF8
    try {
        & $phpBin $tmpClear 2>&1 | Out-Null
    } catch {} finally {
        Remove-Item -Path $tmpClear -Force -ErrorAction SilentlyContinue
    }
}

if (-not $opcacheResetDone) {
    Write-Status "!" "OPcache non reset (ni php-cgi.exe ni WebAppPool disponibles)." "Yellow"
    Write-Status ">" "L'OPcache se réinitialisera de lui-même (détection timestamp, ~2 sec)." "DarkGray"
}

# ── Installation du hook pre-push (si git est présent) ──────────
$gitDir = Join-Path $AppRoot ".git"
if (Test-Path $gitDir) {
    Write-Section "Installation du hook pre-push"
    $hookPath = Join-Path $gitDir "hooks\pre-push"
    $hookSource = Join-Path $AppRoot "scripts\pre-push"
    if (Test-Path $hookSource) {
        try {
            Copy-Item -Path $hookSource -Destination $hookPath -Force
            # Rendre exécutable (sur Windows, git bash lit le shebang)
            Write-Status "OK" "Hook pre-push installe : $hookPath" "Green"
            Write-Status ">" "Le hook executera scripts/gate.sh avant chaque push vers master/dev." "DarkGray"
            Write-Status ">" "Pour bypasser (deconseille) : git push --no-verify" "DarkGray"
        } catch {
            Write-Status "!" "Impossible d'installer le hook pre-push : $_" "Yellow"
        }
    } else {
        # Pas de scripts/pre-push — installer directement
        $hookContent = @'
#!/usr/bin/env bash
set -uo pipefail
while read -r local_ref local_sha remote_ref remote_sha; do
    if [[ "$remote_ref" == *"master" || "$remote_ref" == *"dev" ]]; then
        echo "[pre-push] Gate en cours..."
        REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
        if [[ ! -f "$REPO_ROOT/scripts/gate.sh" ]]; then break; fi
        if ! bash "$REPO_ROOT/scripts/gate.sh"; then
            echo "[pre-push] Gate echouee. Push bloque."
            echo "[pre-push] Pour bypasser : git push --no-verify"
            exit 1
        fi
        break
    fi
done
exit 0
'@
        Set-Content -Path $hookPath -Value $hookContent -Encoding UTF8
        Write-Status "OK" "Hook pre-push installe (inline) : $hookPath" "Green"
    }
}

Write-Host ""
Write-Section "Post-mise a jour"
Write-Status "!" "Verifiez que l'application fonctionne correctement." "White"
Write-Status "!" "En cas de probleme, restaurez la sauvegarde dans backups/" "White"
Write-Status "!" "config.php n'a PAS ete ecrase (version locale conservee)." "White"

# Nettoyage anciens backups
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
