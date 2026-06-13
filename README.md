# Workflow DREETS BFC — Système de validation dématérialisé

Application PHP de gestion de workflows de validation de formulaires pour la DREETS Bourgogne-Franche-Comté. Permet de dématérialiser les circuits de validation (onboarding, outboarding, etc.) avec suivi en temps réel, alertes automatiques et supervision complète.

**Version actuelle : 2.5.0**

---

## Fonctionnalités principales

### Pour les agents
- **Remplissage de formulaires dynamiques** : champs configurables par l'admin (texte, date, sélecteur, checkbox, textarea), groupés par sections visuelles
- **Suivi des demandes** : page « Mes demandes » avec timeline visuelle, barres de progression, badges d'urgence deadline
- **Notifications email** : confirmation de soumission, refus avec motif, validation finale du circuit

### Pour les validateurs
- **Validation par email** : lien à usage unique, aucune authentification nécessaire
- **Dashboard validateur** : vue des tokens en attente, historique des validations, détection des tokens expirés
- **Progression visible** : diagramme du circuit de validation affiché avant chaque décision

### Pour les administrateurs
- **Dashboard de supervision** : vue d'ensemble des soumissions, filtres, pagination, export CSV
- **Form builder visuel** : création de formulaires sans compétence technique — auto-génération du nom technique, options une par ligne, diagramme du circuit de validation, prévisualisation
- **Gestion des workflows** : étapes séquentielles ou parallèles, destinataires configurables
- **Système d'alertes paramétrable** : alertes automatiques J-N jours avant une deadline si des étapes sont incomplètes, 6 cibles de notification possibles
- **Monitoring** : métriques globales, tokens bloqués/expirés, santé SMTP, graphique camembert CSS, journal d'audit
- **Régénération de tokens** : renvoi d'un lien de validation pour un validateur bloqué
- **Annulation de soumission** : clôture immédiate avec notification de l'agent
- **Paramètres SMTP** : configuration complète du serveur mail depuis l'interface

---

## Architecture technique

- **PHP 8 procédural** — aucun framework, aucune dépendance hormis PHPMailer
- **SQLite** — base embarquée, migration automatique au premier accès
- **CSS pur** — pas de framework CSS, design Marianne, stylesheet partagée via `style.php`
- **Zéro JavaScript** côté client (sauf confirms et toggles minimes)
- **Auth Windows** — IIS + Kerberos, `$_SERVER['AUTH_USER']`
- **Scripts CLI** — relance automatique (`remind.php`) et vérification d'alertes (`alert_check.php`)

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
Exécuter `update.ps1` en PowerShell :
```powershell
.\update.ps1              # Mise à jour standard
.\update.ps1 -DryRun      # Simulation sans modification
```
Le script sauvegarde automatiquement l'existant et préserve `config.php`.

---

## Sécurité

- Tokens CSRF sur tous les formulaires POST
- Requêtes préparées PDO (pas d'injection SQL)
- Validation des emails destinataires
- Actions destructives protégées par confirmation + CSRF
- Liens d'approbation admin en POST (pas d'effet de bord au GET)
- Tokens de validation cryptographiques (`random_bytes(32)`), à usage unique, avec expiration
- Journal d'audit de toutes les actions administratives

---

## Structure des fichiers

```
config.php            Configuration (protégée par update.ps1)
helpers.php           Fonctions partagées + moteur workflow + DB
style.php             CSS commun (inclus via require_once)
index.php             Accueil adapté au rôle
form.php              Formulaire dynamique
form_preview.php      Prévisualisation admin
validate.php          Validation par token
submission_view.php   Détail complet d'une soumission
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
alert_check.php       Script CLI alertes (cron)
remind.php            Script CLI relances (cron)
update.ps1            Script PowerShell de mise à jour
AGENT.md              Instructions pour agent IA
CHANGELOG.md          Journal des modifications
README.md             Ce fichier
```
