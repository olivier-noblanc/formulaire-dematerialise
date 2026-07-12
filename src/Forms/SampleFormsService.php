<?php

declare(strict_types=1);

namespace App\Forms;

use App\Core\Database;

/**
 * Service de peuplement des formulaires d'exemple (onboarding, outboarding, etc.).
 */
final readonly class SampleFormsService
{
    public function __construct(private Database $database)
    {
    }

    /**
     * Crée les formulaires d'exemple dans la base de données.
     * Transactionnel : si une erreur survient, toutes les insertions sont annulées.
     * Les formulaires dont le slug existe déjà sont ignorés.
     *
     * @return string Message décrivant le résultat.
     */
    public function populate(): string
    {
        $pdo = $this->database->getPdo();

        try {
            $pdo->beginTransaction();

            $sample_forms = [
                [
                    'slug' => 'onboarding',
                    'label' => 'Accueil agent',
                    'description' => "Formulaire d'accueil d'un nouvel agent — prise de poste, création des accès et formalités d'entrée",
                    'fields' => [
                        ['label' => 'Nom complet', 'field_type' => 'text', 'field_name' => 'nom_complet', 'required' => 1, 'card_group' => 'Identité', 'hint' => 'Nom Prénom'],
                        ['label' => "Date d'arrivée", 'field_type' => 'date', 'field_name' => 'date_arrivee', 'required' => 1, 'card_group' => 'Identité'],
                        ['label' => "Type d'arrivée", 'field_type' => 'select', 'field_name' => 'type_arrivee', 'options' => ['Nouveau recruté', 'Mutation entrante', 'Contrat temporaire', 'Stage'], 'required' => 1, 'card_group' => 'Identité'],
                        ['label' => 'Direction / Service', 'field_type' => 'text', 'field_name' => 'direction_service', 'required' => 1, 'card_group' => 'Affectation'],
                        ['label' => 'Bureau / Poste', 'field_type' => 'text', 'field_name' => 'bureau_poste', 'required' => 0, 'card_group' => 'Affectation'],
                        ['label' => 'Création compte SI', 'field_type' => 'checkbox', 'field_name' => 'creation_compte_si', 'required' => 0, 'card_group' => 'IT'],
                        ['label' => 'Création messagerie', 'field_type' => 'checkbox', 'field_name' => 'creation_messagerie', 'required' => 0, 'card_group' => 'IT'],
                        ['label' => 'Matériel informatique', 'field_type' => 'select', 'field_name' => 'materiel_info', 'options' => ['PC portable', 'PC fixe', 'Tablette', 'Aucun'], 'required' => 0, 'card_group' => 'IT'],
                        ['label' => 'Badge accès', 'field_type' => 'checkbox', 'field_name' => 'badge_acces', 'required' => 0, 'card_group' => 'Logistique'],
                        ['label' => 'Remarques', 'field_type' => 'textarea', 'field_name' => 'remarques', 'required' => 0, 'card_group' => 'Divers'],
                        ['label' => 'Décision de validation', 'field_type' => 'select', 'field_name' => 'decision_validation', 'options' => ['Accepté', 'Accepté avec réserves', 'Refusé'], 'required' => 1, 'card_group' => 'Décision', 'filled_by' => 'validator', 'validator_step' => 'Validation manager'],
                    ],
                    'steps' => [
                        ['label' => 'Validation manager', 'ordre' => 1, 'recipients' => ['manager@dreets.gouv.fr']],
                        ['label' => 'Validation RH', 'ordre' => 2, 'recipients' => ['rh@dreets.gouv.fr']],
                        ['label' => 'Validation DSI', 'ordre' => 3, 'recipients' => ['dsi@dreets.gouv.fr']],
                        ['label' => 'Validation Logistique', 'ordre' => 4, 'recipients' => ['logistique@dreets.gouv.fr']],
                    ],
                ],
                [
                    'slug' => 'outboarding',
                    'label' => 'Départ agent',
                    'description' => "Formulaire de départ d'un agent — restitution du matériel, cloture des accès et formalités de fin de contrat",
                    'fields' => [
                        ['label' => 'Nom complet', 'field_type' => 'text', 'field_name' => 'nom_complet', 'required' => 1, 'card_group' => 'Identité', 'hint' => 'Nom Prénom'],
                        ['label' => 'Date de départ', 'field_type' => 'date', 'field_name' => 'date_depart', 'required' => 1, 'card_group' => 'Identité'],
                        ['label' => 'Motif de départ', 'field_type' => 'select', 'field_name' => 'motif_depart', 'options' => ['Démission', 'Retraite', 'Mutation sortante', 'Fin de contrat', 'Licenciement'], 'required' => 1, 'card_group' => 'Identité'],
                        ['label' => 'Restitution matériel', 'field_type' => 'checkbox', 'field_name' => 'restitution_materiel', 'required' => 0, 'card_group' => 'Logistique'],
                        ['label' => 'Clôture compte SI', 'field_type' => 'checkbox', 'field_name' => 'cloture_compte_si', 'required' => 0, 'card_group' => 'IT'],
                        ['label' => 'Clôture messagerie', 'field_type' => 'checkbox', 'field_name' => 'cloture_messagerie', 'required' => 0, 'card_group' => 'IT'],
                        ['label' => 'Remarques', 'field_type' => 'textarea', 'field_name' => 'remarques', 'required' => 0, 'card_group' => 'Divers'],
                        ['label' => 'Bilan de départ', 'field_type' => 'textarea', 'field_name' => 'bilan_depart', 'required' => 0, 'card_group' => 'Décision', 'filled_by' => 'validator', 'validator_step' => 'Validation manager'],
                    ],
                    'steps' => [
                        ['label' => 'Validation manager', 'ordre' => 1, 'recipients' => ['manager@dreets.gouv.fr']],
                        ['label' => 'Validation RH', 'ordre' => 2, 'recipients' => ['rh@dreets.gouv.fr']],
                        ['label' => 'Validation DSI', 'ordre' => 3, 'recipients' => ['dsi@dreets.gouv.fr']],
                        ['label' => 'Validation Logistique', 'ordre' => 4, 'recipients' => ['logistique@dreets.gouv.fr']],
                    ],
                ],
                [
                    'slug' => 'acces_si',
                    'label' => 'Accès SI',
                    'description' => "Demande de création, modification ou suppression d'un accès au système d'information",
                    'fields' => [
                        ['label' => 'Nom complet', 'field_type' => 'text', 'field_name' => 'nom_complet', 'required' => 1, 'card_group' => 'Identité'],
                        ['label' => 'Type de demande', 'field_type' => 'select', 'field_name' => 'type_demande', 'options' => ['Création', 'Modification', 'Suppression'], 'required' => 1, 'card_group' => 'Demande'],
                        ['label' => 'Application / SI concerné', 'field_type' => 'text', 'field_name' => 'application_si', 'required' => 1, 'card_group' => 'Demande'],
                        ['label' => 'Justification', 'field_type' => 'textarea', 'field_name' => 'justification', 'required' => 0, 'card_group' => 'Demande'],
                        ['label' => 'Date souhaitée', 'field_type' => 'date', 'field_name' => 'date_souhaitee', 'required' => 0, 'card_group' => 'Demande'],
                    ],
                    'steps' => [
                        ['label' => 'Validation manager', 'ordre' => 1, 'recipients' => ['manager@dreets.gouv.fr']],
                        ['label' => 'Validation DSI', 'ordre' => 2, 'recipients' => ['dsi@dreets.gouv.fr']],
                    ],
                ],
                [
                    'slug' => 'formation',
                    'label' => 'Formation',
                    'description' => 'Demande de formation',
                    'fields' => [
                        ['label' => 'Nom complet', 'field_type' => 'text', 'field_name' => 'nom_complet', 'required' => 1, 'card_group' => 'Agent'],
                        ['label' => 'Intitulé de la formation', 'field_type' => 'text', 'field_name' => 'intitule_formation', 'required' => 1, 'card_group' => 'Formation'],
                        ['label' => 'Organisme', 'field_type' => 'text', 'field_name' => 'organisme', 'required' => 0, 'card_group' => 'Formation'],
                        ['label' => 'Date début', 'field_type' => 'date', 'field_name' => 'date_debut', 'required' => 1, 'card_group' => 'Formation'],
                        ['label' => 'Date fin', 'field_type' => 'date', 'field_name' => 'date_fin', 'required' => 0, 'card_group' => 'Formation'],
                        ['label' => 'Coût estimé (€)', 'field_type' => 'text', 'field_name' => 'cout_estime', 'required' => 0, 'card_group' => 'Formation'],
                        ['label' => 'Justification', 'field_type' => 'textarea', 'field_name' => 'justification', 'required' => 1, 'card_group' => 'Formation'],
                    ],
                    'steps' => [
                        ['label' => 'Validation manager', 'ordre' => 1, 'recipients' => ['manager@dreets.gouv.fr']],
                        ['label' => 'Validation RH', 'ordre' => 2, 'recipients' => ['rh@dreets.gouv.fr']],
                    ],
                ],
                [
                    'slug' => 'mutation',
                    'label' => 'Mutation',
                    'description' => 'Demande de mutation',
                    'fields' => [
                        ['label' => 'Nom complet', 'field_type' => 'text', 'field_name' => 'nom_complet', 'required' => 1, 'card_group' => 'Agent'],
                        ['label' => 'Direction actuelle', 'field_type' => 'text', 'field_name' => 'direction_actuelle', 'required' => 1, 'card_group' => 'Mutation'],
                        ['label' => 'Direction demandée', 'field_type' => 'text', 'field_name' => 'direction_demandee', 'required' => 1, 'card_group' => 'Mutation'],
                        ['label' => 'Motif', 'field_type' => 'textarea', 'field_name' => 'motif', 'required' => 1, 'card_group' => 'Mutation'],
                        ['label' => 'Date souhaitée', 'field_type' => 'date', 'field_name' => 'date_souhaitee', 'required' => 0, 'card_group' => 'Mutation'],
                    ],
                    'steps' => [
                        ['label' => 'Validation manager actuel', 'ordre' => 1, 'recipients' => ['manager@dreets.gouv.fr']],
                        ['label' => 'Validation manager accueil', 'ordre' => 2, 'recipients' => ['manager-accueil@dreets.gouv.fr']],
                        ['label' => 'Validation DRH', 'ordre' => 3, 'recipients' => ['drh@dreets.gouv.fr']],
                    ],
                ],
                [
                    'slug' => 'materiel_prescription',
                    'label' => 'Matériel — Prescription',
                    'description' => 'Prescription de matériel informatique ou bureautique',
                    'fields' => [
                        ['label' => 'Nom complet', 'field_type' => 'text', 'field_name' => 'nom_complet', 'required' => 1, 'card_group' => 'Agent'],
                        ['label' => 'Type de matériel', 'field_type' => 'select', 'field_name' => 'type_materiel', 'options' => ['PC portable', 'PC fixe', 'Écran', 'Imprimante', 'Clavier/Souris', 'Autre'], 'required' => 1, 'card_group' => 'Matériel'],
                        ['label' => 'Motif', 'field_type' => 'textarea', 'field_name' => 'motif', 'required' => 1, 'card_group' => 'Matériel'],
                    ],
                    'steps' => [
                        ['label' => 'Validation manager', 'ordre' => 1, 'recipients' => ['manager@dreets.gouv.fr']],
                        ['label' => 'Validation DSI', 'ordre' => 2, 'recipients' => ['dsi@dreets.gouv.fr']],
                    ],
                ],
                [
                    'slug' => 'remboursement_avance_frais',
                    'label' => 'Remboursement / Avance de frais',
                    'description' => 'Demande de remboursement ou avance de frais',
                    'fields' => [
                        ['label' => 'Nom complet', 'field_type' => 'text', 'field_name' => 'nom_complet', 'required' => 1, 'card_group' => 'Agent'],
                        ['label' => 'Type de demande', 'field_type' => 'select', 'field_name' => 'type_demande', 'options' => ['Remboursement', 'Avance'], 'required' => 1, 'card_group' => 'Finance'],
                        ['label' => 'Montant (€)', 'field_type' => 'text', 'field_name' => 'montant', 'required' => 1, 'card_group' => 'Finance'],
                        ['label' => 'Justificatif', 'field_type' => 'file', 'field_name' => 'justificatif', 'required' => 1, 'card_group' => 'Finance'],
                        ['label' => 'Motif', 'field_type' => 'textarea', 'field_name' => 'motif', 'required' => 1, 'card_group' => 'Finance'],
                    ],
                    'steps' => [
                        ['label' => 'Validation manager', 'ordre' => 1, 'recipients' => ['manager@dreets.gouv.fr']],
                        ['label' => 'Validation Comptabilité', 'ordre' => 2, 'recipients' => ['compta@dreets.gouv.fr']],
                    ],
                ],
                [
                    'slug' => 'sortie_hors_plages',
                    'label' => 'Sortie hors plages',
                    'description' => 'Autorisation de sortie hors plages horaires',
                    'fields' => [
                        ['label' => 'Nom complet', 'field_type' => 'text', 'field_name' => 'nom_complet', 'required' => 1, 'card_group' => 'Agent'],
                        ['label' => 'Date', 'field_type' => 'date', 'field_name' => 'date_sortie', 'required' => 1, 'card_group' => 'Demande'],
                        ['label' => 'Heure départ', 'field_type' => 'text', 'field_name' => 'heure_depart', 'required' => 1, 'card_group' => 'Demande', 'hint' => 'ex : 16h30'],
                        ['label' => 'Motif', 'field_type' => 'textarea', 'field_name' => 'motif', 'required' => 1, 'card_group' => 'Demande'],
                    ],
                    'steps' => [
                        ['label' => 'Validation manager', 'ordre' => 1, 'recipients' => ['manager@dreets.gouv.fr']],
                    ],
                ],
            ];

            $created = 0;
            $skipped = 0;
            foreach ($sample_forms as $sample_form) {
                $chk = $pdo->prepare('SELECT COUNT(*) FROM forms WHERE slug = ?');
                $chk->execute([$sample_form['slug']]);
                if ($chk->fetchColumn() > 0) {
                    $skipped++;
                    continue;
                }

                $form_uuid = \generate_uuid();
                $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
                    ->execute([$form_uuid, $sample_form['slug'], $sample_form['label'], $sample_form['description']]);

                if (isset($sample_form['fields']) && !in_array($sample_form['fields'], ['', '0', []], true)) {
                    $field_stmt = $pdo->prepare('INSERT INTO form_fields (id, form_id, label, field_type, field_name, options, required, ordre, card_group, hint, filled_by, validator_step) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $ordre = 1;
                    foreach ($sample_form['fields'] as $f) {
                        $options_json = null;
                        if (isset($f['options']) && $f['options'] !== []) {
                            $options_json = json_encode($f['options'], JSON_UNESCAPED_UNICODE);
                        }
                        $filled_by = empty($f['filled_by']) ? 'demandeur' : $f['filled_by'];
                        if (!in_array($filled_by, ['demandeur', 'validator'])) {
                            $filled_by = 'demandeur';
                        }
                        $field_stmt->execute([
                            \generate_uuid(), $form_uuid,
                            $f['label'], $f['field_type'] ?? '', $f['field_name'] ?? '',
                            $options_json,
                            (int) ($f['required'] ?? 0),
                            $ordre,
                            $f['card_group'] ?? 'Général',
                            $f['hint'] ?? '',
                            $filled_by,
                            $f['validator_step'] ?? '',
                        ]);
                        $ordre++;
                    }
                }

                if (isset($sample_form['steps']) && !in_array($sample_form['steps'], ['', '0', []], true)) {
                    foreach ($sample_form['steps'] as $s) {
                        $step_uuid = \generate_uuid();
                        $pdo->prepare('INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, 1)')
                            ->execute([$step_uuid, $form_uuid, $s['label'], $s['ordre'] ?? 0]);

                        if (!empty($s['recipients'])) {
                            $recip_stmt = $pdo->prepare('INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)');
                            foreach ($s['recipients'] as $email) {
                                $recip_stmt->execute([\generate_uuid(), $step_uuid, $email]);
                            }
                        }
                    }
                }

                $created++;
            }

            $pdo->commit();
            \App\Core\App::audit()->log('populate_samples', 'system', "Formulaires exemples peuplés : $created créés, $skipped ignorés (déjà existants)", '');
            return "$created formulaire(s) exemple(s) créé(s), $skipped ignoré(s) (déjà existant(s)).";
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new \RuntimeException('Erreur lors du peuplement : ' . $e->getMessage(), 0, $e);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw new \RuntimeException('Erreur lors du peuplement : ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 0, $e);
        }
    }
}
