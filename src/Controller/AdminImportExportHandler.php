<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Handlers pour l'export et l'import de formulaires en JSON.
 */
final class AdminImportExportHandler
{
    public static function handleExportForm(\PDO $pdo): array
    {
        $export_id = trim($_POST['form_id'] ?? '');
        if (empty($export_id)) {
            return ['error' => 'Aucun formulaire sélectionné pour l\'export.'];
        }
        $stmt = $pdo->prepare("SELECT * FROM forms WHERE id = ?");
        $stmt->execute([$export_id]);
        $form_data = $stmt->fetch(\PDO::FETCH_ASSOC);
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
                'actif' => (int)$form_data['actif'],
                'deadline_field' => $form_data['deadline_field'] ?? '',
            ],
            'fields' => [],
            'steps' => [],
        ];

        $f_stmt = $pdo->prepare("SELECT * FROM form_fields WHERE form_id = ? ORDER BY ordre");
        $f_stmt->execute([$export_id]);
        foreach ($f_stmt->fetchAll(\PDO::FETCH_ASSOC) as $f) {
            $export['fields'][] = [
                'label' => $f['label'],
                'field_type' => $f['field_type'],
                'field_name' => $f['field_name'],
                'options' => $f['options'] ? json_decode($f['options'], true) : null,
                'required' => (int)$f['required'],
                'ordre' => (int)$f['ordre'],
                'card_group' => $f['card_group'] ?? 'Général',
                'hint' => $f['hint'] ?? '',
                'filled_by' => $f['filled_by'] ?? 'demandeur',
                'validator_step' => $f['validator_step'] ?? '',
                'visibility' => $f['visibility'] ?? 'all',
            ];
        }

        $s_stmt = $pdo->prepare("
            SELECT s.*, GROUP_CONCAT(sr.email, '|') as recipient_emails
            FROM steps s
            LEFT JOIN step_recipients sr ON sr.step_id = s.id
            WHERE s.form_id = ?
            GROUP BY s.id
            ORDER BY s.ordre
        ");
        $s_stmt->execute([$export_id]);
        foreach ($s_stmt->fetchAll(\PDO::FETCH_ASSOC) as $s) {
            $recipients = $s['recipient_emails'] ? explode('|', $s['recipient_emails']) : [];
            $raw_condition = (string)($s['condition'] ?? '');
            $condition_export = null;
            if ($raw_condition !== '') {
                $decoded = json_decode($raw_condition, true);
                if (is_array($decoded)) {
                    $condition_export = $decoded;
                }
            }
            $export['steps'][] = [
                'label' => $s['label'],
                'ordre' => (int)$s['ordre'],
                'actif' => (int)$s['actif'],
                'recipients' => $recipients,
                'condition' => $condition_export,
            ];
        }

        $filename = preg_replace('/[^a-z0-9_-]/i', '_', $form_data['slug']) . '.json';
        return [
            'filename' => $filename,
            'json_output' => json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    public static function handleValidateJson(): array
    {
        $json_input = trim($_POST['json_data'] ?? '');
        if (empty($json_input)) {
            return [
                'validation_html' => '<div class="msg-error" role="alert" aria-live="assertive">Aucune donnée JSON fournie pour la validation.</div>',
                'preserved_json' => $json_input,
            ];
        }
        $data = json_decode($json_input, true);
        if ($data === null) {
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
            $validation_html = '<div class="msg-error" role="alert" aria-live="assertive" style="margin-bottom:.25rem;">✗ JSON invalide — l\'import échouerait. Corrigez les erreurs ci-dessous :</div>';
            $validation_html .= \App\Forms\FormJsonValidator::formatResults($result);
        }
        return ['validation_html' => $validation_html, 'preserved_json' => $json_input];
    }

    public static function handleImportForm(\PDO $pdo): array
    {
        $json_input = trim($_POST['json_data'] ?? '');
        if (empty($json_input)) {
            return ['error' => 'Aucune donnée JSON fournie pour l\'import.'];
        }
        $data = json_decode($json_input, true);
        if ($data === null) {
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
        try {
            $pdo->beginTransaction();

            $label = $data['form']['label'];
            $slug = \generate_slug($label);
            $desc = $data['form']['description'] ?? '';
            $deadline = $data['form']['deadline_field'] ?? '';

            $new_id = \generate_uuid();
            $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at, deadline_field) VALUES (?, ?, ?, ?, 1, datetime('now'), ?)")
                ->execute([$new_id, $slug, $label, $desc, $deadline]);

            if (!empty($data['fields'])) {
                $field_stmt = $pdo->prepare("INSERT INTO form_fields (id, form_id, label, field_type, field_name, options, required, ordre, card_group, hint, filled_by, validator_step, visibility) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ordre = 1;
                foreach ($data['fields'] as $f) {
                    $options_json = null;
                    if (!empty($f['options'])) {
                        $options_json = is_string($f['options']) ? $f['options'] : json_encode($f['options'], JSON_UNESCAPED_UNICODE);
                    }
                    $field_name = !empty($f['field_name']) ? $f['field_name'] : \generate_field_name($f['label']);
                    $filled_by = !empty($f['filled_by']) ? $f['filled_by'] : 'demandeur';
                    if (!in_array($filled_by, ['demandeur', 'validator'])) {
                        $filled_by = 'demandeur';
                    }
                    $visibility = $f['visibility'] ?? 'all';
                    if (!is_string($visibility) || !in_array($visibility, ['all', 'owner_only'], true)) {
                        $visibility = 'all';
                    }
                    $field_stmt->execute([
                        \generate_uuid(), $new_id,
                        $f['label'] ?? 'Champ',
                        $f['field_type'] ?? 'text',
                        $field_name,
                        $options_json,
                        (int)($f['required'] ?? 0),
                        (int)($f['ordre'] ?? $ordre),
                        $f['card_group'] ?? 'Général',
                        $f['hint'] ?? '',
                        $filled_by,
                        $f['validator_step'] ?? '',
                        $visibility,
                    ]);
                    $ordre++;
                }
            }

            if (!empty($data['steps'])) {
                foreach ($data['steps'] as $s) {
                    $step_id = \generate_uuid();
                    $raw_cond_import = $s['condition'] ?? '';
                    $cond_db = '';
                    if (is_array($raw_cond_import)) {
                        $op_imp = (string)($raw_cond_import['op'] ?? '');
                        $valid_ops = ['equals', 'not_equals', 'contains', 'not_empty', 'empty'];
                        if (!empty($raw_cond_import['field']) && in_array($op_imp, $valid_ops, true)) {
                            $encoded = json_encode([
                                'field' => (string)$raw_cond_import['field'],
                                'op'    => $op_imp,
                                'value' => (string)($raw_cond_import['value'] ?? ''),
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

                    $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif, `condition`) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$step_id, $new_id, $s['label'] ?? 'Étape', (int)($s['ordre'] ?? 1), (int)($s['actif'] ?? 1), $cond_db]);

                    if (!empty($s['recipients'])) {
                        $recip_stmt = $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)");
                        foreach ($s['recipients'] as $email) {
                            $recip_stmt->execute([\generate_uuid(), $step_id, $email]);
                        }
                    }
                }
            }

            $pdo->commit();
            App::audit()->log('form_import', 'form:' . $new_id, "Formulaire '$label' importé depuis JSON");
            return ['redirect' => 'index.php?p=admin_forms&form_id=' . urlencode($new_id)];
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('handleImportForm error: ' . $e->getMessage());
            return ['error' => 'Une erreur technique est survenue.'];
        }
    }
}
