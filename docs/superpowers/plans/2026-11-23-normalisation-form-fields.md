# Plan d’implémentation — normalisation centralisée de `form_fields.required`

> **Pour l’agent d’exécution :** suivre le cycle TDD RED → GREEN à chaque étape. Ne pas lancer la suite PHPUnit complète ni `scripts/check.ps1` localement ; la validation finale appartient à l’orchestrateur.

## Objectif et contrat

Établir une seule frontière de normalisation pour les lignes de champs récupérées depuis la base. `required` doit être fourni aux contrôleurs, handlers et renderers comme un entier strict appartenant à `{0, 1}` : `null`/valeur vide signifie `0`, et toute valeur non nulle (entier ou chaîne numérique, notamment les anciennes valeurs ordinales `2`, `21`, etc.) signifie `1`. Cette règle conserve le comportement historique « toute valeur non nulle = obligatoire » tout en supprimant les divergences de comparaison (`!== 0`, cast booléen local, cast entier local).

La normalisation sera portée par **`FieldService`**, plutôt que par le repository : le repository reste responsable de la lecture SQL et son alias `FormFieldRow` décrit la forme métier consommée ; `FieldService` est déjà la façade appelée par `ValidatorDataService::getFormFields()` et possède le cache des champs. Les lectures de champs via cette façade seront donc cohérentes pour le formulaire public, la prévisualisation et les champs validateur.

## Approches considérées

1. **Normaliser dans chaque consommateur** (renderer, preview, validation) : rejeté, car c’est la situation actuelle et elle permet aux chaînes `'1'`, aux entiers et aux valeurs legacy de diverger.
2. **Normaliser dans `FormFieldsTrait`/le repository** : possible, mais mélange la forme SQL et la règle métier, et ne couvre pas les lignes fabriquées ou transformées par la façade ; cela rendrait moins explicite la frontière de service.
3. **Créer immédiatement un DTO** : rejeté pour cette correction bornée ; le code utilise encore des array shapes et le besoin est un invariant unique sur un seul champ.
4. **Normaliser dans `FieldService`** : recommandé. Ajouter une méthode privée de normalisation de ligne (et une méthode de collection si nécessaire), l’appliquer aux résultats de `getFields()` et `getValidatorFields()`, puis faire référencer partout l’alias `FormFieldRow` existant.

## Fichiers concernés

- `src/Forms/FieldService.php` — normalisation unique des résultats de `getFields()` et `getValidatorFields()`, signatures/PHPDoc retournant `FormFieldRow`.
- `src/Repository/FormFieldsTrait.php` — conserver et, si nécessaire, ajuster l’alias `FormFieldRow` pour documenter `required: int` comme valeur normalisée ; ne pas dupliquer la shape dans les services.
- `src/Forms/ValidatorDataService.php` — importer `FormFieldRow` et remplacer sa shape locale dans `getFormFields()`.
- `src/Controller/FormPreviewController.php` — remplacer les comparaisons `required !== 0` aux lignes 80 et 84 par le contrat `required === 1` (ou une variable dérivée unique), sans recast local.
- `src/Render/FormRenderer.php` — importer/utiliser `FormFieldRow` dans le PHPDoc de `field()` (ou une shape minimale documentée seulement si PHPStan l’exige), supprimer `required: int|string` et consommer l’entier normalisé sans reproduire la normalisation.
- `src/Controller/FormValidationHandler.php` — importer `FormFieldRow`, remplacer les shapes répétées de `validateFields()`, `filterConditionallyHidden()` et `validateFiles()` ; tester `required === 1` plutôt que recalculer sa vérité par cast.
- `src/Controller/FormController.php` — documenter `$form_fields` comme `list<FormFieldRow>` et conserver le flux `ValidatorDataService::getFormFields()` comme unique source normalisée ; vérifier que le JSON de test et le renderer consomment le `0/1` sans conversion concurrente.
- `tests/PHPUnit/FieldServiceTest.php` — tests de frontière de service et valeurs DB legacy.
- `tests/PHPUnit/FormRendererTest.php` — matrice de rendu `required` 0/1/2, chaîne/int, et vérification qu’une ligne normalisée produit l’étoile et l’attribut attendus.
- `tests/PHPUnit/FormValidationHandlerTest.php` — matrice serveur `required` 0/1/2 et chaînes/int, incluant champs fichier et champ text vide.
- `tests/PHPUnit/FormPreviewControllerTest.php` (à créer seulement s’il n’existe pas de couverture équivalente) ou le test de contrôleur existant approprié — vérification du HTML de prévisualisation pour 0, 1 et une valeur legacy.
- `CHANGELOG.md` — entrée de version avec date, fix, tests ciblés.
- `TODO.md` — métriques/historique et tâche de normalisation marquée terminée.

