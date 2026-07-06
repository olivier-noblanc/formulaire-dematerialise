<?php
declare(strict_types=1);

/**
 * Validator-only fields (filled_by) — Option A.
 *
 * Champs réservés aux validateurs, stockés dans submission_validator_data
 * (séparément des données demandeur dans submissions.data).
 *
 * @package lib
 */

// ═══════════════════════════════════════════════════════════════
// A-13 — VALIDATOR-ONLY FIELDS (filled_by)
// Permet de marquer certains champs comme remplissables uniquement
// par des validateurs (ex : "Avis médecin", "Décision SG").
// Ces champs sont stockés dans submission_validator_data au lieu
// de submissions.data.
// ═══════════════════════════════════════════════════════════════

/**
 * Récupère les données saisies par les validateurs pour une soumission.
 * @param string      $submission_id ID de la soumission
 * @param string|null $step_id       Si fourni, limite aux champs de cette étape
 * @return array<string, mixed> Tableau des données validateur
 */
function get_submission_validator_data(string $submission_id, ?string $step_id = null): array {
    // A-13 — Pas de cache statique : cette fonction est appelée au plus 1-2x
    // par requête (validate.php GET, submission_view.php). Le cache statique
    // précédent n'était pas invalidé par save_validator_data(), ce qui
    // pouvait retourner des données stale dans la même requête
    // (save puis re-read consécutifs). On supprime donc le cache :
    // la perf n'est pas critique ici, et la cohérence prime.
    $pdo = get_pdo();

    // Récupère toutes les données validator pour cette soumission, filtrées
    // par les champs form_fields où filled_by = 'validator'.
    //
    // Bug #2 (P1-B) : appliquer le même fix que get_form_validator_fields() —
    // validator_step peut contenir soit l'UUID du step, soit son label
    // (historique / UI admin), soit être vide (champ validator "global",
    // visible à toutes les étapes). Sans ce fix, un champ validator global
    // sauvegardé par save_validator_data() ne serait pas retourné ici.
    if ($step_id !== null && $step_id !== '') {
        // Résout le label du step : validator_step peut contenir l'UUID ou le label.
        $form_id_stmt = $pdo->prepare("SELECT form_id FROM submissions WHERE id = ?");
        $form_id_stmt->execute([$submission_id]);
        $form_id = (string)$form_id_stmt->fetchColumn();

        $step_label = '';
        if ($form_id !== '') {
            $label_stmt = $pdo->prepare("SELECT label FROM steps WHERE id = ? AND form_id = ?");
            $label_stmt->execute([$step_id, $form_id]);
            // fetchColumn() retourne false si le step n'existe pas → (string)false = ''.
            $step_label = (string)$label_stmt->fetchColumn();
        }

        // Matche :
        //  - validator_step = $step_id    (UUID — cas nominal)
        //  - validator_step = $step_label (label — historique / UI admin)
        //  - validator_step = ''          (champ validator global, toutes étapes)
        $sql = "
            SELECT svd.*
            FROM submission_validator_data svd
            WHERE svd.submission_id = ?
            AND svd.field_name IN (
                SELECT ff.field_name FROM form_fields ff
                WHERE ff.form_id = (SELECT form_id FROM submissions WHERE id = ?)
                AND ff.filled_by = 'validator'
                AND (ff.validator_step = ? OR ff.validator_step = ? OR ff.validator_step = '')
            )
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$submission_id, $submission_id, $step_id, $step_label]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Pas de filtre step : toutes les données validator de la soumission.
    $sql = "
        SELECT svd.*
        FROM submission_validator_data svd
        WHERE svd.submission_id = ?
        AND svd.field_name IN (
            SELECT ff.field_name FROM form_fields ff
            WHERE ff.form_id = (SELECT form_id FROM submissions WHERE id = ?)
            AND ff.filled_by = 'validator'
        )
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$submission_id, $submission_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Sauvegarde les données saisies par un validateur pour un champ.
 * Fait un vrai UPSERT via ON CONFLICT(submission_id, field_name) DO UPDATE
 * (SQLite 3.24+, PHP 7.2+/8+ — supporté nativement).
 *
 * Audit trail (P1-B) : la migration v14 a ajouté 4 colonnes à
 * submission_validator_data (step_id, step_label, filled_by_email, token_id)
 * + un UNIQUE(submission_id, field_name). On les persiste toutes pour
 * permettre l'audit (savoir quel validateur, sur quel step, via quel token,
 * a saisi la valeur). Le UNIQUE garantit qu'on n'a qu'une seule valeur par
 * champ par soumission : l'UPSERT met à jour la ligne existante, ou insère.
 *
 * Rétro-compatibilité : les 4 nouveaux paramètres sont optionnels. Les
 * callers existants (validate.php, test_e2e.php) qui passent 4 ou 5 args
 * continuent de fonctionner — les colonnes d'audit seront juste à NULL.
 *
 * @param string      $submission_id    ID de la soumission
 * @param string      $field_name       Nom technique du champ
 * @param string      $value            Valeur saisie
 * @param string      $filled_by        'validator'
 * @param string|null $step_id          UUID de l'étape de validation (optionnel)
 * @param string|null $step_label       Label de l'étape (dénormalisé pour audit)
 * @param string|null $filled_by_email  Email du validateur (audit)
 * @param string|null $token_id         ID du token utilisé (lien vers tokens.id)
 */
