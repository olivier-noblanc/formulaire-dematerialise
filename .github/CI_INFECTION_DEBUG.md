# Debug CI Infection — Playbook

Si le job Infection échoue dans GitHub Actions, suivre cette checklist dans l'ordre.

## 1. Exit code 143 (SIGTERM à <10%)

**Symptôme :** Infection s'arrête à 4-10% des tests initiaux avec `exit code 143`.

**Cause :** PHPUnit exécute les tests en ordre aléatoire, un test tue le process (OOM, fork bomb, dépendance cachée).

**Solution :** Dans `phpunit.xml`, ligne 7 :
```xml
<phpunit executionOrder="default" ...>
```
Changer `executionOrder="depends"` ou `random` → `executionOrder="default"`

**Pourquoi :** L'ordre séquentiel évite les tests qui tuent le runner en ordre aléatoire.

---

## 2. Erreur de schéma JSON Infection

**Symptôme :** `Invalid configuration: Unexpected property "executionOrder"`

**Cause :** `infection.json` contient une propriété `executionOrder` qui n'existe pas dans le schéma Infection.

**Solution :** Dans `infection.json`, **retirer** la ligne `"executionOrder": "..."` si elle existe.

**Pourquoi :** `executionOrder` est géré par `phpunit.xml`, pas par Infection. Infection hérite de la config PHPUnit.

---

## 3. OOM suspecté (mémoire insuffisante)

**Symptôme :** Exit 137 (SIGKILL) ou 143 après un certain pourcentage.

**Solution :** Dans `.github/workflows/ci.yml`, job `infection` :
```yaml
- name: Run Infection
  run: php vendor/bin/infection --threads=2 --no-progress --min-msi=30 --min-covered-msi=50
```
Changer `--threads=4` → `--threads=2`

**Pourquoi :** Réduit la consommation mémoire (moins de processus PHPUnit parallèles).

---

## 4. Tests skip ou échec massif

**Symptôme :** Infection refuse de démarrer car "tests must be in a passing state".

**Solution :**
1. Vérifier que `composer.lock` est à jour et commité
2. Vérifier qu'aucun test n'a de `@requires` non satisfait (PHP version, extensions)
3. Lancer PHPUnit en local : `vendor/bin/phpunit` pour identifier les échecs

---

## Fichiers concernés

| Fichier | Ligne | Rôle |
|---|---|---|
| `phpunit.xml` | ~7 | `executionOrder` pour PHPUnit/Infection |
| `infection.json` | — | Config Infection (pas de `executionOrder`) |
| `.github/workflows/ci.yml` | ~260 | Job `infection`, flags `--threads` |

---

## Commandes de diagnostic local

```bash
# Lancer Infection en local (1 thread pour debug)
php vendor/bin/infection --threads=1 --no-progress

# Lancer PHPUnit seul (vérifier que les tests passent)
vendor/bin/phpunit

# Voir les tests les plus lents (peuvent causer timeout)
vendor/bin/phpunit --debug
```
