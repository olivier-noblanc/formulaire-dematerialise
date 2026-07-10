# Migrate service_wrappers.php to DI Container Calls

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace all procedural wrapper function calls in `lib/` and `pages/` with direct DI container calls (`\App\Core\App::*()`), then delete `lib/service_wrappers.php`.

**Architecture:** Each wrapper function in `service_wrappers.php` delegates to a service via the DI container. The migration replaces `function_name(...)` with the equivalent `\App\Core\App::service()->method(...)` call. Files in `src/` are NOT modified — those controllers/services keep their existing wrapper calls (they will be migrated separately or wrappers kept as a thin layer for them).

**Tech Stack:** PHP 8.1+, PHPUnit

## Global Constraints

- **NEVER modify files in `src/`** — only replace calls in `lib/`, `pages/`, and root-level PHP files
- **NEVER modify test files** (`tests/`) — tests should continue to work as-is
- **943 tests must pass** after each task
- Prefer the shortest, most direct replacement (use `App::service()` static methods when available, otherwise `App::getInstance()->get(ClassName::class)`)

## Wrapper → DI Mapping Reference

| Wrapper Function | DI Replacement |
|---|---|
| `app_log(...)` | `\App\Core\App::audit()->log(...)` |
| `security_log(...)` | `\App\Core\App::audit()->securityLog(...)` |
| `get_audit_logs(...)` | `\App\Core\App::audit()->getLogs(...)` |
| `send_mail(...)` | `\App\Core\App::mailer()->send(...)` |
| `send_mail_detailed(...)` | `\App\Core\App::mailer()->sendDetailed(...)` |
| `log_mail_attempt(...)` | `\App\Core\App::mailer()->logAttempt(...)` |
| `get_recent_mail_logs(...)` | `\App\Core\App::mailer()->getRecentLogs(...)` |
| `build_mail_html(...)` | `\App\Core\App::mailer()->buildMailHtml(...)` |
| `render_email_template(...)` | `\App\Core\App::mailer()->renderEmailTemplate(...)` |
| `get_allowed_mime_types()` | `\App\Core\App::attachment()->getAllowedMimeTypes()` |
| `get_allowed_extensions()` | `\App\Core\App::attachment()->getAllowedExtensions()` |
| `get_max_file_size()` | `\App\Core\App::attachment()->getMaxFileSize()` |
| `handle_file_upload(...)` | `\App\Core\App::attachment()->handleFileUpload(...)` |
| `get_attachments(...)` | `\App\Core\App::attachment()->getAttachments(...)` |
| `get_attachment_by_id(...)` | `\App\Core\App::attachment()->getAttachmentById(...)` |
| `get_submission_validator_data(...)` | `\App\Core\App::validatorData()->getSubmissionValidatorData(...)` |
| `save_validator_data(...)` | `\App\Core\App::validatorData()->saveValidatorData(...)` |
| `delete_validator_data(...)` | `\App\Core\App::validatorData()->deleteValidatorData(...)` |
| `get_form_validator_fields(...)` | `\App\Core\App::validatorData()->getFormValidatorFields(...)` |
| `get_form_fields(...)` | `\App\Core\App::validatorData()->getFormFields(...)` |
| `get_validator_status_batch(...)` | `\App\Core\App::validatorData()->getValidatorStatusBatch(...)` |
| `regenerate_token(...)` | `\App\Core\App::token()->regenerate(...)` |
| `cancel_submission(...)` | `\App\Core\App::token()->cancel(...)` |
| `remind_one(...)` | `\App\Core\App::token()->remind(...)` |
| `get_tokens_for_submission(...)` | `\App\Core\App::token()->getForSubmission(...)` |
| `delegate_token(...)` | `\App\Core\App::token()->delegate(...)` |
| `get_delegations(...)` | `\App\Core\App::token()->getDelegations(...)` |
| `verify_email(...)` | `\App\Core\App::emailVerify()->verify(...)` |
| `verify_email_ldap(...)` | `\App\Core\App::emailVerify()->verifyLdap(...)` |
| `verify_email_smtp(...)` | `\App\Core\App::emailVerify()->verifySmtp(...)` |
| `ldap_suggest(...)` | `\App\Core\App::emailVerify()->ldapSuggest(...)` |
| `test_email_verification(...)` | `\App\Core\App::emailVerify()->testVerification(...)` |
| `export_csv(...)` | `\App\Core\App::export()->exportCsv(...)` |
| `run_lazy_cron(...)` | `\App\Core\App::cron()->runLazyCron()` |
| `handle_post()` | `\App\Core\App::cron()->handlePost()` |
| `parse_db_datetime(...)` | `\App\Cron\CronService::parseDbDatetime(...)` |
| `send_webhook(...)` | `\App\Core\App::webhook()->send(...)` |
| `get_db_size()` | `\App\Core\App::webhook()->getDbSize()` |
| `get_global_stats()` | `\App\Core\App::getInstance()->get(\App\Stats\StatsService::class)->getGlobalStats()` |
| `get_stats_by_period(...)` | `\App\Core\App::getInstance()->get(\App\Stats\StatsService::class)->getStatsByPeriod(...)` |
| `search_submissions(...)` | `\App\Core\App::getInstance()->get(\App\Stats\StatsService::class)->searchSubmissions(...)` |
| `rgpd_export_user_data(...)` | `\App\Core\App::getInstance()->get(\App\Rgpd\RgpdService::class)->exportUserData(...)` |
| `rgpd_delete_user_data(...)` | `\App\Core\App::getInstance()->get(\App\Rgpd\RgpdService::class)->deleteUserData(...)` |
| `rgpd_auto_purge(...)` | `\App\Core\App::getInstance()->get(\App\Rgpd\RgpdService::class)->autoPurge(...)` |
| `validate_token(...)` | `\App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)->validateToken(...)` |
| `advance_workflow(...)` | `\App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)->advanceWorkflow(...)` |
| `get_token_with_context(...)` | `\App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)->getTokenWithContext(...)` |
| `get_token_by_id_with_context(...)` | `\App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)->getTokenByIdWithContext(...)` |
| `get_workflow_steps(...)` | `\App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)->getWorkflowSteps(...)` |
| `get_submission_with_form_label(...)` | `\App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)->getSubmissionWithFormLabel(...)` |
| `resolve_dynamic_recipient(...)` | `\App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)->resolveDynamicRecipient(...)` |
| `has_active_submissions(...)` | `\App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)->hasActiveSubmissions(...)` |
| `has_active_step_submissions(...)` | `\App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)->hasActiveStepSubmissions(...)` |

