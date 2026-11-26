# Squash des migrations et baseline du schéma v42/v43

## Objectif

Remplacer l’historique de migrations v1 à v41 par une création initiale complète
représentant l’état final v42, puis conserver v43 comme migration versionnée
corrigée pour `submissions.status` et l’autorisation de plusieurs soumissions
actives. La préproduction ne contient aucune base à préserver : le déploiement
repart d’une base vierge et les futures évolutions commencent à v44.

La validation finale appartient à l’orchestrateur. Ce plan ne prévoit aucun
traitement, remplacement ou migration de `workflow.db`, `db/workflow.db` ou de
toute autre base locale existante.

## Décisions et invariants non négociables

- Supprimer les migrations v1 à v41 et leurs chemins d’exécution ; ne pas
  ajouter de compatibilité pour des bases historiques.
- `schema_initial.php` crée directement le schéma complet correspondant à v42,
  avec les colonnes, index, clés étrangères, triggers, contraintes et seeds
  nécessaires à l’application actuelle.
- Une base vierge est marquée baseline `schema_version = 42` après création
  atomique et vérification du schéma/seeding. Elle ne rejoue pas v1…v42.
- v43 reste un fichier de migration séparé et est exécutée ensuite. Elle impose
  `submissions.status NOT NULL` et son `CHECK` cohérent avec
  `App\Enum\SubmissionStatus`, recrée les triggers de validation et ne crée
  **aucun index unique** sur les soumissions actives par formulaire et agent.
- Le contrat applicatif `required ∈ {0,1}` et `actif ∈ {0,1}` est reflété par
  `NOT NULL`, `DEFAULT` et `CHECK` SQL dans la baseline ; `status` suit les
  mêmes valeurs que l’enum PHP.
- Les index uniques légitimes (PK, `forms.slug`, `tokens.token`, etc.) sont
  conservés ; l’index unique global `submissions(form_id, submitted_by)` avec
  filtre `status = 'en_cours'` est explicitement absent.
- Les seeds nécessaires aux installations vierges sont conservés, rendus
  idempotents et exécutés depuis le chemin de bootstrap de la baseline ; les
  seeds différés ne doivent plus dépendre d’une version v1…v41.

## Phase 1 — Inventaire exhaustif de l’état final v42+v43

1. Lire chaque migration v1…v43, `schema_initial.php`,
   `seed_default_forms.php`, `post_migration.php`, les repositories et les
   scripts d’exécution afin de produire une matrice « objet / source historique
   / état final / seed / consommateur » avant toute suppression.
2. Vérifier l’inventaire contre `sqlite_master` d’une base de test créée par le
   code actuel, sans ouvrir ni modifier les bases locales du dépôt. L’inventaire
   doit lister les colonnes, types, nullabilité, valeurs par défaut, PK, FK et
   `ON DELETE` pour :
   - `schema_version` ;
   - `forms` (`deadline_field` inclus) ;
   - `steps` (`condition` inclus) ;
   - `step_recipients` ;
   - `submissions` (dont `status`, `admin_comment`, `rgpd_consent`) ;
   - `tokens` (dont `action`, `invalidated_at`) ;
   - `admins`, `admin_requests` (dont `reviewed_at`, `reviewed_by`) ;
   - `settings` ;
   - `form_fields` (dont `required`, `card_group`, `filled_by`,
     `validator_step`, `visibility`, `condition`) ;
   - `audit_log`, `alert_rules`, `alert_log`, `attachments`, `delegations`,
     `form_owners`, `submission_validator_data`, `lazy_cron`, `mail_log` et
     toute table ajoutée par les migrations ou requise par un repository.
3. Recenser séparément tous les index explicites et implicites : index de FK et
   de recherche, index `form_fields` (`idx_ff_form`, `idx_ff_filled_by`),
   index de `submission_validator_data` (`idx_svd_sub_field` unique et
   `idx_svds_submission`), index de `mail_log` (created_at, recipient, status,
   next_retry), index liés à l’invalidation des tokens et éventuels index
   partiels. Documenter explicitement l’absence de l’index unique des
   soumissions actives.
4. Recenser les triggers de validation (`status`, `tokens.action`,
   `form_fields.filled_by`/`visibility`, `admin_requests.status` et autres
   triggers effectivement présents), leur moment, leur message et leur liste
   de valeurs. Les triggers v43 doivent être NULL-safe même si la table est
   ensuite `NOT NULL`.
