# force-update.ps1 — Télécharge TOUS les fichiers via curl.exe (proxy Negotiate)
# Usage : .\force-update.ps1
# Token : $env:FORMULAIRE_TOKEN ou prompt
# Proxy : détecté via $env:HTTPS_PROXY / $env:HTTP_PROXY (Windows)

$token = $env:FORMULAIRE_TOKEN
if (-not $token) {
    $token = Read-Host "Token Codeberg"
    $env:FORMULAIRE_TOKEN = $token
}
if (-not $token) { Write-Host "Token requis." -ForegroundColor Red; exit 1 }

# Détecter le proxy depuis les variables d'environnement
$proxy = $env:HTTPS_PROXY
if (-not $proxy) { $proxy = $env:https_proxy }
if (-not $proxy) { $proxy = $env:HTTP_PROXY }
if (-not $proxy) { $proxy = $env:http_proxy }
# Nettoyer les slashes trailing
if ($proxy) { $proxy = $proxy.TrimEnd('/') }

if ($proxy) {
    Write-Host "Proxy : $proxy" -ForegroundColor DarkGray
} else {
    Write-Host "Pas de proxy (connexion directe)" -ForegroundColor DarkGray
}

# Vérifier que curl.exe existe
$curlExe = "curl.exe"
if (-not (Get-Command $curlExe -ErrorAction SilentlyContinue)) {
    $curlExe = "C:\Windows\System32\curl.exe"
    if (-not (Test-Path $curlExe)) {
        Write-Host "curl.exe non trouve. Telechargement impossible." -ForegroundColor Red
        exit 1
    }
}
Write-Host "curl : $curlExe" -ForegroundColor DarkGray

# Liste des fichiers
$filesToDownload = @(
    "helpers.php","router.php","style.php","update.ps1",
    "index.php","form.php","validate.php","admin_forms.php","dashboard.php",
    "submission_view.php","admin_settings.php","admin_access.php","admin_alerts.php",
    "backup.php","monitoring.php","my_submissions.php","my_validations.php",
    "docs.php","download.php","rgpd.php","stats.php","health.php",
    "confirm_action.php","changelog.php","form_preview.php","form_tracking.php",
    "install.php","screenshot.php","remind.php","alert_check.php",
    "lib/autoloader.php","lib/core_bootstrap.php","lib/database.php","lib/auth.php",
    "lib/settings.php","lib/cache.php","lib/audit_log.php",
    "lib/test_mode.php","lib/email_verify.php","lib/mail.php","lib/workflow.php",
    "lib/filled_by.php","lib/conditions.php","lib/tokens.php","lib/attachments.php",
    "lib/rgpd.php","lib/stats.php","lib/webhook.php","lib/export_csv.php",
    "lib/lazy_cron.php","lib/render_navigation.php","lib/render_errors.php",
    "lib/render_form.php","lib/render_ldap.php","lib/jargon.php",
    "lib/html.php","lib/uuid.php","lib/date.php","lib/validation.php",
    "lib/admin_forms_handlers.php","lib/admin_forms_handlers_forms.php",
    "lib/admin_forms_handlers_steps.php","lib/admin_forms_json.php",
    "lib/admin_forms_render.php","lib/admin_forms_render_css.php",
    "lib/admin_forms_render_panels.php","lib/admin_forms_render_form.php",
    "lib/admin_forms_render_workflow.php","lib/admin_forms_render_fields.php",
    "lib/admin_forms_samples.php","lib/admin_settings_handlers.php",
    "lib/render_admin_settings.php","lib/render_backup.php","lib/render_dashboard.php",
    "lib/render_index.php","lib/render_install.php","lib/render_monitoring.php",
    "lib/render_monitoring_audit.php","lib/render_submission_view.php",
    "lib/render_submission_view_sections.php",
    "lib/docs_section_admin.php","lib/docs_section_agent.php","lib/docs_section_faq.php",
    "lib/docs_section_features.php","lib/docs_section_quickstart.php",
    "lib/docs_section_rgpd.php","lib/docs_section_roles.php","lib/docs_section_start.php",
    "lib/docs_section_technique.php","lib/docs_section_toc.php","lib/docs_section_validateur.php",
    "lib/style_tokens.css","lib/style_layout.css","lib/style_components.css",
    "lib/style_forms.css","lib/style_responsive.css","lib/style_features.css",
    "lib/style_onboarding.css","lib/style_pages.css","lib/index_page.css",
    "lib/dashboard_page.css","lib/admin_settings_page.css",
    "lib/admin_settings_scripts.js","lib/submission_view_page.css","lib/monitoring_page.css",
    "src/bootstrap.php","src/Core/App.php","src/Core/Config.php","src/Core/Database.php",
    "src/Auth/AuthService.php","src/Settings/SettingsService.php","src/Forms/FieldService.php",
    "src/Security/SecurityService.php","src/Mail/MailService.php","src/Audit/AuditLogService.php",
    "src/Cache/CacheService.php","src/Render/HtmlService.php",
    "src/Workflow/ConditionEvaluator.php","src/Workflow/WorkflowEngine.php",
    "src/View/ViewRenderer.php","src/View/EmailView.php",
    "src/Controller/BaseController.php","src/Controller/PageController.php",
    "src/Controller/IndexController.php","src/Controller/DashboardController.php",
    "src/Controller/FormController.php",
    "classes/DatabaseMigrations.php",
    "classes/migrations/schema_initial.php","classes/migrations/seed_default_forms.php",
    "classes/migrations/post_migration.php",
    "classes/migrations/v10.php","classes/migrations/v11.php","classes/migrations/v12.php",
    "classes/migrations/v13.php","classes/migrations/v14.php","classes/migrations/v15.php",
    "classes/migrations/v16.php","classes/migrations/v17.php","classes/migrations/v18.php",
    "classes/migrations/v19.php","classes/migrations/v20.php","classes/migrations/v21.php",
    "classes/migrations/v22.php",
    "assets/form-progress.js","assets/form-conditions.js",
    "samples/adaptation_poste_materiel.json"
)