## Target Files (lib/ and pages/ only — NOT src/, NOT tests/)

| File | Functions Used |
|---|---|
| `lib/render_form.php` | `get_allowed_extensions`, `get_max_file_size` |
| `lib/render_ldap.php` | `ldap_suggest` |
| `lib/admin_settings_handlers.php` | `send_mail_detailed`, `send_webhook`, `test_email_verification` |
| `lib/admin_forms_handlers.php` | `has_active_submissions`, `has_active_step_submissions` |
| `lib/admin_forms_render_workflow.php` | `get_form_validator_fields` |
| `lib/conditions.php` | `get_submission_validator_data` |
| `lib/docs_section_technique.php` | (only in HTML comments — no code change needed) |
| `lib/render_monitoring.php` | (only in PHP comments — no code change needed) |
| `pages/validate.php` | `validate_token`, `get_token_with_context`, `save_validator_data`, `delete_validator_data`, `get_submission_validator_data`, `get_form_validator_fields`, `get_attachments`, `get_form_fields` |
| `pages/submission_view.php` | `get_workflow_steps`, `get_tokens_for_submission`, `regenerate_token`, `remind_one`, `delegate_token`, `cancel_submission`, `get_submission_validator_data`, `save_validator_data`, `delete_validator_data`, `get_form_validator_fields`, `get_attachments`, `get_delegations` |
| `pages/my_validations.php` | `delegate_token` |
| `pages/monitoring.php` | `send_mail_detailed`, `get_recent_mail_logs`, `get_global_stats` |
| `pages/stats.php` | `get_global_stats`, `get_stats_by_period`, `get_db_size` |
| `pages/rgpd.php` | `rgpd_export_user_data`, `rgpd_delete_user_data`, `rgpd_auto_purge`, `get_db_size` |
| `pages/download.php` | `get_attachment_by_id`, `get_allowed_mime_types` |
| `pages/form_tracking.php` | `get_form_fields` |
| `pages/form_preview.php` | `get_form_fields`, `get_workflow_steps` |
| `remind.php` | `send_mail`, `build_mail_html` |
| `alert_check.php` | `send_mail`, `render_email_template` |

