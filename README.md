# Workflow DREETS - Système d'administration

## Modifications apportées

Ce projet a été modifié pour implémenter un système d'administration sécurisé :

### 1. Configuration de l'administrateur principal

Dans `config.php`, un nouveau paramètre a été ajouté :
```php
define('ADMIN_EMAIL',    'admin@dreets.gouv.fr');
```

### 2. Système d'accès admin

#### Nouveau fichier : `admin_access.php`
- Page d'accès au back office avec demande d'accès admin
- Affichage de l'email de l'administrateur principal
- Interface pour les utilisateurs demandant l'accès admin
- Interface d'administration pour l'admin principal

#### Modification de `index.php`
- Redirection vers `admin_access.php` au lieu d'accéder directement au back office

#### Base de données mise à jour (`init_db.php`)
- Table `admins` : stocke les emails des administrateurs
- Table `admin_requests` : stocke les demandes d'accès admin

#### Fonctions ajoutées dans `helpers.php`
- `is_admin_user()` : vérifie si l'utilisateur est administrateur
- `is_super_admin()` : vérifie si l'utilisateur est l'admin principal
- `process_admin_request()` : traite les demandes d'accès
- `approve_admin_request()` : approuve une demande d'accès
- `reject_admin_request()` : refuse une demande d'accès
- `remove_admin()` : supprime un administrateur

### 3. Fonctionnalités implémentées

#### Accès utilisateur :
1. Les utilisateurs peuvent demander l'accès admin via la page `admin_access.php`
2. Une fois la demande envoyée, un email est envoyé à l'administrateur principal
3. L'utilisateur reçoit un email de confirmation ou de refus

#### Administration :
1. L'administrateur principal peut voir les demandes en attente
2. Il peut approuver ou refuser les demandes
3. Il peut gérer la liste des administrateurs
4. Il peut supprimer des administrateurs (sauf lui-même)

### 4. Sécurité

- L'accès au back office est restreint aux utilisateurs approuvés
- L'administrateur principal ne peut pas être supprimé
- Les demandes d'accès sont stockées dans la base de données
- Les emails sont envoyés via le serveur SMTP configuré

### 5. Utilisation

1. Accédez à la page d'accès admin : `admin_access.php`
2. Connectez-vous avec votre compte Windows Auth
3. Si vous n'avez pas d'accès, cliquez sur "Demander l'accès admin"
4. L'administrateur principal recevra un email de notification
5. Une fois approuvé, vous pouvez accéder au back office via `index.php`