5. Recenser les contraintes/checks et les aligner sur les enums existants :
   `SubmissionStatus`, `FieldType`, `FieldVisibility`, `FilledBy`, statuts des
   demandes/admin/mail, valeurs `action`, `required` et `actif`. Ne pas
   recopier une liste divergente : dériver les SQL depuis une source unique ou
   vérifier explicitement la parité enum/DDL.
6. Recenser les seeds : administrateur issu de la configuration, formulaires
   par défaut, champs, étapes, destinataires, propriétaires, paramètres,
   règles d’alerte et réglages différés. Définir l’ordre FK-safe et le contrat
   « base vierge complète » attendu après le bootstrap.

## Phase 2 — Réécriture de la baseline initiale

1. Réécrire `classes/migrations/schema_initial.php` comme constructeur du
   schéma final v42 : DDL explicite et lisible, sans commentaires indiquant que
   des colonnes seront ajoutées par v1…v41, sans logique de détection de vieilles
   versions et sans dépendance à une table historique préexistante.
2. Intégrer directement toutes les colonnes identifiées en phase 1, notamment
   les ajouts historiquement portés par v10…v41 (`condition`, données
   validateur, RGPD, action/invalidation des tokens, revue des demandes,
   commentaire admin, compteur de rejeu mail, contraintes `required` et
   drapeaux `actif`). Préserver exactement les noms utilisés par les requêtes
   applicatives.
3. Définir dans la baseline les contraintes finales : FK et cascades, PK et
   unicités fonctionnelles, `required INTEGER NOT NULL DEFAULT 0 CHECK
   (required IN (0,1))`, `actif INTEGER NOT NULL DEFAULT 1 CHECK (actif IN
   (0,1))` ou `DEFAULT 0` selon la table et le contrat documenté, ainsi que les
   checks des autres listes fermées. Ne pas ajouter l’unicité globale des
   soumissions actives.
4. Créer tous les index non implicites dans un bloc dédié, avec noms stables et
   `IF NOT EXISTS` uniquement pour l’idempotence de construction. Recréer les
   index qui disparaissaient lors des anciennes reconstructions de tables.
5. Définir les triggers finaux qui ne sont pas fournis par v43, en s’assurant
   qu’ils ne contredisent pas les `CHECK` et qu’ils utilisent les valeurs des
   enums. Laisser à v43 la responsabilité explicite de reconstruire
   `submissions` et ses triggers de statut si cette séparation réduit le risque
   de divergence.
6. Rendre le seeding de base vierge transactionnel, déterministe et idempotent.
   Déplacer dans le chemin baseline les seeds qui étaient seulement post-v14
   lorsque leur table/structure existe déjà ; supprimer les fallbacks destinés
   aux bases pré-v9/pré-v14. Un échec de création ou de seed doit remonter et
   rollbacker, jamais produire une base partiellement initialisée.
7. Inscrire la baseline 42 dans `schema_version` uniquement après succès du
   DDL, des index/triggers, des `foreign_key_check` et des seeds obligatoires.
   Garantir qu’un second appel ne duplique ni les objets ni les lignes de seed.

## Phase 3 — Simplification de l’orchestrateur et conservation de v43

1. Simplifier `classes/DatabaseMigrations.php` : charger uniquement le schéma
   initial, les helpers nécessaires, v43 et les migrations futures à partir de
   v44 ; supprimer les `require_once`, appels et commentaires v1…v42 devenus
   obsolètes. Remplacer la boucle historique par une orchestration explicite
   baseline → v43 → versions >=44.
2. Conserver `_dbm_apply_required` ou un équivalent minimal fail-closed pour
   vérifier que v43 atteint bien 43 et que toute future migration atteint sa
   cible. Supprimer `seed_needed` et les signatures de compatibilité s’ils ne
   sont plus nécessaires après vérification de tous leurs appelants.
3. Corriger `classes/migrations/v43.php` pour qu’elle soit compatible avec la
   baseline : no-op si 43 est déjà enregistré, préflight avant DDL, nettoyage
   des tables temporaires, reconstruction sans perte de colonnes/index/FK,
   `status TEXT NOT NULL DEFAULT 'en_cours' CHECK (...)`, triggers insert/update
   NULL-safe, transaction/rollback et restauration de `foreign_keys`.
4. Vérifier que v43 ne recrée ni ne conserve par accident
   `idx_submissions_active_per_form_user`. Ajouter une assertion d’introspection
   dédiée à son absence et une régression avec deux lignes `en_cours` pour le
   même formulaire et le même agent.
