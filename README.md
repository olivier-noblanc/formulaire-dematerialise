# Formulaire Dématérialisé DREETS BFC

> Système de validation dématérialisé pour la DREETS Bourgogne-Franche-Comté.
> Workflows de formulaires, suivi en temps réel, alertes automatiques, supervision complète.

**Version 2.5.0** | PHP 8 • SQLite • Zéro framework • Zéro CDN

---

## Aperçu

| Vue Agent | Vue Admin |
|---|---|
| ![Accueil agent](docs/screenshots/01_index_agent.png) | ![Accueil admin](docs/screenshots/02_index_admin.png) |

---

## Fonctionnalités

### Pour les agents

| Fonctionnalité | Description | Capture |
|---|---|---|
| **Formulaires dynamiques** | Champs configurables (texte, date, sélecteur, checkbox, textarea), groupés par sections visuelles | ![Onboarding](docs/screenshots/03_form_onboarding.png) |
| **Suivi des demandes** | Timeline visuelle, barres de progression, badges d'urgence deadline | ![Mes demandes](docs/screenshots/05_my_submissions.png) |
| **Détail complet** | Barre de progression, diagramme workflow, deadline, historique validations | ![Détail](docs/screenshots/16_submission_view.png) |
| **Notifications email** | Confirmation de soumission, refus avec motif, validation finale du circuit | — |

### Pour les validateurs

| Fonctionnalité | Description | Capture |
|---|---|---|
| **Validation par email** | Lien à usage unique, aucune authentification nécessaire | — |
| **Dashboard validateur** | Tokens en attente, historique, détection des tokens expirés | ![Validations](docs/screenshots/06_my_validations.png) |
| **Progression visible** | Diagramme du circuit de validation affiché avant chaque décision | ![Validation](docs/screenshots/15_validate.png) |

### Pour les administrateurs

| Fonctionnalité | Description | Capture |
|---|---|---|
| **Dashboard de supervision** | Vue d'ensemble, filtres, pagination, export CSV, deadline colorée | ![Dashboard](docs/screenshots/07_dashboard.png) |
| **Form builder visuel** | Création de formulaires sans compétence technique, auto-génération du nom technique | ![Form builder](docs/screenshots/10_admin_forms.png) |
| **Prévisualisation** | Aperçu exact du formulaire tel que l'agent le verra | ![Aperçu](docs/screenshots/17_form_preview.png) |
| **Système d'alertes** | Alertes J-N jours avant une deadline, 6 cibles de notification | ![Alertes](docs/screenshots/11_admin_alerts.png) |
| **Monitoring** | Métriques, tokens bloqués, SMTP health, donut CSS, audit log | ![Monitoring](docs/screenshots/08_monitoring.png) |
| **Régénération de tokens** | Renvoi d'un lien de validation pour un validateur bloqué | — |
| **Annulation de soumission** | Clôture immédiate avec notification de l'agent | — |
| **Paramètres SMTP** | Configuration complète du serveur mail depuis l'interface | ![Paramètres](docs/screenshots/12_admin_settings.png) |
| **Gestion des accès** | Demande, approbation, révocation des accès admin | ![Accès](docs/screenshots/09_admin_access.png) |

---

## Architecture technique

| Composant | Technologie |
|---|---|
| Langage | PHP 8 procédural — aucun framework |
| Base de données | SQLite (embarquée, migration automatique) |
| CSS | Pur — stylesheet partagée via `style.php` (`require_once`), design Marianne |
| JavaScript | Minimal (confirms, toggles) — aucun framework JS |
| Authentification | Windows Auth (IIS + Kerberos) via `$_SERVER['AUTH_USER']` |
| Mail | PHPMailer (seule dépendance) |
| Scripts CLI | `remind.php` (relances) et `alert_check.php` (alertes deadline) |

### Principes

- **Zéro framework** : pas de Laravel, Symfony, React, Vue, Alpine
- **Zéro CDN** : aucune ressource externe, tout est local
- **Zéro fichier .css** : le CSS passe exclusivement par `style.php`
- **Future-proof** : le code doit tourner sans modification dans 10 ans
- **KISS** : chaque fichier fait une chose, pas d'abstraction inutile

---

## Déploiement

### Prérequis

- Windows Server avec IIS + PHP 8 FastCGI
- Authentification Windows (Kerberos) activée sur IIS
- SMTP accessible (intranet par défaut)
- Accès en écriture au répertoire `db/`

### Installation

1. Copier les fichiers dans `C:\inetpub\wwwroot\workflow\`
2. Adapter `config.php` (SMTP, email admin, BASE_URL)
3. Accéder à l'application — la base SQLite est créée automatiquement
4. Configurer les scripts CLI dans le Planificateur de tâches Windows :
   - `remind.php` toutes les 12h
   - `alert_check.php` toutes les 6h

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
| CSRF | Tokens CSRF sur tous les formulaires POST |
| Injection SQL | Requêtes préparées PDO exclusivement |
| Tokens de validation | `random_bytes(32)` — cryptographiquement sûrs, à usage unique, avec expiration |
| Validation emails | `filter_var(FILTER_VALIDATE_EMAIL)` sur tous les destinataires |
| Actions destructives | Protection par confirmation + CSRF |
| Liens d'approbation | En POST (pas d'effet de bord au GET) |
| Journal d'audit | Toutes les actions administratives tracées |

---

## Structure des fichiers

```
# Application (21 fichiers)
config.php            Configuration (protégée par update.ps1)
helpers.php           Fonctions partagées + moteur workflow + DB + test mode
style.php             CSS commun (inclus via require_once)
router.php            Routeur pour le serveur PHP intégré (dev only)

index.php             Accueil adapté au rôle
form.php              Formulaire dynamique (?f=slug)
form_preview.php      Prévisualisation admin (?form_id=N)
validate.php          Validation par token (?t=TOKEN)
submission_view.php   Détail complet d'une soumission (?id=N)
my_submissions.php    Suivi agent
my_validations.php    Dashboard validateur
dashboard.php         Supervision admin
monitoring.php        Observabilité + audit
admin_access.php      Gestion des accès admin
admin_forms.php       Back office formulaires
admin_alerts.php      Configuration alertes
admin_settings.php    Paramètres SMTP & relances
docs.php              Documentation utilisateur
changelog.php         Journal des versions

# Scripts CLI (2 fichiers)
alert_check.php       Vérification des deadlines (cron 6h)
remind.php            Relance automatique (cron 12h)

# Déploiement (1 fichier)
update.ps1            Script PowerShell de mise à jour

# Tests (3 fichiers)
test_all.php          Suite de tests CLI (47 tests)
test_api.php          API de test (header X-Test-Mode)
test_http.php         Suite de tests HTTP (12 phases)

# Documentation (3 fichiers)
AGENT.md              Instructions pour agent IA
CHANGELOG.md          Journal des modifications
README.md             Ce fichier

# Dépendance (1 dossier)
PHPMailer/            Librairie PHPMailer (seule dépendance)

# Captures d'écran (1 dossier)
docs/screenshots/     17 captures de l'application
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