## Étapes TDD

### 1. RED — verrouiller le contrat de normalisation

- Dans `FieldServiceTest`, préparer des lignes/champs de test avec `required` sous les formes `0`, `1`, `2`, `'0'`, `'1'`, `'2'` (et `null` si le chemin SQL/test le permet).
- Ajouter des assertions sur les résultats de `getFields()` et `getValidatorFields()` : respectivement `0` pour zéro/vide/null, `1` pour toute valeur non nulle ; toujours `is_int()` et valeur dans `{0,1}`.
- Faire échouer d’abord ces tests avec l’implémentation actuelle qui retourne directement les lignes du repository.
- Ajouter/adapter les PHPDoc afin que `FormFieldRow` soit la seule shape de ligne réutilisée ; les doublons locaux doivent disparaître sans élargir en `array<string, mixed>`.

### 2. GREEN — implémenter la frontière `FieldService`

- Ajouter une normalisation privée courte et explicite dans `FieldService`, basée sur la règle `null`/`0` → `0`, non nul → `1`, en préservant toutes les autres colonnes.
- Appliquer cette méthode aux deux chemins de lecture (`getFields()` — y compris cache — et `getValidatorFields()`). Normaliser avant mise en cache pour que le cache ne puisse pas réintroduire une forme brute.
- Éviter une double lecture ou une normalisation dans `ValidatorDataService`; celui-ci doit seulement déléguer.
- Relancer le filtre `FieldServiceTest` et vérifier les valeurs exactes et le type entier.

### 3. RED → GREEN — aligner les shapes PHPStan et le contrôleur public

- Ajouter `@phpstan-import-type FormFieldRow from FormFieldsTrait` dans `ValidatorDataService`, `FormValidationHandler` et `FormController` si nécessaire ; remplacer les répétitions de `array{...}` par `FormFieldRow`/`list<FormFieldRow>`.
- Dans `FormController`, vérifier que filtrer `filled_by` conserve une `list<FormFieldRow>` et que le JSON de test convertit uniquement à la frontière de présentation (`bool` si le contrat JSON l’exige), sans modifier la ligne utilisée pour validation/rendu.
- Passer le test PHPStan ciblé (ou analyse des fichiers touchés) après le test rouge puis la correction ; traiter toute incompatibilité de shape plutôt que d’ajouter `mixed`.

### 4. RED → GREEN — prévisualisation

- Ajouter une régression de contrôleur/HTML qui injecte ou expose des champs `required` 0, 1, `'1'` et `2`, puis vérifie que seuls les champs normalisés à 1 ont l’attribut `required` et l’étoile.
- Corriger `FormPreviewController` : utiliser une variable `$isRequired = $previewField['required'] === 1` pour l’attribut et le libellé, afin d’éviter deux conditions divergentes. Le contrôleur reçoit déjà les champs par `App::validatorData()->getFormFields()` ligne 33.
- Vérifier que la valeur legacy `2` est normalisée avant le contrôleur, et qu’aucun cast local ne redevient nécessaire.

### 5. RED → GREEN — renderer et validation serveur

