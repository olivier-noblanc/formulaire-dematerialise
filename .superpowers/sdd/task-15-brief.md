# Task 15: Migrer AttachmentService vers AttachmentRepository

**Files:**
- Modify: `src/Attachment/AttachmentService.php:151,169,183`
- Modify: `helpers.php`
- Modify: `src/bootstrap.php`
- Modify: `tests/phpunit_bootstrap.php`

## Step 1: Inject AttachmentRepository

Ajouter en property et constructor :

```php
private AttachmentRepository $repo;

public function __construct(Database $db, AttachmentRepository $repo)
{
    $this->db = $db;
    $this->repo = $repo;
}
```

## Step 2: Remplacer les appels getPdo()

Lignes 151, 169, 183 : `$this->db->getPdo()` → `$this->repo->...`

## Step 3: Mettre à jour le DI

Dans helpers.php, src/bootstrap.php, tests/phpunit_bootstrap.php :
```php
// Avant
$_app->set(\App\Attachment\AttachmentService::class, new \App\Attachment\AttachmentService($_db_service));

// Après
$_attachment_repo = $_app->get(\App\Repository\AttachmentRepository::class);
$_app->set(\App\Attachment\AttachmentService::class, new \App\Attachment\AttachmentService($_db_service, $_attachment_repo));
```

## Step 4: Run all tests

Run: `rtk php phpunit.phar`
Expected: 531+ tests PASS

## Step 5: Commit

```bash
rtk git add src/Attachment/AttachmentService.php helpers.php src/bootstrap.php tests/phpunit_bootstrap.php
rtk git commit --author="onoblanc <olivier.noblanc@dreets.gouv.fr>" -m "refactor: AttachmentService uses AttachmentRepository"
```
