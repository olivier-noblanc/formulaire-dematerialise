# Durcissement des drapeaux `actif` et du statut des soumissions

## Contrat strict

- Les représentations JSON de `forms.actif`, `steps[].actif` et des champs
  persistés correspondants sont strictement `0`, `1`, `"0"` ou `"1"`.
  Les booléens, `null`, chaînes vides, nombres hors domaine et autres chaînes
  sont rejetés (choix aligné sur le contrat strict de `required`).
- À l'écriture applicative, la valeur est normalisée en entier `0`/`1` avant
  le repository. Un import invalide est refusé avant ouverture de transaction
  et ne crée aucun formulaire partiel.
- `submissions.status` est obligatoire et limité aux valeurs de
  `App\\Enum\\SubmissionStatus` (`en_cours`, `valide`, `refuse`, `annule`).
  Les triggers utilisent `IS NULL OR ...` afin de rester explicites même sur
  une base momentanément non durcie; la migration impose ensuite `NOT NULL`.

## Migration et déploiement

1. Ajouter **v42**, migration indépendante et non rétroactive, qui préflight
   les `NULL` et valeurs hors domaine dans `forms.actif`, `steps.actif` et
   `alert_rules.actif`; elle s'arrête avant tout DDL avec un échantillon
   d'identifiants. Elle reconstruit chaque table avec `INTEGER NOT NULL
   DEFAULT 1 CHECK (actif IN (0,1))`, préserve les colonnes/indexes/FK, vérifie
   `foreign_key_check`, marque la version en dernier et restaure l'état
   `foreign_keys`. Toute erreur rollbacke et laisse la base exploitable.
2. Ajouter **v43**, migration indépendante et non rétroactive, avec préflight
   de `submissions.status` (`NULL` ou hors enum) avant DDL. Reconstruire
   `submissions` avec `TEXT NOT NULL DEFAULT 'en_cours' CHECK (...)`, recréer
   l'index unique actif et les triggers insert/update NULL-safe. Idempotence:
   version déjà marquée = no-op; les tables `_new` orphelines sont nettoyées
   dans la transaction. Les migrations historiques restent inchangées.
3. Déployer d'abord le code d'import/écriture (compatible avec les données
   existantes), sauvegarder la base, diagnostiquer les préflights, puis
   appliquer les migrations. Une anomalie bloque le déploiement: elle doit
   être corrigée explicitement, jamais convertie automatiquement. Vérifier
   les contraintes et la version après redémarrage; rollback applicatif via
   sauvegarde si migration interrompue.

## Code et tests TDD

- Renforcer `FormJsonValidator` pour `form.actif` et `steps[].actif`, puis
  l'import pour convertir seulement les quatre formes acceptées.
- Renforcer les repositories `forms`/`steps` pour valider `actif` à toute
  écriture, y compris update, sans toucher au correctif PHPStan de
  `ValidatorDataService`.
- Ajouter tests de validation/import pour `0`, `1`, `"0"`, `"1"`, booléen,
  `null`, chaîne invalide et nombre hors domaine; vérifier l'absence de ligne
  partielle après rejet.
- Ajouter tests v42/v43: préflight bloquant avant DDL (NULL et hors domaine),
  contraintes d'insertion/update, status NULL et invalide, rollback après
  échec de copie et idempotence.
- Lancer PHPUnit ciblé, `php -l` sur les fichiers modifiés et PHPStan via
  `vendor/bin/phpstan` si disponible. Ne pas lancer de commit/push.