- Étendre `FormRendererTest` avec la matrice types string/int et valeurs 0, 1, 2. Les assertions doivent couvrir l’étoile, `required aria-required="true"`, l’absence de ces marqueurs pour 0, et le comportement désactivé.
- Ajuster le PHPDoc de `FormRenderer::field()` (ligne 22) pour consommer le contrat `required: int`/`FormFieldRow` sans prétendre accepter une donnée brute hétérogène. Retirer le cast local de normalisation ; une comparaison stricte à 1 suffit. Si les tests unitaires appellent directement le renderer, leur fixture doit représenter une ligne déjà normalisée, tandis que le test de service couvre les chaînes DB.
- Étendre `FormValidationHandlerTest` : champ text et fichier vide avec `required=0` → aucune erreur, `1`/`'1'`/`2` → erreur ; vérifier aussi qu’une valeur postée `'0'` reste acceptée pour un champ obligatoire.
- Modifier `FormValidationHandler` pour utiliser `FormFieldRow` et le contrat `=== 1` dans `validateFields()` et `validateFiles()`. `filterConditionallyHidden()` doit conserver les lignes normalisées sans les transformer.
- Relancer uniquement `--filter FormRendererTest` et `--filter FormValidationHandlerTest` après GREEN.

### 6. Vérification transversale et documentation

- Rechercher `required` dans `src/` et `tests/` et repérer tout nouveau cast/comparaison local sur les lignes de champs (`(bool) $field['required']`, `(int) ...`, `!== 0`). Conserver uniquement les conversions de formats externes explicitement nécessaires (par exemple JSON de test/export), pas dans les consommateurs du contrat.
- Rechercher `array{id: string, form_id: string` dans les PHPDoc : aucune duplication de `FormFieldRow` ne doit subsister pour les mêmes lignes SQL.
- Vérifier que les autres appels `ValidatorDataService::getFormFields()` (`AdminFormsController`, `FormTrackingController`, `ValidateController`) acceptent déjà `required: int`; les adapter uniquement si PHPStan ou le contrat l’exige, sans élargir le périmètre.
- Mettre à jour `CHANGELOG.md` avec une entrée datée 2026-11-23 : normalisation centralisée, legacy non nul → 1, preview/renderer/validation alignés, tests ciblés et résultat.
- Mettre à jour `TODO.md` : métriques de tests/assertions si elles changent, ligne historique dédiée et mention des commandes ciblées ; ne pas prétendre avoir exécuté la suite complète.

## Commandes ciblées de vérification (à exécuter par l’agent d’implémentation)

```powershell
php vendor/bin/phpunit --filter FieldServiceTest
php vendor/bin/phpunit --filter FormRendererTest
php vendor/bin/phpunit --filter FormValidationHandlerTest
php vendor/bin/phpunit --filter FormPreview
phpstan analyse src/Forms/FieldService.php src/Forms/ValidatorDataService.php src/Repository/FormFieldsTrait.php src/Controller/FormPreviewController.php src/Render/FormRenderer.php src/Controller/FormValidationHandler.php src/Controller/FormController.php --no-progress
php -l src/Forms/FieldService.php
php -l src/Controller/FormPreviewController.php
php -l src/Render/FormRenderer.php
php -l src/Controller/FormValidationHandler.php
```

Le dernier filtre doit être ajusté au nom exact du test de prévisualisation créé ou enrichi. Ne pas lancer `php vendor/bin/phpunit` sans filtre, `tests/run_all.php` ou `scripts/check.ps1` dans ce lot ; l’orchestrateur est l’owner de la validation finale.

## Critères de succès

- Toutes les lignes retournées par `FieldService::getFields()` et `getValidatorFields()` exposent `required` comme `int` strict `0` ou `1` ; `0`, `1`, `2` et leurs variantes string sont couvertes par tests, avec la règle legacy non nul → 1.
- `FormFieldRow` est la source PHPDoc réutilisée ; aucune shape complète dupliquée ne décrit ces lignes dans les services/handlers.
- Preview, rendu public et validation serveur donnent le même résultat métier pour chaque valeur de la matrice ; les champs désactivés restent sans attribut HTML `required`.
- Les tests ciblés FieldService, preview, renderer et validation passent, le PHPStan ciblé et les lint PHP passent, et les recherches transversales ne révèlent pas de consommateur divergent.
- `CHANGELOG.md` et `TODO.md` décrivent le changement et les vérifications réellement effectuées ; aucune modification hors du présent plan n’est requise pour cette phase de planification.
