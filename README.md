# CircuitDémat — DREETS Bourgogne-Franche-Comté

> Système de validation dématérialisé pour la DREETS Bourgogne-Franche-Comté.
> Workflows de formulaires, suivi en temps réel, alertes automatiques J-N, supervision complète.

**Version 10.28.1** | PHP 8.4 • SQLite • IIS • PHPMailer • Zéro framework • Zéro CDN

---

## Aperçu

CircuitDémat dématérialise les circuits de validation RH et administratifs de la DREETS BFC.
L'agent remplit un formulaire, les validateurs reçoivent un lien à usage unique par email,
chacun valide ou refuse à son rythme — le système trace, relance et alerte automatiquement.

---

## Fonctionnalités

### Pour les agents

| Fonctionnalité | Description | Capture |
|---|---|---|
| **Formulaires dynamiques** | Champs configurables (texte, date, sélecteur, checkbox, textarea, fichier, email), groupés par sections visuelles | ![Onboarding](docs/screenshots/03_form_onboarding.png) |
| **Suivi des demandes** | Timeline visuelle, barres de progression, badges d'urgence deadline | ![Mes demandes](docs/screenshots/05_my_submissions.png) |
| **Détail complet** | Barre de progression, diagramme du circuit de validation, deadline, historique des validations | ![Détail](docs/screenshots/16_submission_view.png) |
| **Notifications email** | Confirmation de soumission, refus avec motif, validation finale du circuit | — |
| **Annulation en cours** | L'agent peut annuler une demande tant qu'elle n'est pas clôturée | — |
| **Droits RGPD** | Accès, rectification, effacement, mentions légales en bas de chaque formulaire | — |

### Pour les validateurs

| Fonctionnalité | Description | Capture |
|---|---|---|
| **Validation par email** | Lien à usage unique, aucune authentification nécessaire (accessible hors réseau DREETS) | — |
| **Dashboard validateur** | Tokens en attente, historique, détection des tokens expirés | ![Validations](docs/screenshots/06_my_validations.png) |
| **Progression visible** | Diagramme du circuit de validation affiché avant chaque décision | ![Validation](docs/screenshots/15_validate.png) |
| **Délégation** | Transfert d'une validation à un collègue, tracé dans l'historique avec motif | — |
| **Commentaire facultatif** | Ajout d'un commentaire lors de la validation ou du refus | — |

### Pour les administrateurs

| Fonctionnalité | Description | Capture |
|---|---|---|
| **Dashboard de supervision** | Vue d'ensemble, filtres, pagination, export CSV, deadline colorée | ![Dashboard](docs/screenshots/07_dashboard.png) |
| **Form builder visuel** | Création de formulaires sans compétence technique, auto-génération du nom technique | ![Form builder](docs/screenshots/10_admin_forms.png) |
| **Prévisualisation** | Aperçu exact du formulaire tel que l'agent le verra | ![Aperçu](docs/screenshots/17_form_preview.png) |
| **Circuit de validation** | Étapes séquentielles ou parallèles, destinataires multiples, recipients dynamiques `{{champ}}` | — |
| **Alertes J-N** | Alertes automatiques N jours avant une deadline, 6 cibles de notification (admin ou email personnalisé) — **réparées en v5.22.0** (bug `generate_uuid()` en SQL) | ![Alertes](docs/screenshots/11_admin_alerts.png) |
| **Monitoring** | Métriques, tokens bloqués, tokens expirés, SMTP health, donut CSS, audit log | ![Monitoring](docs/screenshots/08_monitoring.png) |
| **Statistiques** | Volumes globaux, par période / formulaire / validateur, graphique de répartition | — |
| **Tableau de suivi propriétaire** | Page `form_tracking.php` réservée aux owners d'un formulaire (cloisonnement strict) | — |
| **Régénération de tokens** | Renvoi d'un lien de validation pour un validateur bloqué | — |
| **Annulation de demande** | Clôture immédiate avec notification de l'agent | — |
| **Paramètres SMTP** | Configuration complète du serveur mail depuis l'interface (super admin) | ![Paramètres](docs/screenshots/12_admin_settings.png) |
| **Gestion des accès** | Demande, approbation, révocation des accès admin (super admin) | ![Accès](docs/screenshots/09_admin_access.png) |
| **Conformité RGPD** | Mentions légales, durée de conservation, export JSON, anonymisation, purge automatique | — |
| **Sauvegarde / restauration** | Téléchargement .db et restauration depuis l'interface | — |
| **Health check** | Page `health.php` (HTTP 200/503) pour la supervision externe | — |
| **Webhooks** | Notifications JSON sur événements clés (validation, refus, clôture) pour intégration SI | — |
| **Documentation in-app** | Guide utilisateur complet accessible depuis la barre de navigation | ![Docs](docs/screenshots/13_docs.png) |
| **Journal des versions** | `CHANGELOG.md` parsé et affiché dans l'interface | ![Changelog](docs/screenshots/14_changelog.png) |