---

### Task 1: Migrate Mail Service Calls (send_mail, send_mail_detailed, build_mail_html, render_email_template, get_recent_mail_logs)

**Files:**
- Modify: `remind.php`, `alert_check.php`, `pages/monitoring.php`, `lib/admin_settings_handlers.php`

**Interfaces:**
- Consumes: `App::mailer()` accessor from DI container
- Produces: All mail wrapper calls replaced with `\App\Core\App::mailer()->*()` calls

- [ ] **Step 1: Replace calls in `remind.php`**

In `remind.php`, replace:
- `send_mail(...)` → `\App\Core\App::mailer()->send(...)`
- `build_mail_html(...)` → `\App\Core\App::mailer()->buildMailHtml(...)`

- [ ] **Step 2: Replace calls in `alert_check.php`**

In `alert_check.php`, replace:
- `send_mail(...)` → `\App\Core\App::mailer()->send(...)`
- `render_email_template(...)` → `\App\Core\App::mailer()->renderEmailTemplate(...)`

- [ ] **Step 3: Replace calls in `pages/monitoring.php`**

In `pages/monitoring.php`, replace:
- `send_mail_detailed(...)` → `\App\Core\App::mailer()->sendDetailed(...)`
- `get_recent_mail_logs(...)` → `\App\Core\App::mailer()->getRecentLogs(...)`

- [ ] **Step 4: Replace calls in `lib/admin_settings_handlers.php`**