function save_validator_data(
    string $submission_id,
    string $field_name,
    string $value,
    string $filled_by,
    ?string $step_id = null,
    ?string $step_label = null,
    ?string $filled_by_email = null,
    ?string $token_id = null
): void {
    $pdo = get_pdo();

    // Récupérer label et type du champ depuis form_fields (dénormalisé dans
    // submission_validator_data pour ne pas avoir à JOIN à chaque lecture).
    $field_stmt = $pdo->prepare("SELECT label, field_type FROM form_fields WHERE field_name = ?");
    $field_stmt->execute([$field_name]);
    $field_info = $field_stmt->fetch(PDO::FETCH_ASSOC);
    $field_label = $field_info['label'] ?? $field_name;
    $field_type = $field_info['field_type'] ?? 'text';

    // Si $step_label n'est pas fourni mais $step_id l'est, on résout le label
    // du step pour enrichir l'audit (évite un JOIN côté lecture). Si le step
    // n'est pas trouvé, on laisse $step_label à NULL (pas d'audit step).
    if ($step_label === null && $step_id !== null && $step_id !== '') {
        $form_id_stmt = $pdo->prepare("SELECT form_id FROM submissions WHERE id = ?");
        $form_id_stmt->execute([$submission_id]);
        $form_id = (string)$form_id_stmt->fetchColumn();
        if ($form_id !== '') {
            $label_stmt = $pdo->prepare("SELECT label FROM steps WHERE id = ? AND form_id = ?");
            $label_stmt->execute([$step_id, $form_id]);
            // fetchColumn() retourne false si le step n'existe pas → '' → on garde NULL.
            $resolved = (string)$label_stmt->fetchColumn();
            $step_label = $resolved !== '' ? $resolved : null;
        }
    }

    // UPSERT : INSERT ... ON CONFLICT(submission_id, field_name) DO UPDATE.
    // Remplace l'ancien pattern DELETE + INSERT (non atomique, race condition
    // possible entre 2 validateurs soumettant le même champ en même temps).
    // Le UNIQUE(submission_id, field_name) ajouté en v14 est le point de
    // conflit. excluded.<col> fait référence aux valeurs qu'on tentait
    // d'insérer (la nouvelle valeur).
    $sql = "INSERT INTO submission_validator_data
            (id, submission_id, field_name, field_label, field_type, value, filled_by, filled_at, step_id, step_label, filled_by_email, token_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(submission_id, field_name) DO UPDATE SET
                value = excluded.value,
                field_label = excluded.field_label,
                field_type = excluded.field_type,
                filled_by = excluded.filled_by,
                filled_at = excluded.filled_at,
                step_id = excluded.step_id,
                step_label = excluded.step_label,
                filled_by_email = excluded.filled_by_email,
                token_id = excluded.token_id";
    $pdo->prepare($sql)->execute([
        generate_uuid(),
        $submission_id,
        $field_name,
        $field_label,
        $field_type,
        $value,
        $filled_by,
        gmdate('Y-m-d H:i:s'),
        $step_id,
        $step_label,
        $filled_by_email,
        $token_id,
    ]);
}

/**
 * Supprime la valeur d'un champ validator pour une soumission.
 * Utilisé quand un validateur soumet le champ vide (correction / reset).
 * Issue #8.
 *
 * @param string $submission_id ID de la soumission
 * @param string $field_name    Nom technique du champ
 */
function delete_validator_data(string $submission_id, string $field_name): void {
    $pdo = get_pdo();
    $pdo->prepare("DELETE FROM submission_validator_data WHERE submission_id = ? AND field_name = ?")
        ->execute([$submission_id, $field_name]);
}

/**
 * Récupère les champs d'un formulaire réservés aux validateurs.
 *
 * Bug #2 (P0-B) : `validator_step` peut contenir soit l'UUID du step,
 * soit son label (historique / UI admin qui saisit un label), soit être
 * vide (champ validator "global" — visible à toutes les étapes).
 * On résout donc le label du step passé en paramètre et on matche les 3 cas.
 *
 * @param string      $form_id  ID du formulaire
 * @param string|null $step_id  Si fourni (UUID), limite aux champs de cette étape
 *                              + champs globaux (validator_step='').
 *                              Si null, retourne TOUS les champs validator du formulaire.
 * @return array<string, mixed> Tableau des champs validateur
 */
function get_form_validator_fields(string $form_id, ?string $step_id = null): array {
    $pdo = get_pdo();
    $sql = "SELECT * FROM form_fields
            WHERE form_id = ?
              AND filled_by = 'validator'";
    $params = [$form_id];

    if ($step_id !== null && $step_id !== '') {
        // Résout le label du step : validator_step peut contenir l'UUID ou le label.
        // (La UI admin et les sample data historiques saisissent le label, pas l'UUID.)
        $label_stmt = $pdo->prepare("SELECT label FROM steps WHERE id = ? AND form_id = ?");
        $label_stmt->execute([$step_id, $form_id]);
        // fetchColumn() retourne false si le step n'existe pas → (string)false = ''.
        $step_label = (string)$label_stmt->fetchColumn();

        // Matche :
        //  - validator_step = $step_id    (UUID — cas nominal)
        //  - validator_step = $step_label (label — historique / UI admin)
        //  - validator_step = ''          (champ validator global, toutes étapes)
        // Si $step_label est vide (step introuvable), la 2e condition dégénère en
        // `validator_step = ''` (doublon avec la 3e), ce qui est inoffensif :
        // on matche toujours les champs globaux + l'UUID passé.
        $sql .= " AND (validator_step = ? OR validator_step = ? OR validator_step = '')";
        $params[] = $step_id;
        $params[] = $step_label;
    }

    $sql .= " ORDER BY ordre, id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Modifie get_form_fields() — filtre optionnel par filled_by.
 * Par défaut retourne TOUT (backwards compat).
 *
 * @param string      $form_id   ID du formulaire
 * @param string|null $filled_by Si 'demandeur', retourne uniquement les champs demandeur
 *                               Si null, retourne tous les champs
 * @return array<string, mixed>
 */
function get_form_fields(string $form_id, ?string $filled_by = null): array {
    // A-11 : cache par requête pour éviter les requêtes SQL répétées
    static $cache = [];
    // Clé de cache incluant le filtre filled_by
    $cache_key = $form_id . ($filled_by !== null ? ':' . $filled_by : '');
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }
    $pdo = get_pdo();
    $sql = "SELECT * FROM form_fields WHERE form_id = ?";
    $params = [$form_id];
    if ($filled_by !== null) {
        $sql .= " AND filled_by = ?";
        $params[] = $filled_by;
    }
    $sql .= " ORDER BY ordre, id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $cache[$cache_key] = $result;
    return $result;
}

// ═══════════════════════════════════════════════════════════════
// BACKLOG — Indicateur "Reste à traiter" pour le dashboard.
// Pour chaque soumission, détermine si des champs validator ne sont
// pas encore remplis. Optimisé en batch (2 requêtes SQL pour N
// soumissions) pour éviter le N+1.
// ═══════════════════════════════════════════════════════════════

/**
 * Calcule l'état de complétion des champs validator pour un ensemble
 * de soumissions (batch — 2 requêtes SQL pour N soumissions).
 *
 * Retourne un tableau indexé par submission_id :
 *   [
 *     'sub_uuid_1' => ['total' => 3, 'filled' => 2, 'complet' => false],
 *     'sub_uuid_2' => ['total' => 0, 'filled' => 0, 'complet' => true],
 *     ...
 *   ]
 *
 * - total = nombre de champs validator du formulaire (toutes étapes
 *   confondues).
 * - filled = nombre de ces champs réellement remplis (valeur non vide)
 *   pour cette soumission.
 * - complet = true si total == 0 ou filled >= total.
 *
 * Utilisé par dashboard.php pour l'indicateur « Reste à traiter ».
 *
 * @param PDO                        $pdo         Connexion PDO
 * @param array<int, array<string, mixed>> $submissions Lignes submissions (doivent
 *                                               contenir 'id' et 'form_id')
 * @return array<string, array{total: int, filled: int, complet: bool}>
 */
function get_validator_status_batch(PDO $pdo, array $submissions): array {
    if (empty($submissions)) {
        return [];
    }

    // Index form_id par submission_id (on ignore les lignes incomplètes).
    $form_id_by_sub = [];
    $sub_ids_index = [];
    foreach ($submissions as $sub) {
        $sub_id  = (string)($sub['id'] ?? '');
        $form_id = (string)($sub['form_id'] ?? '');
        if ($sub_id === '' || $form_id === '') {
            continue;
        }
        $form_id_by_sub[$sub_id] = $form_id;
        $sub_ids_index[$sub_id] = true;
    }

    if (empty($sub_ids_index)) {
        return [];
    }

    // 1. Récupérer les champs validator pour tous les formulaires concernés
    //    (1 seule requête quel que soit le nombre de formulaires).
    $form_ids = array_values(array_unique(array_values($form_id_by_sub)));
    $form_placeholders = implode(',', array_fill(0, count($form_ids), '?'));
    $stmt_fields = $pdo->prepare(
        "SELECT form_id, field_name FROM form_fields
         WHERE filled_by = 'validator' AND form_id IN ($form_placeholders)"
    );
    $stmt_fields->execute($form_ids);
    $validator_fields_by_form = []; // form_id => [field_name, ...]
    foreach ($stmt_fields->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $fid = (string)($r['form_id'] ?? '');
        $fn  = (string)($r['field_name'] ?? '');
        if ($fid !== '' && $fn !== '') {
            $validator_fields_by_form[$fid][] = $fn;
        }
    }

    // 2. Récupérer les données validator déjà remplies pour ces soumissions
    //    (1 seule requête quel que soit le nombre de soumissions).
    $sub_id_list = array_keys($sub_ids_index);
    $sub_placeholders = implode(',', array_fill(0, count($sub_id_list), '?'));
    $stmt_data = $pdo->prepare(
        "SELECT submission_id, field_name FROM submission_validator_data
         WHERE submission_id IN ($sub_placeholders)
         AND value IS NOT NULL AND value != ''"
    );
    $stmt_data->execute($sub_id_list);
    $filled_by_sub = []; // submission_id => [field_name, ...]
    foreach ($stmt_data->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sid = (string)($r['submission_id'] ?? '');
        $fn  = (string)($r['field_name'] ?? '');
        if ($sid !== '' && $fn !== '') {
            $filled_by_sub[$sid][] = $fn;
        }
    }

    // 3. Combiner : pour chaque soumission, compter les champs attendus
    //    vs remplis (intersection stricte pour éviter les faux positifs
    //    si une valeur existe pour un champ non validator du formulaire).
    $result = [];
    foreach ($form_id_by_sub as $sub_id => $form_id) {
        $expected = $validator_fields_by_form[$form_id] ?? [];
        $filled   = $filled_by_sub[$sub_id] ?? [];
        $total        = count($expected);
        $filled_count = count(array_intersect($expected, $filled));
        $result[$sub_id] = [
            'total'   => $total,
            'filled'  => $filled_count,
            'complet' => ($total === 0) ? true : ($filled_count >= $total),
        ];
    }

    return $result;
}
