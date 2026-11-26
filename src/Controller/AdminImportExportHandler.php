<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\App;
use App\Enum\FieldType;
use App\Enum\FieldVisibility;
use App\Enum\FilledBy;
use App\Forms\FormJsonValidator;
use App\Repository\FormRepository;
use App\Workflow\ConditionEvaluator;

/**
 * Handlers pour l'export et l'import de formulaires en JSON.
 */
final class AdminImportExportHandler
{
    /**
     * @return array{error?: string, filename?: string, json_output?: string}
     */
    public static function handleExportForm(): array
    {
        $export_id = trim($_POST['form_id'] ?? '');
        if ($export_id === '' || $export_id === '0') {
            return ['error' => 'Aucun formulaire sélectionné pour l\'export.'];
        }

        $repo = App::getInstance()->get(FormRepository::class);
        $form_data = $repo->findById($export_id);
        if (!((bool)$form_data)) {
            return ['error' => 'Formulaire introuvable.'];
        }
        $export = [
            'schema_version' => '1.0',
            'exported_at' => date('c'),
            'form' => [
                'label' => $form_data['label'],
                'slug' => $form_data['slug'],
                'description' => $form_data['description'] ?? '',
                'actif' => (int) $form_data['actif'],
                'deadline_field' => $form_data['deadline_field'] ?? '',
                // B-FIX3a (2026-09-01) : config de relance per-form — sans elle
                // l'export perdait la configuration (défauts 48 h / 3 à la relecture)
                'relance_delai_h' => $form_data['relance_delai_h'] === null ? null : (int) $form_data['relance_delai_h'],
                'relance_max' => $form_data['relance_max'] === null ? null : (int) $form_data['relance_max'],
            ],
            'fields' => [],
            'steps' => [],
        ];

        $step_rows = $repo->getStepsWithRecipients($export_id);
        // Round-trip cohérent (audit 2026-11-26) : l'UI stocke
        // `form_fields.validator_step` sous forme d'ID d'étape, alors que
        // l'import ne connaît que les labels (les IDs sont régénérés). L'export
        // convertit donc les IDs en labels ; un value déjà en label (ou
        // orpheline) est conservé tel quel.
        /** @var array<string, string> $step_labels_by_id */
        $step_labels_by_id = [];
        foreach ($step_rows as $s) {
            $step_labels_by_id[(string) $s['id']] = (string) $s['label'];
        }

        foreach ($repo->getFields($export_id) as $f) {
            $validator_step_raw = (string) ($f['validator_step'] ?? '');
            // Ne jamais perdre une valeur legacy illisible : si les options ne
            // décodent pas en JSON, la chaîne brute est exportée (le validateur
            // d'import la signalera pour un select au lieu de la droper).
            $options_export = null;
            if ($f['options'] !== null && $f['options'] !== '') {
                $decoded_options = json_decode($f['options'], true);
                $options_export = is_array($decoded_options) ? $decoded_options : $f['options'];
            }
            $export['fields'][] = [
                'label' => $f['label'],
                'field_type' => $f['field_type'],
                'field_name' => $f['field_name'],
                'options' => $options_export,
                // Contrat de schéma stable : "required" est un drapeau 0/1.
                // On normalise toute valeur legacy/non booléenne (ex. numéro
                // d'ordre écrit dans cette colonne) en 1 (obligatoire) sans
                // jamais exporter de valeur hors {0,1}.
                'required' => (((int) $f['required']) !== 0) ? 1 : 0,
                'ordre' => (int) $f['ordre'],
                'card_group' => $f['card_group'] ?? 'Général',
                'hint' => $f['hint'] ?? '',
                'filled_by' => $f['filled_by'] ?? FilledBy::Demandeur->value,
                'validator_step' => $step_labels_by_id[$validator_step_raw] ?? $validator_step_raw,
                'visibility' => $f['visibility'] ?? FieldVisibility::All->value,
            ];
        }

        foreach ($step_rows as $s) {
            $recipients = ($s['recipient_emails'] !== null && $s['recipient_emails'] !== '') ? explode('|', $s['recipient_emails']) : [];
            $raw_condition = (string) ($s['condition'] ?? '');
            $condition_export = null;
            if ($raw_condition !== '' && $raw_condition !== '0') {
                $decoded = json_decode($raw_condition, true);
                // Condition legacy illisible : exportée telle quelle (chaîne)
                // afin que l'import la refuse explicitement, au lieu de la
                // supprimer en silence.
                $condition_export = is_array($decoded) ? $decoded : $raw_condition;
            }
            $export['steps'][] = [
                'label' => $s['label'],
                'ordre' => (int) $s['ordre'],
                'actif' => (int) $s['actif'],
                'recipients' => $recipients,
                'condition' => $condition_export,
            ];
        }

        // Nom de fichier : base dérivée du slug (jeu de caractères [a-z0-9_-]).
        // On garantit (1) une base non vide — un slug absent/exotique produisait
        // un fichier ".json" sans nom exploitable — et (2) l'extension ".json"
        // systématiquement présente dans le Content-Disposition.
        $slug_base = preg_replace('/[^a-z0-9_-]/i', '_', (string) ($form_data['slug'] ?? ''));
        if ($slug_base === null || $slug_base === '') {
            $slug_base = 'form_export';
        }
        $filename = $slug_base . '.json';
        $jsonOutput = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        return [
            'filename' => $filename,
            'json_output' => $jsonOutput !== false ? $jsonOutput : '',
        ];
    }