5. Préparer le point d’extension v44 : nouveau fichier indépendant, enregistré
   après v43 uniquement, sans réintroduire les fichiers supprimés ni modifier
   le numéro de baseline.

## Phase 4 — Tri des fichiers et tests devenus obsolètes

1. Supprimer/archiver selon la convention du dépôt les fichiers
   `classes/migrations/v01.php` à `v41.php` (ou les variantes effectivement
   présentes), sans supprimer `schema_initial.php`, les seeds maintenus, v43 et
   les fichiers futurs. Vérifier les références textuelles et `require_once`
   dans le code, les scripts, les fixtures et la CI.
2. Trier les tests unitaires dédiés aux migrations v1…v41 : supprimer ceux qui
   testent uniquement une transition historique impossible en préproduction,
   adapter ceux qui vérifient des contrats encore valides vers la baseline ou
   v43, et conserver les tests d’orchestration utiles. Avant suppression,
   rechercher chaque symbole dans `tests/` afin de ne pas casser un usage
   indirect.
3. Créer des fixtures de base vierge utilisant un fichier temporaire obtenu par
   `sys_get_temp_dir()`. La fixture doit appeler le bootstrap de migration et
   exposer une base sans aucune donnée locale préalable ; elle ne doit jamais
   cibler `workflow.db` ou `db/workflow.db`.
4. Ajouter des tests d’introspection qui vérifient, sur cette fixture :
   - version baseline 42 puis version finale 43 ;
   - liste exacte des tables, colonnes, FK, index, triggers et contraintes
     critiques ;
   - checks `required`, `actif` et `status`, valeurs par défaut et nullabilité ;
   - présence des index nécessaires et absence de l’index unique des soumissions
     actives ;
   - présence et contenu minimal des seeds obligatoires.
5. Ajouter des tests d’idempotence : deux appels complets ne modifient pas la
   version, le nombre d’objets ni le nombre de seeds ; v43 appelée seule après
   baseline est stable ; les tables temporaires ne persistent pas après succès
   ou rollback.
6. Ajouter les tests négatifs de contrat : insert/update de `required`, `actif`
   et `status` invalides rejetés par SQL/triggers, chaque valeur de l’enum de
   statut acceptée, et deux soumissions actives identiques acceptées.

## Phase 5 — Documentation et validation CI

1. Mettre à jour `CHANGELOG.md` avec une entrée datée du 24/11/2026 décrivant
   le squash v1…v41, la baseline v42, v43 conservée/corrigée, la suppression
   de l’unicité globale des soumissions et les tests/introspection prévus.
2. Mettre à jour `TODO.md` : métriques de tests si elles changent, état de la
   nouvelle baseline, migrations supprimées, prochaine version v44 et rappel
   qu’aucune base locale n’a été migrée ou remplacée.
3. Vérifier la CI GitHub comme validation principale : workflow YAML, job
   PHPUnit migrations, PHPStan, lint, export/vendor offline et jobs qui créent
   une base vierge. Ajouter les tests de baseline au job approprié sans
   supprimer les rapports JUnit, l’upload d’artifact ni `dorny/test-reporter`.
4. La validation finale de l’orchestrateur lancera la CI et décidera de la
   livraison. Le sous-agent ne lancera aucun test, aucune gate, aucun build et
   ne touchera à aucune base existante.

## Critères d’acceptation

- Un checkout neuf crée une base complète, cohérente et idempotente sans
  exécuter v1…v42, puis atteint 43 par v43.
- Aucun fichier ou appel v1…v41 ne subsiste dans le chemin de production ; les
  tests historiques obsolètes sont retirés ou remplacés par des tests de
  baseline.
- L’introspection confirme toutes les colonnes/index/triggers/FK/checks/seeds
  de l’état final v42+v43, et les checks SQL sont alignés sur les enums PHP.
- Le contrat `required`/`actif`/`status` est imposé en base, les valeurs valides
  sont acceptées, les invalides sont rejetées, et plusieurs soumissions
  `en_cours` du même formulaire et agent restent possibles.
- Les tests d’introspection et d’idempotence passent sur des fichiers
  temporaires vierges uniquement ; aucune opération ne cible
  `workflow.db`/`db/workflow.db`.
- CHANGELOG/TODO et la CI documentent la nouvelle trajectoire ; la décision de
  validation finale reste à l’orchestrateur.