In `lib/admin_settings_handlers.php`, replace:
- `send_mail_detailed(...)` → `\App\Core\App::mailer()->sendDetailed(...)`
- `test_email_verification(...)` → `\App\Core\App::emailVerify()->testVerification(...)`
- `send_webhook(...)` → `\App\Core\App::webhook()->send(...)`

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add remind.php alert_check.php pages/monitoring.php lib/admin_settings_handlers.php
git commit -m "refactor: replace mail/webhook wrapper calls with DI container calls"
```

---

### Task 2: Migrate Form Rendering Calls (get_allowed_extensions, get_max_file_size, get_form_fields, get_workflow_steps)

**Files:**
- Modify: `lib/render_form.php`, `lib/admin_forms_render_workflow.php`, `pages/form_tracking.php`, `pages/form_preview.php`

**Interfaces:**
- Consumes: `App::attachment()`, `App::validatorData()` accessors
- Produces: All rendering-related wrapper calls replaced

- [ ] **Step 1: Replace calls in `lib/render_form.php`**

In `lib/render_form.php`, replace:
- `get_allowed_extensions()` → `\App\Core\App::attachment()->getAllowedExtensions()`
- `get_max_file_size()` → `\App\Core\App::attachment()->getMaxFileSize()`

- [ ] **Step 2: Replace calls in `lib/admin_forms_render_workflow.php`**

In `lib/admin_forms_render_workflow.php`, replace:
- `get_form_validator_fields(...)` → `\App\Core\App::validatorData()->getFormValidatorFields(...)`

- [ ] **Step 3: Replace calls in `pages/form_tracking.php`**

In `pages/form_tracking.php`, replace:
- `get_form_fields(...)` → `\App\Core\App::validatorData()->getFormFields(...)`

- [ ] **Step 4: Replace calls in `pages/form_preview.php`**

In `pages/form_preview.php`, replace:
- `get_form_fields(...)` → `\App\Core\App::validatorData()->getFormFields(...)`
- `get_workflow_steps(...)` → `\App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)->getWorkflowSteps(...)`

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 6: Commit**

```bash
git add lib/render_form.php lib/admin_forms_render_workflow.php pages/form_tracking.php pages/form_preview.php
git commit -m "refactor: replace rendering wrapper calls with DI container calls"
```

---

### Task 3: Migrate Stats/RGPD/Download Calls

**Files:**
- Modify: `pages/stats.php`, `pages/rgpd.php`, `pages/download.php`

**Interfaces:**
- Consumes: `App::getInstance()->get(StatsService::class)`, `App::getInstance()->get(RgpdService::class)`, `App::webhook()`, `App::attachment()`
- Produces: Stats, RGPD, and download wrapper calls replaced

- [ ] **Step 1: Replace calls in `pages/stats.php`**

In `pages/stats.php`, replace:
- `get_global_stats()` → `\App\Core\App::getInstance()->get(\App\Stats\StatsService::class)->getGlobalStats()`
- `get_stats_by_period(...)` → `\App\Core\App::getInstance()->get(\App\Stats\StatsService::class)->getStatsByPeriod(...)`
- `get_db_size()` → `\App\Core\App::webhook()->getDbSize()`

- [ ] **Step 2: Replace calls in `pages/rgpd.php`**

In `pages/rgpd.php`, replace:
- `rgpd_export_user_data(...)` → `\App\Core\App::getInstance()->get(\App\Rgpd\RgpdService::class)->exportUserData(...)`
- `rgpd_delete_user_data(...)` → `\App\Core\App::getInstance()->get(\App\Rgpd\RgpdService::class)->deleteUserData(...)`
- `rgpd_auto_purge(...)` → `\App\Core\App::getInstance()->get(\App\Rgpd\RgpdService::class)->autoPurge(...)`
- `get_db_size()` → `\App\Core\App::webhook()->getDbSize()`

- [ ] **Step 3: Replace calls in `pages/download.php`**

In `pages/download.php`, replace:
- `get_attachment_by_id(...)` → `\App\Core\App::attachment()->getAttachmentById(...)`
- `get_allowed_mime_types()` → `\App\Core\App::attachment()->getAllowedMimeTypes()`

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 5: Commit**

```bash
git add pages/stats.php pages/rgpd.php pages/download.php
git commit -m "refactor: replace stats/rgpd/download wrapper calls with DI container calls"
```

---

### Task 4: Migrate validate.php (Token + Validator + Workflow Calls)

**Files:**
- Modify: `pages/validate.php`

**Interfaces:**
- Consumes: `App::getInstance()->get(WorkflowEngine::class)`, `App::validatorData()`, `App::attachment()`
- Produces: All wrapper calls in validate.php replaced

- [ ] **Step 1: Replace calls in `pages/validate.php`**

In `pages/validate.php`, replace:
- `get_token_with_context(...)` → `\App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)->getTokenWithContext(...)`
- `validate_token(...)` → `\App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)->validateToken(...)`
- `get_submission_validator_data(...)` → `\App\Core\App::validatorData()->getSubmissionValidatorData(...)`
- `save_validator_data(...)` → `\App\Core\App::validatorData()->saveValidatorData(...)`
- `delete_validator_data(...)` → `\App\Core\App::validatorData()->deleteValidatorData(...)`
- `get_form_validator_fields(...)` → `\App\Core\App::validatorData()->getFormValidatorFields(...)`
- `get_attachments(...)` → `\App\Core\App::attachment()->getAttachments(...)`
- `get_form_fields(...)` → `\App\Core\App::validatorData()->getFormFields(...)`

Note: This file has many calls. Replace them carefully, ensuring argument lists match exactly.

- [ ] **Step 2: Run tests**

Run: `vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 3: Commit**

```bash
git add pages/validate.php
git commit -m "refactor: replace validate.php wrapper calls with DI container calls"
```

---

### Task 5: Migrate submission_view.php (Token + Workflow + Validator Calls)

**Files:**
- Modify: `pages/submission_view.php`

**Interfaces:**
- Consumes: All token, workflow, validator, and attachment accessors
- Produces: All wrapper calls in submission_view.php replaced

- [ ] **Step 1: Replace calls in `pages/submission_view.php`**

