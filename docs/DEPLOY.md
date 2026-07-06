# Déploiement — CircuitDemat

## Prérequis

| Composant | Version requise | Vérification |
|-----------|----------------|--------------|
| Windows Server | 2016+ | `winver` |
| IIS | 10+ | Gestionnaire IIS |
| PHP | 8.4+ (FastCGI) | `php --version` |
| Extensions PHP | `pdo_sqlite`, `mbstring`, `ctype`, `openssl`, `session` | `php -m` |
| SMTP | Accessible (intranet) | `Test-NetConnection smtp.server -Port 25` |
| SQLite | Embarqué (pas d'installation séparée) | Via `pdo_sqlite` |

## Installation

### 1. Préparer IIS

```powershell
# Activer l'authentification Windows (Kerberos)
# Gestionnaire IIS → Site → Authentification → Windows = Activé

# Créer le site web
New-Website -Name "workflow" -PhysicalPath "C:\inetpub\wwwroot\workflow" -Port 80 -ApplicationPool "DefaultAppPool"
```

### 2. Déployer les fichiers

```powershell
# Copier le code dans le répertoire du site
Copy-Item -Path "\\serveur\share\formulaire-dematerialise\*" -Destination "C:\inetpub\wwwroot\workflow\" -Recurse
```

### 3. Configurer

```powershell
# Lancer l'assistant de configuration
# Navigateur → http://localhost/install.php
# Ou copier manuellement config.php.dist → config.php et adapter :
#   - SMTP_HOST, SMTP_PORT, SMTP_FROM
#   - ADMIN_EMAIL
#   - BASE_URL (ex: https://workflow.dreets.bfc.fr)
```

### 4. Permissions

```powershell
# Donner les droits d'écriture à IIS sur les répertoires de données
$apppool = "IIS AppPool\DefaultAppPool"
icacls "C:\inetpub\wwwroot\workflow\db" /grant "${apppool}:(OI)(CI)M" /T
icacls "C:\inetpub\wwwroot\workflow\download" /grant "${apppool}:(OI)(CI)M" /T
```

### 5. Vérifier

```powershell
# Health check
Invoke-WebRequest -Uri "http://localhost/health.php" -UseBasicParsing
# Doit retourner HTTP 200

# Ou en JSON
Invoke-WebRequest -Uri "http://localhost/health.php?format=json" -UseBasicParsing
```

## Mise à jour

```powershell
# Depuis le répertoire du projet
.\update.ps1              # Mise à jour standard
.\update.ps1 -DryRun      # Simulation sans modification
```

Le script :
1. Sauvegarde automatiquement l'existant dans `backups/`
2. Télécharge la dernière version depuis Codeberg
3. Préserve `config.php`, `db/workflow.db`, `.env`
4. Réinitialise l'OPcache IIS

### Après mise à jour

1. Vérifier `health.php` (HTTP 200)
2. Vérifier la version affichée dans l'interface
3. Si des migrations existent, elles s'exécutent automatiquement au premier accès

## Sauvegarde

### Automatique
Le script `update.ps1` crée un backup avant chaque mise à jour dans `backups/`.

### Manuelle
```
http://localhost/backup.php
→ Télécharger le fichier .db
```

### Restauration
```
http://localhost/backup.php
→ Upload du fichier .db (backup automatique avant restauration)
```

## Monitoring

| Endpoint | Usage |
|----------|-------|
| `health.php` | Health check HTTP 200/503 |
| `health.php?format=json` | Monitoring externe (Prometheus, UptimeRobot) |
| `monitoring.php` | Dashboard métriques (admin) |

## Lazy Cron

Aucune tâche planifiée Windows n'est requise. Le système de **lazy cron** s'exécute automatiquement au premier accès PDO :

| Tâche | Fréquence | Script |
|-------|-----------|--------|
| Relances validateurs | Toutes les heures | `remind.php` |
| Alertes J-N | Une fois par jour | `alert_check.php` |
| Purge RGPD | Une fois par jour | `rgpd_auto_purge()` |

## Dépannage

| Problème | Solution |
|----------|----------|
| Erreur 500 | Vérifier `display_errors=1` dans `php.ini`, consulter les logs IIS |
| Email non envoyé | `monitoring.php` → test SMTP, vérifier `smtp_host` dans settings |
| OPcache stale après mise à jour | `Restart-WebAppPool -Name "DefaultAppPool"` |
| SQLite locked | Vérifier que `db/` est accessible en écriture par IIS AppPool |
| Auth Windows ne fonctionne pas | Vérifier que l'authentification Windows est activée sur IIS |