    /**
     * @return array{validation_html: string, preserved_json: string}
     */
    public static function handleValidateJson(): array
    {
        $json_input = trim($_POST['json_data'] ?? '');
        if ($json_input === '' || $json_input === '0') {
            return [
                'validation_html' => '<div class="msg-error" role="alert" aria-live="assertive">Aucune donnée JSON fournie pour la validation.</div>',
                'preserved_json' => $json_input,
            ];
        }
        $decoded = self::decodeJsonObject($json_input);
        if ($decoded['ok'] === false) {
            return [
                'validation_html' => '<div class="msg-error" role="alert" aria-live="assertive">' . \App\Core\App::html()->escape($decoded['error']) . '</div>',
                'preserved_json' => $json_input,
            ];
        }
        $result = FormJsonValidator::validate($decoded['data']);
        if ($result['valid'] && $result['warnings'] === []) {
            $validation_html = '<div class="msg-success" role="status" aria-live="polite">✓ JSON valide ! Le formulaire et le circuit de validation sont correctement définis. Vous pouvez lancer l\'import.</div>';
        } elseif ($result['valid']) {
            $validation_html = '<div class="msg-success" role="status" aria-live="polite">✓ JSON valide (l\'import fonctionnera), mais avec des avertissements :</div>';
            $validation_html .= FormJsonValidator::formatResults($result);
        } else {
            $validation_html = '<div class="msg-error u-mb-025" role="alert" aria-live="assertive">✗ JSON invalide — l\'import échouerait. Corrigez les erreurs ci-dessous :</div>';
            $validation_html .= FormJsonValidator::formatResults($result);
        }
        return ['validation_html' => $validation_html, 'preserved_json' => $json_input];
    }

