# Rapport CTO — CircuitDémat
_Généré le 2026-07-08_

## 1. État actuel
- 9 services extraits de lib/ vers src/
- 341 tests PHPUnit, 525 assertions, 4 failures (en cours de correction)
- Branche: master
- 87 communautés détectées, 10 god functions identifiées

## 2. Extraction des services
| Étape | Service | Statut | Tests |
|-------|---------|--------|-------|
| — | MailService | ✅ | — |
| — | PersonaService | ✅ | — |
| — | RgpdService | ✅ | — |
| — | StatsService | ✅ | — |
| — | WorkflowService | ✅ | — |
| 4a | TokenService | ✅ | 8 |
| 4b | AttachmentService | ✅ | 8 |
| 4c | AuditLogService | ✅ | 13 |
| 4d | ValidatorDataService | ✅ | 12 |

## 3. Analyse Graphify
### God Functions (par degré)
| Fonction | Degré | Rôle | Action |
|----------|-------|------|--------|
| `h()` | 83 | Utility HTML escape | Garder (utility pure) |
| `get_pdo()` | 52 | Accès base de données | ✅ Délégué à Database |
| `app_log()` | 41 | Audit logging | En cours → AuditLogService wrappers |
| `get_setting()` | 36 | Paramètres applicatifs | ✅ Délégué à SettingsService |
| `Database` | 27 | Connexion DB | ✅ Service existant |
| `generate_uuid()` | 25 | Génération UUID | ✅ Délégué à lib/uuid.php |
| `WorkflowEngine` | 24 | Moteur de workflow | ✅ Service existant |
| `db_migrate()` | 22 | Migrations DB | Étape 5+ |
| `BaseController` | 22 | Contrôleur de base | Refactor DI |
| `get_auth_user()` | 21 | Authentification | ✅ Délégué à AuthService |

### Communautés
- **Nombre de communautés:** 87
- **Cohésion moyenne:** ~0.45 (hors communautés singleton)
- **Communauté 0 (catch-all):** cohésion 0.058 — le plus gros réservoir de code non structuré (lib_auth, lib_mail, lib_tokens, lib_workflow, lib_webhook, lib_stats, lib_persona, lib_audit_log, lib_database, helpers)
- **Communauté 1:** cohésion 0.055 — rendu admin (lib_admin_forms_render, lib_render_dashboard, lib_render_monitoring, lib_render_submission_view)
- **Communauté 2:** cohésion 0.054 — formulaires + controllers (lib_attachments, lib_render_form, src_controller_formcontroller)
- **Communauté 3:** cohésion 0.106 — handlers admin (lib_admin_forms_handlers, lib_database, lib_validation, lib_uuid)

## 4. Couplage et risques
### Bridge nodes (betweenness centrale)
- **`h()`** — centralité 0.210, connecte 8 communautés (0, 1, 2, 3, 5, 6, 8, 10, 15). Nœud critique : toute modification de signature impacte tout le projet.
- **`BaseController`** — centralité 0.100, connecte 10 communautés. Point de couplage entre tous les controllers.
- **`db_migrate()`** — centralité 0.092, connecte 15 communautés (v10-v25, schéma initial, seed).

### Relations surprenantes (inferred)
- `count_purge_targets()` (pages/backup.php) → `get_pdo()` — connecte communautés séparées
- `get_global_stats()` (lib/stats.php) → `_dbm_q()` — couplage stats ↔ migrations
- `build_alert_html()` → `render_email_template()` — couplage alertes ↔ mail
- `resolve_recipients()` → `_dbm_q()` — couplage alertes ↔ migrations

### Risques majeurs
1. **Communauté 0** (cohésion 0.058) : 88 nœuds, mélange auth, mail, tokens, workflow, audit — risque de régression élevé lors de toute extraction
2. **`h()` avec 79 arêtes inferred** : relations non vérifiées, peut masquer des bugs potentiels
3. **`get_pdo()` avec 48 arêtes inferred** : accès DB non centralisé malgré le service Database
4. **4 failures actuelles** : 3 dans CronServiceTest (gmdate() type error), 1 dans SettingsServiceTest

## 5. Recommandations
### Court terme (Étape 6)
- Corriger les 4 failures avant toute nouvelle extraction
- `app_log()` (41 appels) → déléguer vers `App::audit()->log()` — wrappers déjà en place
- `get_setting()` (36 appels) → déléguer vers `App::settings()->get()` — service existant
- Valider les 79 arêtes inferred de `h()` — risque de régression silencieux

### Moyen terme
- Décomposer la communauté 0 en sous-services cohérents :
  - **Auth module** : lib_auth + lib_persona → consolider dans AuthService
  - **Mail module** : lib_mail → déjà consolidé dans MailService, supprimer les appels directs
  - **Token module** : lib_tokens → consolider dans TokenService
  - **Workflow module** : lib_workflow → consolider dans WorkflowService
  - **Webhook module** : lib_webhook → absorber dans un service existant
- Extraire `email_verify.php` → EmailVerificationService (6 fonctions, 421 lignes)
- Extraire `export_csv.php` → ExportService (1 fonction)
- Extraire `lazy_cron.php` → CronService (3 fonctions) — corriger le bug gmdate() d'abord

### Long terme
- Refactor DI dans BaseController (22 connexions)
- Centraliser `db_migrate()` (22 connexions, 15 communautés)
- Passer de 87 communautés à < 20 communautés cibles (cohésion > 0.2)

## 6. Plan d'action
| Phase | Action | Priorité | Estimation |
|-------|--------|----------|------------|
| Bugfix | Corriger 4 failures (CronService gmdate, SettingsService) | P0 | 0.5 jour |
| Étape 6a | `app_log()` → `App::audit()->log()` (41 appels) | P1 | 1 jour |
| Étape 6b | `get_setting()` → `App::settings()->get()` (36 appels) | P1 | 1 jour |
| Étape 5a | CronService extraction + correction gmdate | P1 | 1 jour |
| Étape 5b | EmailVerificationService extraction | P2 | 1 jour |
| Étape 5c | ExportService extraction | P2 | 0.5 jour |
| Étape 5d | WebhookService absorption | P2 | 0.5 jour |
| Refactor | BaseController DI + `h()` validation | P3 | 2 jours |
| Refactor | Communauté 0 decomposition | P3 | 3 jours |
| Qualité | Réduire à < 20 communautés | P3 | 5 jours |
