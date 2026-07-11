# Changelog — CircuitDémat

## [10.11.0] — 2026-07-11
_Résumé : Ultrareview — 38 constats corrigés, SQL contrôleurs → repositories, FieldService/ValidatorDataService mergés._

### 🐛 Bug fixes

- **C-1** ConditionEvaluator : opérateurs `equals`/`not_equals`/`contains` ajoutés — les conditions workflow fonctionnent
- **C-5** TokenService::cancel() : 3 UPDATEs enveloppés dans une transaction
- **W-1** TokenRepository::markExpired() : `datetime('now', '-1 second')` au lieu de `datetime('now')`
- **W-2** DashboardController : URL de redirection corrigée (`dashboard.phpfrom=` → `&from=`)
- **W-4** SubmissionRepository::saveValidatorData() : récupère le label au lieu de stocker le nom technique
- **W-14** SlugHelper::generateSlug() : `maxAttempts = 100` + RuntimeException
- **W-15** MailService::buildMailHtml() : `json_decode(...) ?: []`

### 🔒 Sécurité

- **W-5** DownloadController : nettoyage caractères de contrôle dans Content-Disposition
- **W-6** EmailVerificationService : filtrage `<>` dans commande SMTP RCPT TO
- **P-7** ConfirmActionController : CSRF vérifié avant rendu
- **P-6** ScreenshotController : check `realpath()` ajouté
- **P-3** AdminFormsHandlers/AdminAlerts/BackupController : erreurs PDO masquées aux users
- **W-7** SecurityService : log `error_log` quand TEST_MODE bypass CSRF
- **P-4** SecurityService : CSP nonce aléatoire au lieu de `unsafe-inline`

### ⚡ Performance

- **W-8** StatsService::getGlobalStats() : 11 requêtes → 1 requête GROUP BY
- **W-9** MonitoringController : batch query tokens pending (N+1 → 1)
- **P-8** BackupController : COUNT×13 → UNION ALL en 1 requête
- **C-2** ExportService : streaming CSV par batch 500 via json_each + LIMIT/OFFSET
- **C-3** FormTrackingController : pagination ajoutée (COUNT + LIMIT/OFFSET)

### 🏗 Refactor

- **C-7** SQL directes déplacées de 9 contrôleurs vers repositories (+30 méthodes repo)
- **W-17** ValidatorDataService délégué vers FieldService (4 méthodes dupliquées supprimées, -25% lignes)
- **P-1** TokenService::remind() : `relance_at` en UTC
- **P-2** SubmissionViewController : requête token réutilisée (suppression double exécution)
- **P-11** SettingsService::encrypt() : vérification longueur clé ≥ 32 octets

### 📊 Résultat

| Métrique | Avant 10.10.0 | Après 10.11.0 |
|----------|---------------|---------------|
| Tests | 995 | 995 (0 failures) |
| Constats ultrareview | 38 | 0 critiques restants |
| Repositories | 8 | 9 (+AlertRepository) |

---

## [10.10.0] — 2026-07-11
_Résumé : Suppression wrappers + render wrappers + h(), PHPStan -54%, +62 tests, lib/ vidé._

### 🏗 Suppression complète des wrappers procéduraux

**54 wrappers service** supprimés de `lib/service_wrappers.php` (fichier supprimé) :
- Attachment (6), Audit (3), Email Verification (5), Export/Cron (4), RGPD (3), Stats (3), Token (6), Webhook (2), Mail (7), ValidatorData (6), Workflow (9)

**10 render wrappers** supprimés/migrés :
- `render_submission_view.php` (17 fonctions mortes → supprimé)
- `render_ldap.php` → `LdapRenderer::datalist()`
- `render_install.php` → `InstallRenderer::renderPage()`
- `render_index.php` → `IndexRenderer::` (5 fonctions)
- `render_dashboard.php` → `DashboardRenderer::` (2 fonctions)
- `render_monitoring.php` → `MonitoringRenderer::` (3 fonctions)
- `docs_sections.php` → `DocumentationService::` (11 fonctions)
- `render_form.php` → `FormRenderer::` (5 fonctions, 25+ call sites)
- `render_errors.php` → `ErrorRenderer::` (2 fonctions + ErrorResponseException, 25+ call sites)
- `render_navigation.php` → `NavigationRenderer::` (7 fonctions + getAppName(), 13+ call sites)
- `security.php` → `SecurityService::` (3 fonctions)