---

## Architecture technique

| Composant | Technologie |
|---|---|
| Langage | PHP 8.4 orienté objet — 0 framework |
| Base de données | SQLite (embarquée, migration automatique versionnée, mode WAL) |
| Architecture | Services (DI container) + Repository pattern — 10 repositories, 10+ services |
| CSS | Pur — stylesheet partagée via `style.php`, design Marianne conforme RGAA |
| JavaScript | Aucun (CSP `script-src 'none'`) |
| Authentification | Windows Auth (IIS + Kerberos) via `$_SERVER['AUTH_USER']` |
| Mail | PHPMailer (seule dépendance, vendored) |
| Tâches planifiées | **Lazy cron** intégré — `remind.php` et `alert_check.php` au premier accès PDO |
| Sécurité | CSRF, PDO prepared statements, AES-256-CBC des settings, headers HTTP complets (CSP, HSTS, X-Frame-Options), contraintes CHECK SQL, rate limiting IIS natif |
| CI | GitHub Actions — 11 jobs (PHPStan level 8, PHPUnit coverage, Infection, Deptrac, CS Fixer, Rector, Composer audit, phpcpd, Playwright) |

### Principes

- **Zéro framework** : pas de Laravel, Symfony, React, Vue, Alpine
- **Zéro CDN** : aucune ressource externe, tout est local
- **Zéro fichier .css** : le CSS passe exclusivement par `style.php`
- **Future-proof** : le code doit tourner sans modification dans 10 ans
- **KISS** : pas de sur-architecture, pas de cache superflu, pas de couches d'abstraction inutiles
- **Erreurs visibles** : `display_errors=1` même en prod (sauf TEST_MODE)
- **Repository pattern** : tout accès DB passe par des repositories — pas de `get_pdo()` direct
- **Services via DI** : services enregistrés dans `src/bootstrap.php`, accessibles via `App::serviceName()`

---

## Déploiement

### Prérequis

- Windows Server avec IIS + PHP 8 FastCGI
- Authentification Windows (Kerberos) activée sur IIS
- SMTP accessible (intranet par défaut)
- Accès en écriture aux répertoires `db/` et `cache/`
- Extensions PHP : `pdo_sqlite`, `mbstring`, `ctype`, `openssl`

### Installation

1. Copier les fichiers dans `C:\inetpub\wwwroot\workflow\`
2. Adapter `config.php` (SMTP, email admin, BASE_URL) — ou utiliser `install.php` pour générer un `config.php` à partir d'un template
3. Accéder à l'application — la base SQLite est créée automatiquement au premier accès (migration v0→v11)
4. Vérifier l'état du système sur `health.php`
5. **Aucune tâche planifiée Windows à configurer** : `remind.php` (relances) et `alert_check.php` (alertes J-N) sont exécutés automatiquement par le mécanisme de **lazy cron** intégré (depuis v4.2.0) — ils tournent au premier accès PDO de l'heure / de la journée.
6. La **purge RGPD** des demandes clôturées au-delà de la durée de conservation est déclenchée manuellement depuis `rgpd.php` par un administrateur (bouton « Purge automatique »).

### Mise à jour

```powershell
.\update.ps1              # Mise à jour standard
.\update.ps1 -DryRun      # Simulation sans modification
```

Le script sauvegarde automatiquement l'existant et préserve `config.php`.

---

## Sécurité

| Mesure | Détail |
|---|---|
| CSRF | Tokens CSRF sur tous les formulaires POST, rotation après chaque POST réussi |
| Injection SQL | Requêtes préparées PDO exclusivement, validation centralisée `validate_input()` (9 règles) |
| Tokens de validation | `random_bytes(32)` — cryptographiquement sûrs, à usage unique, avec expiration |
| Validation emails | `filter_var(FILTER_VALIDATE_EMAIL)` sur tous les destinataires |
| Actions destructives | Protection par confirmation + CSRF |
| Liens d'approbation | En POST (pas d'effet de bord au GET) |
| Journal d'audit | Toutes les actions administratives tracées dans `audit_log` + `security_log()` |
| Chiffrement au repos | AES-256-CBC pour `smtp_pass`, `ldap_bind_pass`, `webhook_secret`, `app_test_secret` (clé via `APP_ENCRYPTION_KEY`) |
| Headers HTTP | CSP, HSTS (HTTPS), X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy |
| Rate limiting | Géré nativement par IIS (pas de duplication PHP) |
| Protection répertoires | `db/`, `classes/`, `PHPMailer/` bloqués via `web.config` IIS |
| Mode TEST sécurisé | Activation par variable d'environnement `APP_TEST_MODE` ou `APP_TEST_SECRET` (header seul bloqué en prod depuis v5.20.0) |

---

## Structure des fichiers

```
# Application — racine (5 fichiers)
config.php            Configuration (protégée par update.ps1)
helpers.php           Fonctions partagées + compat legacy
style.php             CSS commun — design Marianne / RGAA
router.php            Routeur pour le serveur PHP intégré (dev only)
health.php            Health check (HTTP 200/503)