$appRoot = $PSScriptRoot
if (-not $appRoot) { $appRoot = (Get-Location).Path }
$success = 0; $failCnt = 0

Write-Host ""
Write-Host "=== TELECHARGEMENT ($($filesToDownload.Count) fichiers) ===" -ForegroundColor Cyan

# Arguments communs curl
$curlArgs = @("-s", "-L", "--fail", "--show-error", "-u", "oliviernoblanc:$token")
if ($proxy) {
    $curlArgs += @("--proxy", $proxy, "--proxy-anyauth")
}

foreach ($file in $filesToDownload) {
    $url = "https://codeberg.org/oliviernoblanc/formulaire-dematerialise/raw/branch/master/$file"
    $dest = Join-Path $appRoot $file.Replace("/", "\")
    $destDir = Split-Path -Parent $dest
    if (-not (Test-Path $destDir)) { New-Item -ItemType Directory -Path $destDir -Force | Out-Null }

    # curl.exe -o <dest> <url>
    $allArgs = $curlArgs + @("-o", $dest, $url)
    
    $output = & $curlExe @allArgs 2>&1
    $exitCode = $LASTEXITCODE
    
    if ($exitCode -eq 0) {
        $success++
        Write-Host "  OK : $file" -ForegroundColor Green
    } else {
        $failCnt++
        $err = ($output | Select-Object -Last 1).ToString()
        if ($err.Length -gt 80) { $err = $err.Substring(0, 80) }
        Write-Host "  ERR : $file ($err)" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "$success OK / $failCnt echec sur $($filesToDownload.Count) fichiers" -ForegroundColor $(if ($failCnt -eq 0) {'Green'} else {'Yellow'})
if ($failCnt -gt 0) {
    Write-Host ""
    Write-Host "Si ERR sur tous : verifiez proxy + token" -ForegroundColor Yellow
}
Write-Host ""
Write-Host "config.php et db/ NON touches." -ForegroundColor DarkGray
Write-Host "Termine ! Testez l'application." -ForegroundColor Green