In `pages/submission_view.php`, replace:
- `get_workflow_steps(...)` → `\App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)->getWorkflowSteps(...)`
- `get_tokens_for_submission(...)` → `\App\Core\App::token()->getForSubmission(...)`
- `regenerate_token(...)` → `\App\Core\App::token()->regenerate(...)`
- `remind_one(...)` → `\App\Core\App::token()->remind(...)`
- `delegate_token(...)` → `\App\Core\App::token()->delegate(...)`
- `cancel_submission(...)` → `\App\Core\App::token()->cancel(...)`
- `get_submission_validator_data(...)` → `\App\Core\App::validatorData()->getSubmissionValidatorData(...)`
- `save_validator_data(...)` → `\App\Core\App::validatorData()->saveValidatorData(...)`
- `delete_validator_data(...)` → `\App\Core\App::validatorData()->deleteValidatorData(...)`
- `get_form_validator_fields(...)` → `\App\Core\App::validatorData()->getFormValidatorFields(...)`
- `get_attachments(...)` → `\App\Core\App::attachment()->getAttachments(...)`
- `get_delegations(...)` → `\App\Core\App::token()->getDelegations(...)`

- [ ] **Step 2: Run tests**

Run: `vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 3: Commit**

```bash
git add pages/submission_view.php
git commit -m "refactor: replace submission_view.php wrapper calls with DI container calls"
```

---

### Task 6: Migrate Remaining lib/ Files + Root Files

**Files:**
- Modify: `lib/conditions.php`, `lib/admin_forms_handlers.php`, `pages/my_validations.php`

**Interfaces:**
- Consumes: All remaining service accessors
- Produces: All remaining wrapper calls in lib/ and pages/ replaced

- [ ] **Step 1: Replace calls in `lib/conditions.php`**

In `lib/conditions.php`, replace:
- `get_submission_validator_data(...)` → `\App\Core\App::validatorData()->getSubmissionValidatorData(...)`

- [ ] **Step 2: Replace calls in `lib/admin_forms_handlers.php`**

In `lib/admin_forms_handlers.php`, replace:
- `has_active_submissions(...)` → `\App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)->hasActiveSubmissions(...)`
- `has_active_step_submissions(...)` → `\App\Core\App::getInstance()->get(\App\Workflow\WorkflowEngine::class)->hasActiveStepSubmissions(...)`

- [ ] **Step 3: Replace calls in `pages/my_validations.php`**

In `pages/my_validations.php`, replace:
- `delegate_token(...)` → `\App\Core\App::token()->delegate(...)`

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit`
Expected: All tests pass

- [ ] **Step 5: Commit**

```bash
git add lib/conditions.php lib/admin_forms_handlers.php pages/my_validations.php
git commit -m "refactor: replace remaining lib/pages wrapper calls with DI container calls"
```

---

### Task 7: Verify No Remaining References and Delete service_wrappers.php

**Files:**
- Delete: `lib/service_wrappers.php`
- Modify: `lib/core_bootstrap.php` (if it includes/requires service_wrappers.php)

**Interfaces:**
- Consumes: None
- Produces: service_wrappers.php deleted, all references removed

- [ ] **Step 1: Grep for remaining references**

Run: `rg -l "service_wrappers" --type php` and `rg -l "require.*service_wrappers|include.*service_wrappers" --type php`

Expected: Only `lib/core_bootstrap.php` or similar bootstrap file should reference it.

- [ ] **Step 2: Remove the require/include of service_wrappers.php**

In `lib/core_bootstrap.php` (or wherever it's loaded), remove the `require_once` / `include_once` line for `service_wrappers.php`.

- [ ] **Step 3: Verify no remaining calls to wrapper functions in lib/ and pages/**

Run: `rg -n "function_name\(" --type php lib/ pages/ alert_check.php remind.php` for each wrapper function name.

Expected: No matches (except in HTML comments, which are fine).

- [ ] **Step 4: Delete service_wrappers.php**

```bash
rm lib/service_wrappers.php
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit`
Expected: All 943 tests pass

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "refactor: delete service_wrappers.php — all calls migrated to DI container"
```