**Dernier wrapper `h()`** supprimé :
- 544 call sites remplacés par `App::html()->escape()`
- `lib/html.php` supprimé

### 🔧 MailService consolidation

Méthodes `sendDetailed()`, `getRecentLogs()`, `buildMailHtml()` ajoutées à `MailService`.

### 📐 PHPStan baseline réduite

- Baseline : 312 → **142** erreurs (-54%)
- Stubs ajoutés : `phpstan_inst_stubs.php` (constantes INST, fonctions legacy)
- Fixes : strtotime() casts, null safety, static calls, return types

### 🧪 Tests ajoutés (+62)

- AttachmentService : +15 tests (upload errors, dangerous extensions, DB integration)
- AuthService : +17 tests (setMailer, requireAdmin, getUser, isAdmin, admin requests)
- ExportService : +12 tests (source analysis, BOM, delimiter, boolean conversion)
- WorkflowEngine : +41 tests (resolveDynamicRecipient, advanceWorkflow, validateToken, active submissions)

### 📊 Résultat

| Métrique | Avant 10.9.0 | Après 10.10.0 |
|----------|-------------|---------------|
| Tests | 933 | **995** (+62) |
| lib/ fichiers | 13 | **1** (core_bootstrap uniquement) |
| PHPStan baseline | 312 | **142** (-54%) |
| Wrappers procéduraux | 54 | **0** |
| Render wrappers | 10 | **0** |
| h() call sites | 544 | **0** |

---

## [10.9.0] — 2026-07-11
_Résumé : Suppression complète de service_wrappers.php (54 wrappers → appels DI directs), 933 tests._

### 🏗 Migration wrappers → DI directe

54 fonctions wrapper procédurales supprimées de `lib/service_wrappers.php` :
- **Attachment** (6) : `get_allowed_mime_types`, `get_allowed_extensions`, `get_max_file_size`, `handle_file_upload`, `get_attachments`, `get_attachment_by_id`
- **Audit** (3) : `app_log`, `security_log`, `get_audit_logs`
- **Email Verification** (5) : `verify_email_ldap`, `ldap_suggest`, `verify_email_smtp`, `verify_email`, `test_email_verification`
- **Export/Cron** (4) : `export_csv`, `run_lazy_cron`, `parse_db_datetime`, `handle_post`
- **RGPD** (3) : `rgpd_export_user_data`, `rgpd_delete_user_data`, `rgpd_auto_purge`
- **Stats** (3) : `search_submissions`, `get_stats_by_period`, `get_global_stats`
- **Token** (6) : `regenerate_token`, `cancel_submission`, `remind_one`, `get_tokens_for_submission`, `delegate_token`, `get_delegations`
- **Webhook** (2) : `send_webhook`, `get_db_size`
- **Mail** (7) : `_mail_service`, `send_mail`, `send_mail_detailed`, `log_mail_attempt`, `get_recent_mail_logs`, `build_mail_html`, `render_email_template`
- **ValidatorData** (6) : `get_submission_validator_data`, `save_validator_data`, `delete_validator_data`, `get_form_validator_fields`, `get_form_fields`, `get_validator_status_batch`
- **Workflow** (9) : `get_token_with_context`, `get_token_by_id_with_context`, `get_workflow_steps`, `get_submission_with_form_label`, `resolve_dynamic_recipient`, `advance_workflow`, `validate_token`, `has_active_submissions`, `has_active_step_submissions`

### 🔧 MailService consolidation

Méthodes `sendDetailed()`, `getRecentLogs()`, `buildMailHtml()` ajoutées à `MailService` (anciennement uniquement sur `MailerService`).

### 📊 Résultat

| Métrique | Avant | Après |
|----------|-------|-------|
| Wrappers dans service_wrappers.php | 54 | **0** (fichier vidé) |
| Appels wrappers production | ~80 | **0** |
| Appels wrappers tests | ~120 | **0** |
| Tests | 933 | **933** (0 failures) |

---

## [10.8.0] — 2026-07-10