# Application — pages utilisateur (15 fichiers)
index.php             Accueil adapté au rôle
form.php              Formulaire dynamique (?f=slug)
form_preview.php      Prévisualisation admin (?form_id=N)
validate.php          Validation par token (?t=TOKEN)
submission_view.php   Détail d'une demande (?id=N)
my_submissions.php    Suivi agent
my_validations.php    Dashboard validateur
dashboard.php         Supervision admin
form_tracking.php     Tableau de suivi propriétaire
stats.php             Statistiques
monitoring.php        Observabilité + audit log + SMTP health
admin_access.php      Gestion des accès admin
admin_forms.php       Back office formulaires
admin_alerts.php      Configuration des alertes J-N
admin_settings.php    Paramètres SMTP, relances

# Application — utilitaires (6 fichiers)
docs.php              Documentation utilisateur in-app
changelog.php         Journal des versions
rgpd.php              Conformité RGPD
backup.php            Sauvegarde / restauration .db
download.php          Téléchargement des pièces jointes
confirm_action.php    Confirmation d'actions sensibles

# Installation / déploiement (2 fichiers)
install.php           Assistant de génération de config.php
update.ps1            Script PowerShell de mise à jour

# Scripts CLI (2 fichiers) — lazy_cron
alert_check.php       Alertes J-N (1×/jour)
remind.php            Relances validateurs (1×/heure)

# Code source (src/)
src/
├── bootstrap.php       Enregistrement des services DI
├── helpers.php         Helpers chargés tardivement
├── Core/               App (container), exceptions, Config
├── Auth/               AuthService
├── Security/           SecurityService
├── Audit/              AuditLogService
├── Mail/               MailService
├── Cache/              CacheService
├── Render/             Renderers HTML (HtmlService, SubmissionViewRenderer…)
├── Forms/              Forms, Fields, ValidatorDataService
├── Workflow/           WorkflowEngine
├── Token/              TokenService
├── Export/             ExportService
├── Email/              EmailVerificationService
├── Validation/         ValidationService
├── Attachment/         AttachmentService
├── Settings/           SettingsService
├── Repository/         BaseRepository + 9 repositories métier
├── Controller/         Controllers (FormController, SubmissionView…)
└── Cron/               CronService

# Tests (PHPUnit + PHP + Playwright)
tests/
├── PHPUnit/            1249 tests unitaires
├── test_unit.php       Suite CLI legacy
├── test_e2e.php        Tests end-to-end PHP
├── test_bootstrap.php  Bootstrapper commun
├── playwright_test.js  Playwright (Firefox)
└── ...

# Documentation (4 fichiers)
README.md
CHANGELOG.md            Journal des modifications (30+ versions)
AGENTS.md               Guide technique pour agent IA
agent.md                Suivi de remédiation audit

# Dépendances (1 dossier)
PHPMailer/              PHPMailer 6.9.x (vendored)

# Captures d'écran
docs/screenshots/       21 captures
```

---

## Workflow de validation

```
┌─────────────┐     ┌─────────────────┐     ┌──────────────┐     ┌──────────────┐
│  Étape 1    │     │    Étape 2      │     │   Étape 3    │     │   Étape 4    │
│ Responsable │────→│ Service info.   │────→│ RH + Logist. │────→│  Direction   │
│  direct     │     │                 │     │  (parallèle) │     │              │
└─────────────┘     └─────────────────┘     └──────────────┘     └──────────────┘
     ↓ mail              ↓ mail               ↓ ↓ mail              ↓ mail
  Token unique        Token unique         2 tokens             Token unique
  Valider/Refuser     Valider/Refuser      Les 2 doivent        Valider/Refuser
                                            être validés