    /**
     * @return array{
     *   error?: string,
     *   validation_html?: string,
     *   preserved_json?: string,
     *   redirect?: string,
     *   form_id?: string,
     *   success?: string,
     *   warnings?: list<string>
     * }
     */
    public static function handleImportForm(): array
    {
        $json_input = trim($_POST['json_data'] ?? '');
        if ($json_input === '' || $json_input === '0') {
            return ['error' => 'Aucune donnée JSON fournie pour l\'import.'];
        }
        $decoded = self::decodeJsonObject($json_input);
        if ($decoded['ok'] === false) {
            return ['error' => $decoded['error'], 'preserved_json' => $json_input];
        }
        $validation = FormJsonValidator::validate($decoded['data']);
        if (!$validation['valid']) {
            return [
                'error' => 'Le JSON contient des erreurs de structure. L\'import a été bloqué. Corrigez les erreurs puis réessayez.',
                'validation_html' => FormJsonValidator::formatResults($validation),
                'preserved_json' => $json_input,
            ];
        }
        $data = $decoded['data'];

        // R6 (audit 2026-09-15) — fail-closed : une condition fournie mais mal
        // formée (opérateur inconnu, champ absent, JSON non-objet) ne doit
        // jamais être stockée verbatim ni silencieusement transformée en étape
        // inconditionnelle. FormJsonValidator bloque déjà ces cas ; ce garde-fou
        // rend l'import sûr indépendamment de l'ordre des appels.
        $guard_steps = $data['steps'] ?? [];
        if (!is_array($guard_steps)) {
            $guard_steps = [];
        }
        foreach ($guard_steps as $s) {
            if (!is_array($s)) {
                continue;
            }
            $parsed_condition = self::parseStepCondition($s['condition'] ?? '');
            if ($parsed_condition['provided'] && !$parsed_condition['valid']) {
                return [
                    'error' => 'Le JSON contient une condition d\'étape invalide (opérateur inconnu ou structure incorrecte). L\'import a été bloqué.',
                    'preserved_json' => $json_input,
                ];
            }
        }

        $form_input = $data['form'] ?? null;
        if (!is_array($form_input)) {
            // Impossible après validation ; garde défensive pour les types.
            return ['error' => 'Le JSON ne contient pas d\'objet "form" valide.'];
        }

        $repo = App::getInstance()->get(FormRepository::class);
        try {
            $repo->beginImmediateTransaction();

            $label = is_string($form_input['label'] ?? null) ? $form_input['label'] : '';
            $slug = \generate_slug($label);
            $desc = is_string($form_input['description'] ?? null) ? $form_input['description'] : '';
            $deadline = is_string($form_input['deadline_field'] ?? null) ? strtolower(trim($form_input['deadline_field'])) : '';
            // B-FIX3b (2026-09-01) : restaurer la config de relance si présente.
            // Validation stricte en amont : null ou entier borné.
            $relance_delai_raw = $form_input['relance_delai_h'] ?? null;
            $relance_max_raw = $form_input['relance_max'] ?? null;
            $relance_delai = is_int($relance_delai_raw) ? $relance_delai_raw : null;
            $relance_max = is_int($relance_max_raw) ? $relance_max_raw : null;

            $new_id = $repo->create([
                'label' => $label,
                'slug' => $slug,
                'description' => $desc,
                'actif' => array_key_exists('actif', $form_input)
                    ? FormJsonValidator::normalizeFlag($form_input['actif'])
                    : 1,
                'deadline_field' => $deadline,
                // Validation stricte en amont : null ou entier borné. Aucune
                // coercion de chaîne/float ici (plus de is_numeric silencieux).
                'relance_delai_h' => $relance_delai,
                'relance_max' => $relance_max,
            ]);

            $fields_input = $data['fields'] ?? null;
            if (is_array($fields_input) && $fields_input !== []) {
                $ordre = 1;
                foreach ($fields_input as $f) {
                    if (!is_array($f)) {
                        continue;
                    }
                    $options_json = null;
                    if (isset($f['options']) && $f['options'] !== '') {
                        $encoded = is_string($f['options']) ? $f['options'] : json_encode($f['options'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                        $options_json = $encoded !== false ? $encoded : null;
                    }
                    $field_label = is_string($f['label'] ?? null) ? $f['label'] : 'Champ';
                    $field_name = (!isset($f['field_name']) || !is_string($f['field_name']) || $f['field_name'] === '')
                        ? \generate_field_name($field_label)
                        : $f['field_name'];
                    $visibility_input = $f['visibility'] ?? FieldVisibility::All->value;
                    $visibility = is_string($visibility_input) && in_array($visibility_input, [FieldVisibility::All->value, FieldVisibility::OwnerOnly->value], true)
                        ? $visibility_input
                        : FieldVisibility::All->value;
                    // D5 (2026-09-15) : le hint est conservé tel quel (trim seul).
                    // Il n'est plus vidé quand il est purement numérique — un
                    // chiffre est une valeur de hint légitime, préservée au
                    // round-trip export → import.
                    $raw_hint = trim((string) ($f['hint'] ?? ''));
                    $card_group = is_string($f['card_group'] ?? null) && trim($f['card_group']) !== ''
                        ? $f['card_group']
                        : 'Général';
                    $filled_by_input = $f['filled_by'] ?? null;
                    $filled_by = is_string($filled_by_input) && in_array($filled_by_input, [FilledBy::Demandeur->value, FilledBy::Validator->value], true)
                        ? $filled_by_input
                        : FilledBy::Demandeur->value;
                    $field_type = is_string($f['field_type'] ?? null) ? $f['field_type'] : FieldType::Text->value;
                    $repo->createField([
                        'form_id' => $new_id,
                        'label' => $field_label,
                        'field_type' => $field_type,
                        'field_name' => $field_name,
                        'options' => $options_json,
                        // Contrat d'import durci : "required" a été validé
                        // strictement (booléen ou 0/1) avant la transaction.
                        // normalizeFlag ne convertit donc jamais autre chose.
                        'required' => FormJsonValidator::normalizeFlag($f['required'] ?? 0),
                        'ordre' => (int) ($f['ordre'] ?? $ordre),
                        'card_group' => $card_group,
                        'hint' => $raw_hint,
                        'filled_by' => $filled_by,
                        'validator_step' => is_string($f['validator_step'] ?? null) ? $f['validator_step'] : '',
                        'visibility' => $visibility,
                    ]);
                    $ordre++;
                }
            }

            $steps_input = $data['steps'] ?? null;
            if (is_array($steps_input) && $steps_input !== []) {
                foreach ($steps_input as $s) {
                    if (!is_array($s)) {
                        continue;
                    }
                    // R6 : objet ET chaîne JSON passent par la même
                    // normalisation — plus de stockage verbatim d'une chaîne.
                    $parsed_cond = self::parseStepCondition($s['condition'] ?? '');
                    $cond_db = '';
                    if ($parsed_cond['provided'] && $parsed_cond['valid']) {
                        $cond_obj = $parsed_cond['condition'];
                        // B-FIX3c (2026-09-01) : l'op "in" peut porter une value
                        // tableau (liste de valeurs) — la préserver telle quelle,
                        // sinon le cast (string) produisait "Array" en base.
                        // La clé value n'est ajoutée que si elle existe, pour un
                        // round-trip fidèle des opérateurs sans valeur.
                        $cond_to_store = [
                            'field' => (string) $cond_obj['field'],
                            'op'    => (string) $cond_obj['op'],
                        ];
                        if (array_key_exists('value', $cond_obj)) {
                            $raw_value_imp = $cond_obj['value'];
                            $cond_to_store['value'] = is_array($raw_value_imp) ? $raw_value_imp : (string) $raw_value_imp;
                        }
                        $encoded = json_encode($cond_to_store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                        if ($encoded !== false) {
                            $cond_db = $encoded;
                        }
                    }

                    $step_id = $repo->createStep([
                        'form_id' => $new_id,
                        'label' => is_string($s['label'] ?? null) ? $s['label'] : 'Étape',
                        'ordre' => (int) ($s['ordre'] ?? 1),
                        'actif' => array_key_exists('actif', $s)
                            ? FormJsonValidator::normalizeFlag($s['actif'])
                            : 1,
                        'condition' => $cond_db,
                    ]);

                    if (isset($s['recipients']) && is_array($s['recipients'])) {
                        foreach ($s['recipients'] as $email) {
                            if (is_string($email)) {
                                $repo->createRecipient($step_id, $email);
                            }
                        }
                    }
                }
            }

            $repo->pdo()->commit();
            App::audit()->log('form_import', 'form:' . $new_id, "Formulaire '$label' importé depuis JSON");

            // P2 (audit 2026-11-26) : les avertissements de FormJsonValidator
            // n'étaient jamais transmis au call site (jetés en silence). Ils
            // sont désormais rendus visibles : si l'import est valide mais
            // comporte des avertissements, on ne redirige pas — on réaffiche la
            // page admin avec le formulaire importé sélectionné, le détail des
            // avertissements et le JSON conservé.
            if ($validation['warnings'] !== []) {
                return [
                    'form_id' => $new_id,
                    'success' => 'Formulaire importé, avec ' . count($validation['warnings']) . ' avertissement(s) à vérifier (voir le panneau d\'import).',
                    'validation_html' => FormJsonValidator::formatResults($validation),
                    'preserved_json' => $json_input,
                    'warnings' => $validation['warnings'],
                ];
            }
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($new_id)];
        } catch (\InvalidArgumentException $e) {
            // Violation prévisible d'un contrat métier (ordre, required,
            // destinataire, field_name…) : message explicite, jamais le
            // générique « erreur technique ». La transaction est fermée avant
            // de répondre.
            self::rollbackIfNeeded($repo);
            return [
                'error' => 'Import refusé : ' . $e->getMessage(),
                'preserved_json' => $json_input,
            ];
        } catch (\PDOException $e) {
            self::rollbackIfNeeded($repo);
            $message = $e->getMessage();
            // Index d'expression v48 : SQLite peut rapporter soit les colonnes,
            // soit le nom de l'index selon la version.
            if (str_contains($message, 'UNIQUE constraint failed')
                && (str_contains($message, 'field_name') || str_contains($message, 'idx_ff_form_field_name'))) {
                return [
                    'error' => 'Import refusé : deux champs du formulaire utilisent le même field_name. Le nom technique de chaque champ doit être unique (les noms générés depuis le label sont inclus).',
                    'preserved_json' => $json_input,
                ];
            }
            // Catégorie 2 : panne DB attendue → réponse structurée au call site.
            error_log('handleImportForm error: ' . $message);
            return ['error' => 'Une erreur technique est survenue.'];
        } catch (\Throwable $e) {
            // R1 (audit 2026-09-14) : toute exception survenue après
            // beginTransaction() doit fermer la transaction — pas seulement
            // PDOException. AGENTS.md règle 9, catégorie 1 : rollback puis
            // relance pour une exception non-PDO, jamais avalée (elle remonte
            // au handler global qui la journalise et affiche la page d'erreur).
            self::rollbackIfNeeded($repo);
            throw $e;
        }
    }

    private static function rollbackIfNeeded(FormRepository $repo): void
    {
        if ($repo->pdo()->inTransaction()) {
            $repo->pdo()->rollBack();
        }
    }

    /**
     * Décode le JSON d'import en objet racine, avec profondeur bornée.
     *
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, error: string}
     */
    private static function decodeJsonObject(string $json): array
    {
        try {
            $decoded = json_decode($json, true, FormJsonValidator::JSON_MAX_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [
                'ok' => false,
                'error' => 'JSON invalide : ' . $e->getMessage() . '. Vérifiez la syntaxe (virgules manquantes, guillemets non fermés, profondeur maximale ' . FormJsonValidator::JSON_MAX_DEPTH . ').',
            ];
        }
        if (!is_array($decoded)) {
            return [
                'ok' => false,
                'error' => 'Le JSON doit être un objet { ... } à la racine. Trouvé : ' . gettype($decoded) . '.',
            ];
        }
        /** @var array<string, mixed> $decoded */
        return ['ok' => true, 'data' => $decoded];
    }

    /**
     * Décode et valide une condition d'étape (objet ou chaîne JSON).
     *
     * R6 (audit 2026-09-15) — fail-closed : une condition fournie mais mal
     * formée (champ absent, opérateur inconnu, JSON non-objet) est signalée
     * invalide. Elle n'est donc jamais stockée verbatim ni silencieusement
     * transformée en étape inconditionnelle.
     *
     * @return array{provided: bool, valid: bool, condition: array<string, mixed>}
     */
    private static function parseStepCondition(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return ['provided' => false, 'valid' => true, 'condition' => []];
        }

        $cond = $raw;
        if (is_string($raw)) {
            try {
                $cond = json_decode($raw, true, FormJsonValidator::JSON_MAX_DEPTH, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return ['provided' => true, 'valid' => false, 'condition' => []];
            }
        }
        if (!is_array($cond)) {
            return ['provided' => true, 'valid' => false, 'condition' => []];
        }

        $field = $cond['field'] ?? null;
        $op = $cond['op'] ?? null;
        if (!is_string($field) || $field === '' || !is_string($op) || !in_array($op, ConditionEvaluator::VALID_OPS, true)) {
            return ['provided' => true, 'valid' => false, 'condition' => []];
        }

        return ['provided' => true, 'valid' => true, 'condition' => $cond];
    }
}
