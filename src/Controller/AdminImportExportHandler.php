<?php

declare(strict_types=1);

namespace App\Controller;

use App\Core\App;
use App\Enum\FieldType;
use App\Enum\FieldVisibility;
use App\Enum\FilledBy;
use App\Repository\FormRepository;

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
        if (!$form_data) {
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
            ],
            'fields' => [],
            'steps' => [],
        ];

        foreach ($repo->getFields($export_id) as $f) {
            $export['fields'][] = [
                'label' => $f['label'],
                'field_type' => $f['field_type'],
                'field_name' => $f['field_name'],
                'options' => $f['options'] ? json_decode($f['options'], true) : null,
                'required' => (int) $f['required'],
                'ordre' => (int) $f['ordre'],
                'card_group' => $f['card_group'] ?? 'Général',
                'hint' => $f['hint'] ?? '',
                'filled_by' => $f['filled_by'] ?? FilledBy::Demandeur->value,
                'validator_step' => $f['validator_step'] ?? '',
                'visibility' => $f['visibility'] ?? 'all',
            ];
        }

        foreach ($repo->getStepsWithRecipients($export_id) as $s) {
            $recipients = $s['recipient_emails'] ? explode('|', $s['recipient_emails']) : [];
            $raw_condition = (string) ($s['condition'] ?? '');
            $condition_export = null;
            if ($raw_condition !== '') {
                $decoded = json_decode($raw_condition, true);
                if (is_array($decoded)) {
                    $condition_export = $decoded;
                }
            }
            $export['steps'][] = [
                'label' => $s['label'],
                'ordre' => (int) $s['ordre'],
                'actif' => (int) $s['actif'],
                'recipients' => $recipients,
                'condition' => $condition_export,
            ];
        }

        $filename = preg_replace('/[^a-z0-9_-]/i', '_', $form_data['slug']) . '.json';
        $jsonOutput = json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
        $data = json_decode($json_input, true);
        // B-01-5 fix (audit 2026-07-26) : json_decode('null') retourne null légitimement.
        // Avant, on traitait ça comme un JSON invalide. On vérifie json_last_error()
        // pour distinguer 'null' valide d'un vrai JSON cassé.
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return [
                'validation_html' => '<div class="msg-error" role="alert" aria-live="assertive">JSON invalide : ' . \App\Core\App::html()->escape(json_last_error_msg()) . '. Vérifiez la syntaxe (virgules manquantes, guillemets non fermés, etc.).</div>',
                'preserved_json' => $json_input,
            ];
        }
        $result = \App\Forms\FormJsonValidator::validate($data);
        if ($result['valid'] && empty($result['warnings'])) {
            $validation_html = '<div class="msg-success" role="status" aria-live="polite">✓ JSON valide ! Le formulaire et le circuit de validation sont correctement définis. Vous pouvez lancer l\'import.</div>';
        } elseif ($result['valid']) {
            $validation_html = '<div class="msg-success" role="status" aria-live="polite">✓ JSON valide (l\'import fonctionnera), mais avec des avertissements :</div>';
            $validation_html .= \App\Forms\FormJsonValidator::formatResults($result);
        } else {
            $validation_html = '<div class="msg-error" role="alert" aria-live="assertive" class="u-mb-025">✗ JSON invalide — l\'import échouerait. Corrigez les erreurs ci-dessous :</div>';
            $validation_html .= \App\Forms\FormJsonValidator::formatResults($result);
        }
        return ['validation_html' => $validation_html, 'preserved_json' => $json_input];
    }

    /**
     * @return array{error?: string, validation_html?: string, preserved_json?: string, redirect?: string}
     */
    public static function handleImportForm(): array
    {
        $json_input = trim($_POST['json_data'] ?? '');
        if ($json_input === '' || $json_input === '0') {
            return ['error' => 'Aucune donnée JSON fournie pour l\'import.'];
        }
        $data = json_decode($json_input, true);
        // B-01-5 fix : voir commentaire dans handleValidateForm
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'JSON invalide : ' . json_last_error_msg()];
        }
        $validation = \App\Forms\FormJsonValidator::validate($data);
        if (!$validation['valid']) {
            return [
                'error' => 'Le JSON contient des erreurs de structure. L\'import a été bloqué. Corrigez les erreurs puis réessayez.',
                'validation_html' => \App\Forms\FormJsonValidator::formatResults($validation),
                'preserved_json' => $json_input,
            ];
        }

        $repo = App::getInstance()->get(FormRepository::class);
        try {
            $repo->pdo()->beginTransaction();

            $label = $data['form']['label'];
            $slug = \generate_slug($label);
            $desc = $data['form']['description'] ?? '';
            $deadline = $data['form']['deadline_field'] ?? '';

            $new_id = $repo->create([
                'label' => $label,
                'slug' => $slug,
                'description' => $desc,
                'deadline_field' => $deadline,
            ]);

            if (!empty($data['fields'])) {
                $ordre = 1;
                foreach ($data['fields'] as $f) {
                    $options_json = null;
                    if (!empty($f['options'])) {
                        $options_json = is_string($f['options']) ? $f['options'] : json_encode($f['options'], JSON_UNESCAPED_UNICODE);
                    }
                    $field_name = empty($f['field_name']) ? \generate_field_name($f['label']) : $f['field_name'];
                    $filled_by = empty($f['filled_by']) ? FilledBy::Demandeur->value : $f['filled_by'];
                    if (!in_array($filled_by, [FilledBy::Demandeur->value, FilledBy::Validator->value], true)) {
                        $filled_by = FilledBy::Demandeur->value;
                    }
                    $visibility = $f['visibility'] ?? 'all';
                    if (!is_string($visibility) || !in_array($visibility, ['all', FieldVisibility::OwnerOnly->value], true)) {
                        $visibility = 'all';
                    }
                    $raw_hint = trim((string) ($f['hint'] ?? ''));
                    if (preg_match('/^\d+$/', $raw_hint) === 1) {
                        $raw_hint = '';
                    }
                    $repo->createField([
                        'form_id' => $new_id,
                        'label' => $f['label'] ?? 'Champ',
                        'field_type' => $f['field_type'] ?? FieldType::Text->value,
                        'field_name' => $field_name,
                        'options' => $options_json,
                        'required' => (int) ($f['required'] ?? 0),
                        'ordre' => (int) ($f['ordre'] ?? $ordre),
                        'card_group' => $f['card_group'] ?? 'Général',
                        'hint' => $raw_hint,
                        'filled_by' => $filled_by,
                        'validator_step' => $f['validator_step'] ?? '',
                        'visibility' => $visibility,
                    ]);
                    $ordre++;
                }
            }

            if (!empty($data['steps'])) {
                foreach ($data['steps'] as $s) {
                    $raw_cond_import = $s['condition'] ?? '';
                    $cond_db = '';
                    if (is_array($raw_cond_import)) {
                        $op_imp = (string) ($raw_cond_import['op'] ?? '');
                        $valid_ops = \App\Workflow\ConditionEvaluator::VALID_OPS;
                        if (!empty($raw_cond_import['field']) && in_array($op_imp, $valid_ops, true)) {
                            $encoded = json_encode([
                                'field' => (string) $raw_cond_import['field'],
                                'op'    => $op_imp,
                                'value' => (string) ($raw_cond_import['value'] ?? ''),
                            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                            if ($encoded !== false) {
                                $cond_db = $encoded;
                            }
                        }
                    } elseif (is_string($raw_cond_import) && $raw_cond_import !== '') {
                        $decoded = json_decode($raw_cond_import, true);
                        if (is_array($decoded)) {
                            $cond_db = $raw_cond_import;
                        }
                    }

                    $step_id = $repo->createStep([
                        'form_id' => $new_id,
                        'label' => $s['label'] ?? 'Étape',
                        'ordre' => (int) ($s['ordre'] ?? 1),
                        'actif' => (int) ($s['actif'] ?? 1),
                        'condition' => $cond_db,
                    ]);

                    if (!empty($s['recipients'])) {
                        foreach ($s['recipients'] as $email) {
                            $repo->createRecipient($step_id, $email);
                        }
                    }
                }
            }

            $repo->pdo()->commit();
            App::audit()->log('form_import', 'form:' . $new_id, "Formulaire '$label' importé depuis JSON");
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($new_id)];
        } catch (\PDOException $e) {
            if ($repo->pdo()->inTransaction()) {
                $repo->pdo()->rollBack();
            }
            error_log('handleImportForm error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }
}