```

Chaque étape génère un token cryptographique envoyé par email au validateur. Le validateur clique sur le lien, voit la progression du circuit, et prend sa décision (valider ou refuser avec motif). Quand toutes les étapes sont validées, l'agent reçoit une confirmation. En cas de refus, le workflow s'arrête et l'agent est notifié avec le motif.

Les étapes peuvent être **séquentielles** (ordres différents) ou **parallèles** (même ordre — tous les destinataires doivent valider). Les recipients peuvent être **dynamiques** via la syntaxe `{{nom_du_champ}}` (résolus à partir des données du formulaire à l'exécution).

---

## Changelog récent

> Le journal complet est dans [`CHANGELOG.md`](CHANGELOG.md) — 30+ versions documentées.

### [10.28.1] — 2026-07-29 — Fix CI

- **11 erreurs PHPStan** `cast.useless` supprimées (baseline 510→497)
- CI repasse au vert (11/11 jobs)

### [10.28.0] — 2026-07-29 — Persona self-agent

- **16 bugs fixés** (4 HIGH, 6 MEDIUM, 8 LOW) dont RgpdService deleteUserData, WorkflowEngine stalled, FormController email validation
- **12 code smells** : god function `advanceWorkflow` splitée, enum `Annule`, migration v33 (10 CHECK constraints, FK delegations)
- **CI durcie** : 11 jobs, liste blanche secrets, dépendabot auto-merge
- **Coverage** : 27.9% → 33.5%
- **1416 tests** (0 fail)

### [10.25.0] — Repository pattern via PHPStan

- Règle `noDirectPdo` avec `spaze/phpstan-disallowed-calls`
- 14 services + 7 controllers migrés — 0 accès PDO direct
- 7 enums métier (SubmissionStatus, FieldType, ValidationAction…)
- Deptrac (6 layers, 0 violations)
- NoMagicStringRule PHPStan custom

### [10.22.0] — Bug bounty

- `send_mail()`/`build_mail_html()` n'existaient qu'en stub — Fatal Error runtime
- Bug fuseau remind.php, code mort MailerService, BaseController
- 16 bugs audit manuel confirmés fixés

---

## Conformité RGPD

- **Durée de conservation** : configurable (défaut 24 mois après clôture)
- **Purge automatique** : exécutée via lazy cron toutes les 24h
- **Droits utilisateur** : accès, rectification, effacement (`rgpd.php`)
- **Anonymisation** : soumissions purgées anonymisées (email → "anonymized", données supprimées)
- **Export JSON** : export complet des données personnelles
- **Journal d'audit** : toutes les actions RGPD tracées

Voir : `rgpd.php`, `docs/declaration-rgaa.md`

## Conformité RGAA 4.1

Déclaration d'accessibilité : `docs/declaration-rgaa.md`

CircuitDémat est **partiellement conforme** au RGAA 4.1.

## Facteur bus (Bus Factor)

> **Le facteur bus actuel est de 1.**

Le **facteur bus** (bus factor) est le nombre de personnes qui doivent être
indisponibles (départ, maladie, accident) pour que le projet devienne
inmaintenable. À 1, une seule personne connaît tout le code.

### Risques d'un facteur bus à 1

- **Départ** : si la personne quitte, personne ne peut reprendre
- **Maladie** : absence de 2 semaines = bugs non corrigés
- **Concentration de connaissance** : décisions d'architecture non documentées

### Plan pour augmenter le facteur bus

| Action | Statut | Priorité |
|--------|--------|----------|
| Documentation architecture (`docs/`) | ✅ Partiellement fait | Haute |
| CHANGELOG complet | ✅ À jour (v10.28.1) | Haute |
| Tests automatisés (gate qualité) | ✅ 1396 tests PHPUnit + Playwright + PHPStan level 8 | Haute |
| Déclaration RGAA | ✅ Créée (`docs/declaration-rgaa.md`) | Moyenne |
| Guide de maintenance | ⬜ À faire | Moyenne |
| Formation d'un 2e développeur | ⬜ À planifier | Haute |
| Revue de code par pair | ⬜ À mettre en place | Moyenne |

---

## Licence

Usage interne DREETS Bourgogne-Franche-Comté. Code source ouvert pour audit et maintenance.
