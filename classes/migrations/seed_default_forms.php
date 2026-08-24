<?php
declare(strict_types=1);

/**
 * Seed default forms — onboarding, outboarding, etc.
 *
 * Insère les formulaires de démonstration si la table forms est vide.
 * Échoue silencieusement (catch PDOException) sur une base pré-v9 (id INTEGER).
 *
 * @package Migrations
 */
/**
 * Insère les formulaires et paramètres par défaut (outboarding, onboarding,
 * sortie_hors_plages, remboursement_avance_frais, materiel_prescription,
 * mutation, formation, acces_si + settings SMTP/LDAP).
 *
 * Ne retourne rien. En cas d'erreur (base pré-v9 avec id INTEGER), la fonction
 * lève une PDOException que l'appelant (apply_schema_initial) intercepte pour
 * positionner $seed_needed = true et retenter après les migrations versionnées.
 *
 * @param PDO $pdo Connexion SQLite
 */
function seed_default_forms(PDO $pdo): void {
    $ob_count = _dbm_q($pdo, "SELECT COUNT(*) FROM forms WHERE slug = 'outboarding'")->fetchColumn();
    if ((int) $ob_count === 0) {
        $outboarding_id = generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$outboarding_id, 'outboarding', 'Départ agent', 'Formulaire de départ d\'un agent — restitution du matériel, cloture des accès et formalités de fin de contrat']);

        $outboarding_fields = [
            ['Identité de l\'agent',    'Nom',                                    'text',     'nom',                  null,                                                                                                   1, 1],
            ['Identité de l\'agent',    'Prénom',                                 'text',     'prenom',               null,                                                                                                   1, 2],
            ['Identité de l\'agent',    'Date de départ',                         'date',     'date_depart',          null,                                                                                                   1, 3],
            ['Identité de l\'agent',    'Motif de départ',                        'select',   'motif_depart',         '["Démission","Retraite","Mutation","Fin de contrat","Licenciement","Autre"]',                  1, 4],
            ['Identité de l\'agent',    'Service / Affectation',                  'text',     'affectation',          null,                                                                                                   1, 5],
            ['Informatique (IT)',       'Restitution poste informatique',         'checkbox', 'it_restitution_poste', null,                                                                                                   0, 6],
            ['Informatique (IT)',       'Restitution téléphone pro',              'checkbox', 'it_restitution_tel',   null,                                                                                                   0, 7],
            ['Informatique (IT)',       'Révocation accès RPVN',                  'checkbox', 'it_revoq_rpvn',        null,                                                                                                   0, 8],
            ['Informatique (IT)',       'Révocation accès applicatifs métier',    'checkbox', 'it_revoq_applicatifs', null,                                                                                                   0, 9],
            ['Informatique (IT)',       'Révocation compte de messagerie',        'checkbox', 'it_revoq_messagerie',  null,                                                                                                   0, 10],
            ['Informatique (IT)',       'Transfert boîte mail (destinataire)',    'text',     'it_transfert_mail',    null,                                                                                                   0, 11],
            ['Ressources Humaines',     'Solde de tout compte',                   'checkbox', 'rh_solde_compte',      null,                                                                                                   0, 12],
            ['Ressources Humaines',     'Attestation employeur',                  'checkbox', 'rh_attestation',       null,                                                                                                   0, 13],
            ['Ressources Humaines',     'Certificat de travail',                  'checkbox', 'rh_certificat',        null,                                                                                                   0, 14],
            ['Ressources Humaines',     'Récupération solde congés',              'checkbox', 'rh_conges',            null,                                                                                                   0, 15],
            ['Ressources Humaines',     'Résiliation mutuelle MGEN',             'checkbox', 'rh_mutuelle',          null,                                                                                                   0, 16],
            ['Ressources Humaines',     'Observations RH',                        'textarea', 'rh_observations',      null,                                                                                                   0, 17],
            ['Logistique',              'Restitution badge d\'accès',             'checkbox', 'log_restitution_badge',null,                                                                                                   0, 18],
            ['Logistique',              'Restitution véhicule de service',        'checkbox', 'log_restitution_vehicule',null,                                                                                               0, 19],
            ['Logistique',              'Restitution EPI',                        'checkbox', 'log_restitution_epi',  null,                                                                                                   0, 20],
            ['Logistique',              'Libération bureau / local',              'text',     'log_bureau',           null,                                                                                                   0, 21],
        ];
        $stmt_ob = $pdo->prepare("INSERT INTO form_fields (id, card_group, label, field_type, field_name, options, required, ordre, form_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($outboarding_fields as $row) {
            $stmt_ob->execute([generate_uuid(), $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $outboarding_id]);
        }

        // Etapes par defaut pour l'outboarding
        $ob_step1_id = generate_uuid();
        $ob_step2_id = generate_uuid();
        $ob_step3_id = generate_uuid();
        $ob_step4_id = generate_uuid();
        $stmt_step = $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, 1)");
        $stmt_step->execute([$ob_step1_id, $outboarding_id, 'Responsable direct', 1]);
        $stmt_step->execute([$ob_step2_id, $outboarding_id, 'Service informatique', 2]);
        $stmt_step->execute([$ob_step3_id, $outboarding_id, 'Ressources humaines', 3]);
        $stmt_step->execute([$ob_step4_id, $outboarding_id, 'Logistique', 4]);

        // Destinataires des étapes
        // ⚠️  Ces adresses sont des VALEURS PAR DÉFAUT destinées à être remplacées
        //     par l'administrateur via admin_forms.php. Elles ne sont pas vérifiées.
        //     L'administrateur DOIT configurer les vrais destinataires avant utilisation.
        $stmt_sr = $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)");
        $stmt_sr->execute([generate_uuid(), $ob_step1_id, 'responsable.direct@exemple.invalid']);
        $stmt_sr->execute([generate_uuid(), $ob_step2_id, 'informatique@exemple.invalid']);
        $stmt_sr->execute([generate_uuid(), $ob_step3_id, 'rh@exemple.invalid']);
        $stmt_sr->execute([generate_uuid(), $ob_step4_id, 'logistique@exemple.invalid']);

        // Owners du formulaire
        $stmt_fo = $pdo->prepare("INSERT OR IGNORE INTO form_owners (id, form_id, email) VALUES (?, ?, ?)");
        $stmt_fo->execute([generate_uuid(), $outboarding_id, 'responsable.direct@exemple.invalid']);
        $stmt_fo->execute([generate_uuid(), $outboarding_id, 'rh@exemple.invalid']);
    }

    // Insertion des paramètres par défaut si la table settings est vide
    $settings_count = _dbm_q($pdo, "SELECT COUNT(*) FROM settings")->fetchColumn();
    if ((int) $settings_count === 0) {
        $defaults = [
            ['smtp_host', 'smtp.social.gouv.fr'],
            ['smtp_port', '25'],
            ['smtp_auth', '0'],
            ['smtp_secure', ''],
            ['smtp_user', ''],
            ['smtp_pass', ''],
            ['smtp_from', 'workflow@exemple.invalid'],
            ['smtp_from_name', 'CircuitDémat'],
            ['token_expire_days', '30'],
            ['app_name', 'CircuitDémat'],
            ['app_favicon', ''],
            ['ldap_suggest_enabled', '0'],
            ['ldap_suggest_filter', '(|(cn=*{query}*)(mail=*{query}*)(sn=*{query}*)(givenName=*{query}*))'],
        ];
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
        foreach ($defaults as $row) {
            $stmt->execute($row);
        }
    }

    // Seed formulaire onboarding s'il n'existe pas
    $onb_count = _dbm_q($pdo, "SELECT COUNT(*) FROM forms WHERE slug = 'onboarding'")->fetchColumn();
    if ((int) $onb_count === 0) {
        $onboarding_id = generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$onboarding_id, 'onboarding', 'Accueil agent', 'Formulaire d\'accueil d\'un nouvel agent — prise de poste, création des accès et formalités d\'entrée']);

        $onboarding_fields = [
            ['Identité de l\'agent',  'Nom',                            'text',    'nom',               null,                                                           1, 1],
            ['Identité de l\'agent',  'Prénom',                         'text',    'prenom',            null,                                                           1, 2],
            ['Identité de l\'agent',  'Date de naissance',              'date',    'date_naissance',    null,                                                           1, 3],
            ['Identité de l\'agent',  'Date de prise de poste',         'date',    'date_prise_poste',  null,                                                           1, 4],
            ['Identité de l\'agent',  'Corps / Grade',                  'select',  'corps_grade',       '["Attaché d\'administration","Secrétaire administratif","Adjoint administratif","Inspecteur du travail","Contrôleur du travail","Technicien","Ingénieur","Autre"]', 1, 5],
            ['Identité de l\'agent',  'Type d\'arrivée',                'select',  'type_arrivee',      '["Mutation","Primo-recrutement","Détachement","Stage","Alternance"]', 1, 6],
            ['Identité de l\'agent',  'Service / Affectation',          'text',    'affectation',       null,                                                           1, 7],
            ['Identité de l\'agent',  'Quotité',                        'select',  'quotite',           '["100%","80%","50%"]',                                         1, 8],
            ['Informatique (IT)',     'Type de poste',                  'select',  'type_poste',        '["Fixe","Portable"]',                                          1, 9],
            ['Informatique (IT)',     'Double écran',                   'checkbox','it_double_ecran',   null,                                                           0, 10],
            ['Informatique (IT)',     'Accès RPVN',                     'checkbox','it_acces_rpvn',    null,                                                           0, 11],
            ['Informatique (IT)',     'Téléphone professionnel',        'checkbox','it_telephone_pro', null,                                                           0, 12],
            ['Informatique (IT)',     'Applicatifs métier',             'textarea','it_applicatifs',   null,                                                           0, 13],
            ['Ressources Humaines',   'Dossier administratif à constituer','checkbox','rh_dossier_admin',null,                                                          0, 14],
            ['Ressources Humaines',   'Affiliation mutuelle MGEN',      'checkbox','rh_mutuelle',      null,                                                           0, 15],
            ['Ressources Humaines',   'Visite médicale à planifier',    'checkbox','rh_visite_medicale',null,                                                          0, 16],
            ['Ressources Humaines',   'Habilitation sécurité requise',  'checkbox','rh_habilitation',  null,                                                           0, 17],
            ['Logistique',            'Bâtiment / Bureau',              'text',    'log_batiment_bureau',null,                                                          1, 18],
            ['Logistique',            'Badge d\'accès',                 'checkbox','log_badge_acces',  null,                                                           0, 19],
            ['Logistique',            'Véhicule de service',            'checkbox','log_vehicule_service',null,                                                         0, 20],
            ['Logistique',            'EPI à préparer',                 'checkbox','log_epi_requis',   null,                                                           0, 21],
            ['Décision',              'Décision de validation',         'select',  'decision_validation','["Accepté","Accepté avec réserves","Refusé"]',                 1, 22, 'validator', 'Responsable direct'],
        ];
        $stmt_ob = $pdo->prepare("INSERT INTO form_fields (id, card_group, label, field_type, field_name, options, required, ordre, form_id, filled_by, validator_step) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($onboarding_fields as $row) {
            $filled_by = $row[7] ?? 'demandeur';
            $validator_step = $row[8] ?? '';
            $stmt_ob->execute([generate_uuid(), $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $onboarding_id, $filled_by, $validator_step]);
        }

        // Etapes par defaut pour l'onboarding
        $onb_step1_id = generate_uuid();
        $onb_step2_id = generate_uuid();
        $onb_step3_id = generate_uuid();
        $onb_step4_id = generate_uuid();
        $stmt_step = $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, 1)");
        $stmt_step->execute([$onb_step1_id, $onboarding_id, 'Responsable direct', 1]);
        $stmt_step->execute([$onb_step2_id, $onboarding_id, 'Service informatique', 2]);
        $stmt_step->execute([$onb_step3_id, $onboarding_id, 'Ressources humaines', 3]);
        $stmt_step->execute([$onb_step4_id, $onboarding_id, 'Logistique', 4]);

        // Destinataires des étapes
        $stmt_sr = $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)");
        $stmt_sr->execute([generate_uuid(), $onb_step1_id, 'responsable.direct@exemple.invalid']);
        $stmt_sr->execute([generate_uuid(), $onb_step2_id, 'informatique@exemple.invalid']);
        $stmt_sr->execute([generate_uuid(), $onb_step3_id, 'rh@exemple.invalid']);
        $stmt_sr->execute([generate_uuid(), $onb_step4_id, 'logistique@exemple.invalid']);

        // Owners du formulaire
        $stmt_fo = $pdo->prepare("INSERT OR IGNORE INTO form_owners (id, form_id, email) VALUES (?, ?, ?)");
        $stmt_fo->execute([generate_uuid(), $onboarding_id, 'responsable.direct@exemple.invalid']);
        $stmt_fo->execute([generate_uuid(), $onboarding_id, 'rh@exemple.invalid']);
    }

    // Seed formulaire "Demande de sortie hors plages" s'il n'existe pas
    $sortie_count = _dbm_q($pdo, "SELECT COUNT(*) FROM forms WHERE slug = 'sortie_hors_plages'")->fetchColumn();
    if ((int) $sortie_count === 0) {
        $sortie_id = generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$sortie_id, 'sortie_hors_plages', 'Demande de sortie hors plages fixes', 'Demande d\'autorisation de sortie en dehors des plages horaires fixes — arrivée tardive, départ anticipé, pause prolongée']);

        $sortie_fields = [
            ['Agent',                  'Prénom',              'text',     'prenom',          null,                                                                                                   '', 1, 1],
            ['Agent',                  'Nom',                 'text',     'nom',             null,                                                                                                   '', 1, 2],
            ['Agent',                  'Email',               'text',     'email',           null,                                                                                                   '', 1, 3],
            ['Agent',                  'Service / Affectation', 'text',   'service',         null,                                                                                                   '', 1, 4],
            ['Détails de la sortie',   'Type de sortie',      'select',   'type_sortie',     '["Arrivée tardive","Départ anticipé","Pause déjeuner prolongée","Absence partielle","Autre"]',   '', 1, 5],
            ['Détails de la sortie',   'Date concernée',      'date',     'date_sortie',     null,                                                                                                   '', 1, 6],
            ['Détails de la sortie',   'Heure de début',      'text',     'heure_debut',     null,                                                                                                   'Format HH:MM', 1, 7],
            ['Détails de la sortie',   'Heure de fin',        'text',     'heure_fin',       null,                                                                                                   'Format HH:MM', 1, 8],
            ['Détails de la sortie',   'Motif',               'textarea', 'motif',           null,                                                                                                   '', 1, 9],
        ];
        $stmt_so = $pdo->prepare("INSERT INTO form_fields (id, card_group, label, field_type, field_name, options, hint, required, ordre, form_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($sortie_fields as $row) {
            $stmt_so->execute([generate_uuid(), $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $row[7], $sortie_id]);
        }

        // Etapes : Chef de service (ordre 1) → DRH (ordre 2)
        $sortie_step1_id = generate_uuid();
        $stmt_step = $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, 1)");
        $stmt_step->execute([$sortie_step1_id, $sortie_id, 'Chef de service', 1]);
        $sortie_step2_id = generate_uuid();
        $stmt_step->execute([$sortie_step2_id, $sortie_id, 'DRH', 2]);

        // Destinataires des étapes
        $stmt_sr = $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)");
        $stmt_sr->execute([generate_uuid(), $sortie_step1_id, 'chef.service@exemple.invalid']);
        $stmt_sr->execute([generate_uuid(), $sortie_step2_id, 'drh@exemple.invalid']);

        // Owners du formulaire
        $stmt_fo = $pdo->prepare("INSERT OR IGNORE INTO form_owners (id, form_id, email) VALUES (?, ?, ?)");
        $stmt_fo->execute([generate_uuid(), $sortie_id, 'chef.service@exemple.invalid']);
        $stmt_fo->execute([generate_uuid(), $sortie_id, 'drh@exemple.invalid']);
    }

    // Seed formulaire "Remboursement d'avance de frais" s'il n'existe pas
    $remboursement_count = _dbm_q($pdo, "SELECT COUNT(*) FROM forms WHERE slug = 'remboursement_avance_frais'")->fetchColumn();
    if ((int) $remboursement_count === 0) {
        $remboursement_id = generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$remboursement_id, 'remboursement_avance_frais', 'Remboursement d\'avance de frais', 'Demande de remboursement d\'une avance de frais engagée dans le cadre professionnel — déplacement, fourniture, représentation']);

        $remboursement_fields = [
            ['Agent',                  'Prénom',              'text',     'prenom',                  null,                                                                                                   '', 1, 1],
            ['Agent',                  'Nom',                 'text',     'nom',                     null,                                                                                                   '', 1, 2],
            ['Agent',                  'Email',               'text',     'email',                   null,                                                                                                   '', 1, 3],
            ['Agent',                  'Service / Affectation', 'text',   'service',                 null,                                                                                                   '', 1, 4],
            ['Détails de la dépense',  'Nature de la dépense', 'select',  'nature_depense',          '["Déplacement professionnel","Hébergement","Repas / Représentation","Fournitures bureautiques","Frais postaux","Autre"]', '', 1, 5],
            ['Détails de la dépense',  'Montant',             'text',     'montant',                 null,                                                                                                   'En euros TTC', 1, 6],
            ['Détails de la dépense',  'Date de la dépense',  'date',     'date_depense',            null,                                                                                                   '', 1, 7],
            ['Détails de la dépense',  'Justification',       'textarea', 'justification',           null,                                                                                                   '', 1, 8],
            ['Détails de la dépense',  'Justificatif (description)', 'text', 'justificatif_desc',  null,                                                                                            'Description du justificatif joint', 0, 9],
        ];
        $stmt_rb = $pdo->prepare("INSERT INTO form_fields (id, card_group, label, field_type, field_name, options, hint, required, ordre, form_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($remboursement_fields as $row) {
            $stmt_rb->execute([generate_uuid(), $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $row[7], $remboursement_id]);
        }

        // Etapes : Chef de service (ordre 1) → Comptabilité (ordre 2) → Agent financier (ordre 3)
        $remboursement_step1_id = generate_uuid();
        $stmt_step = $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, 1)");
        $stmt_step->execute([$remboursement_step1_id, $remboursement_id, 'Chef de service', 1]);
        $remboursement_step2_id = generate_uuid();
        $stmt_step->execute([$remboursement_step2_id, $remboursement_id, 'Comptabilité', 2]);
        $remboursement_step3_id = generate_uuid();
        $stmt_step->execute([$remboursement_step3_id, $remboursement_id, 'Agent financier', 3]);

        // Destinataires des étapes
        $stmt_sr = $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)");
        $stmt_sr->execute([generate_uuid(), $remboursement_step1_id, 'chef.service@exemple.invalid']);
        $stmt_sr->execute([generate_uuid(), $remboursement_step2_id, 'comptabilite@exemple.invalid']);
        $stmt_sr->execute([generate_uuid(), $remboursement_step3_id, 'agent.financier@exemple.invalid']);

        // Owners du formulaire
        $stmt_fo = $pdo->prepare("INSERT OR IGNORE INTO form_owners (id, form_id, email) VALUES (?, ?, ?)");
        $stmt_fo->execute([generate_uuid(), $remboursement_id, 'comptabilite@exemple.invalid']);
        $stmt_fo->execute([generate_uuid(), $remboursement_id, 'agent.financier@exemple.invalid']);
    }

    // Seed formulaire "Demande de matériel suite prescription médicale" s'il n'existe pas
    $materiel_med_count = _dbm_q($pdo, "SELECT COUNT(*) FROM forms WHERE slug = 'materiel_prescription'")->fetchColumn();
    if ((int) $materiel_med_count === 0) {
        $materiel_med_id = generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$materiel_med_id, 'materiel_prescription', 'Demande de matériel (prescription médicale)', 'Demande de matériel suite à une prescription médicale — aménagement de poste, équipement ergonomique, matériel spécifique']);

        $materiel_med_fields = [
            ['Agent',                          'Prénom',                    'text',     'prenom',                  null,                                                                                                   '', 1, 1],
            ['Agent',                          'Nom',                       'text',     'nom',                     null,                                                                                                   '', 1, 2],
            ['Agent',                          'Email',                     'text',     'email',                   null,                                                                                                   '', 1, 3],
            ['Agent',                          'Service / Affectation',      'text',     'service',                 null,                                                                                                   '', 1, 4],
            ['Agent',                          'Bureau / Lieu de travail',   'text',     'bureau',                  null,                                                                                                   '', 1, 5],
            ['Prescription médicale',          'Nature du handicap / besoin', 'select',  'nature_besoin',          '["Trou musculosquelettique","Trouble visuel","Trouble auditif","Maladie chronique","Grossesse","Autre"]', '', 1, 6],
            ['Prescription médicale',          'Date de la prescription',    'date',     'date_prescription',       null,                                                                                                   '', 1, 7],
            ['Prescription médicale',          'Médecin prescripteur',       'text',     'medecin_prescripteur',    null,                                                                                                   '', 0, 8],
            ['Matériel demandé',               'Type de matériel',           'select',   'type_materiel',           '["Fauteuil ergonomique","Repose-pieds","Écran agrandi","Clavier adapté","Souris ergonomique","Plan de travail réglable","Éclairage spécifique","Autre"]', '', 1, 9],
            ['Matériel demandé',               'Description détaillée',      'textarea', 'description_materiel',   null,                                                                                                   '', 1, 10],
            ['Matériel demandé',               'Urgence',                    'select',   'urgence',                 '["Normale","Urgente — aménagement imminent","Très urgente — arrêt de travail risque"]',                                     '', 1, 11],
            ['Matériel demandé',               'Justification médicale',     'textarea', 'justification_medicale',  null,                                                                                                   '', 1, 12],
        ];
        $stmt_mm = $pdo->prepare("INSERT INTO form_fields (id, card_group, label, field_type, field_name, options, hint, required, ordre, form_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($materiel_med_fields as $row) {
            $stmt_mm->execute([generate_uuid(), $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $row[7], $materiel_med_id]);
        }

        // Etapes : Médecin de prévention (ordre 1) → Chef de service (ordre 2) → DSI + Logistique (parallèle, ordre 3) → DRH (ordre 4)
        $materiel_med_step1_id = generate_uuid();
        $stmt_step = $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, 1)");
        $stmt_step->execute([$materiel_med_step1_id, $materiel_med_id, 'Médecin de prévention', 1]);
        $materiel_med_step2_id = generate_uuid();
        $stmt_step->execute([$materiel_med_step2_id, $materiel_med_id, 'Chef de service', 2]);
        $materiel_med_step3_dsi_id = generate_uuid();
        $stmt_step->execute([$materiel_med_step3_dsi_id, $materiel_med_id, 'DSI', 3]);
        $materiel_med_step3_log_id = generate_uuid();
        $stmt_step->execute([$materiel_med_step3_log_id, $materiel_med_id, 'Logistique', 3]);
        $materiel_med_step4_id = generate_uuid();
        $stmt_step->execute([$materiel_med_step4_id, $materiel_med_id, 'DRH', 4]);

        // Destinataires des étapes
        $stmt_sr = $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)");
        $stmt_sr->execute([generate_uuid(), $materiel_med_step1_id, 'medecin.prevention@exemple.invalid']);
        $stmt_sr->execute([generate_uuid(), $materiel_med_step2_id, 'chef.service@exemple.invalid']);
        $stmt_sr->execute([generate_uuid(), $materiel_med_step3_dsi_id, 'dsi@exemple.invalid']);
        $stmt_sr->execute([generate_uuid(), $materiel_med_step3_log_id, 'logistique@exemple.invalid']);
        $stmt_sr->execute([generate_uuid(), $materiel_med_step4_id, 'drh@exemple.invalid']);

        // Owners du formulaire — suivi du matériel médical
        $stmt_fo = $pdo->prepare("INSERT OR IGNORE INTO form_owners (id, form_id, email) VALUES (?, ?, ?)");
        $stmt_fo->execute([generate_uuid(), $materiel_med_id, 'medecin.prevention@exemple.invalid']);
        $stmt_fo->execute([generate_uuid(), $materiel_med_id, 'logistique@exemple.invalid']);
        $stmt_fo->execute([generate_uuid(), $materiel_med_id, 'drh@exemple.invalid']);
    }

    // Seed formulaire "Demande de mutation" s'il n'existe pas
    $mutation_count = _dbm_q($pdo, "SELECT COUNT(*) FROM forms WHERE slug = 'mutation'")->fetchColumn();
    if ((int) $mutation_count === 0) {
        $mutation_id = generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$mutation_id, 'mutation', 'Demande de mutation', 'Formulaire de demande de mutation interne — mobilité entre services ou directions au sein de la DREETS']);

        $mutation_fields = [
            ['Agent',                  'Prénom',              'text',     'prenom',           null,                                                                                    '', 1, 1],
            ['Agent',                  'Nom',                 'text',     'nom',              null,                                                                                    '', 1, 2],
            ['Agent',                  'Email',               'text',     'email',            null,                                                                                    '', 1, 3],
            ['Agent',                  'Corps / Grade',       'text',     'corps_grade',      null,                                                                                    '', 1, 4],
            ['Agent',                  'Service actuel',      'text',     'service_actuel',   null,                                                                                    '', 1, 5],
            ['Agent',                  'Quotité',             'select',   'quotite',          '["100%","80%","60%","50%"]',                                                            '', 1, 6],
            ['Mutation demandée',      'Service demandé',     'text',     'service_demande',  null,                                                                                    '', 1, 7],
            ['Mutation demandée',      'Direction demandée',  'text',     'direction_demandee',null,                                                                                   '', 1, 8],
            ['Mutation demandée',      'Motif',               'textarea', 'motif',            null,                                                                                    '', 1, 9],
            ['Mutation demandée',      'Date souhaitée',      'date',     'date_souhaitee',   null,                                                                                    '', 1, 10],
        ];
        $stmt_mu = $pdo->prepare("INSERT INTO form_fields (id, card_group, label, field_type, field_name, options, hint, required, ordre, form_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($mutation_fields as $row) {
            $stmt_mu->execute([generate_uuid(), $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $row[7], $mutation_id]);
        }

        // Etapes : Chef de service actuel (ordre 1) → Chef service demandé (ordre 2) → DRH (ordre 3)
        $mutation_step1_id = generate_uuid();
        $stmt_step = $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, 1)");
        $stmt_step->execute([$mutation_step1_id, $mutation_id, 'Chef de service actuel', 1]);
        $mutation_step2_id = generate_uuid();
        $stmt_step->execute([$mutation_step2_id, $mutation_id, 'Chef service demandé', 2]);
        $mutation_step3_id = generate_uuid();
        $stmt_step->execute([$mutation_step3_id, $mutation_id, 'DRH', 3]);

        // Destinataires des étapes
        $stmt_sr = $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)");
        $stmt_sr->execute([generate_uuid(), $mutation_step1_id, 'chef.service@exemple.invalid']);
        $stmt_sr->execute([generate_uuid(), $mutation_step2_id, 'chef.service.demande@exemple.invalid']);
        $stmt_sr->execute([generate_uuid(), $mutation_step3_id, 'drh@exemple.invalid']);
    }

    // Seed formulaire "Demande de formation" s'il n'existe pas
    $formation_count = _dbm_q($pdo, "SELECT COUNT(*) FROM forms WHERE slug = 'formation'")->fetchColumn();
    if ((int) $formation_count === 0) {
        $formation_id = generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$formation_id, 'formation', 'Demande de formation', 'Formulaire de demande de formation continue — plan de formation, DIF/CPF, stage inter ou intra']);

        $formation_fields = [
            ['Agent',                  'Prénom',              'text',     'prenom',             null,                                                                                               '', 1, 1],
            ['Agent',                  'Nom',                 'text',     'nom',                null,                                                                                               '', 1, 2],
            ['Agent',                  'Email',               'text',     'email',              null,                                                                                               '', 1, 3],
            ['Agent',                  'Service',             'text',     'service',            null,                                                                                               '', 1, 4],
            ['Agent',                  'Poste',               'text',     'poste',              null,                                                                                               '', 1, 5],
            ['Formation demandée',     'Intitulé formation',  'text',     'intitule_formation', null,                                                                                               '', 1, 6],
            ['Formation demandée',     'Organisme',           'text',     'organisme',          null,                                                                                               '', 1, 7],
            ['Formation demandée',     'Date début',          'date',     'date_debut',         null,                                                                                               '', 1, 8],
            ['Formation demandée',     'Date fin',            'date',     'date_fin',           null,                                                                                               '', 1, 9],
            ['Formation demandée',     'Lieu',                'text',     'lieu',               null,                                                                                               '', 1, 10],
            ['Formation demandée',     'Coût estimé',         'text',     'cout_estime',        null,                                                                                               'en euros TTC', 1, 11],
            ['Formation demandée',     'Heures DIF',          'text',     'heures_dif',         null,                                                                                               'nombre d\'heures au titre du DIF/CPF', 1, 12],
            ['Justification',          'Objectif',            'textarea', 'objectif',           null,                                                                                               '', 1, 13],
            ['Justification',          'Impact métier',       'textarea', 'impact_metier',      null,                                                                                               '', 1, 14],
            ['Justification',          'Avis du chef',        'select',   'avis_chef',          '["Favorable","Défavorable","Réservé"]',                                                             '', 1, 15],
        ];
        $stmt_fo = $pdo->prepare("INSERT INTO form_fields (id, card_group, label, field_type, field_name, options, hint, required, ordre, form_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($formation_fields as $row) {
            $stmt_fo->execute([generate_uuid(), $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $row[7], $formation_id]);
        }

        // Etapes : Chef de service (ordre 1) → Formation (ordre 2) → DRH (ordre 3)
        $formation_step1_id = generate_uuid();
        $stmt_step = $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, 1)");
        $stmt_step->execute([$formation_step1_id, $formation_id, 'Chef de service', 1]);
        $formation_step2_id = generate_uuid();
        $stmt_step->execute([$formation_step2_id, $formation_id, 'Formation', 2]);
        $formation_step3_id = generate_uuid();
        $stmt_step->execute([$formation_step3_id, $formation_id, 'DRH', 3]);

        // Destinataires des étapes
        $stmt_sr = $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)");
        $stmt_sr->execute([generate_uuid(), $formation_step1_id, 'chef.service@exemple.invalid']);
        $stmt_sr->execute([generate_uuid(), $formation_step2_id, 'formation@exemple.invalid']);
        $stmt_sr->execute([generate_uuid(), $formation_step3_id, 'drh@exemple.invalid']);
    }

    // Seed formulaire "Demande d'accès SI" s'il n'existe pas
    $acces_si_count = _dbm_q($pdo, "SELECT COUNT(*) FROM forms WHERE slug = 'acces_si'")->fetchColumn();
    if ((int) $acces_si_count === 0) {
        $acces_si_id = generate_uuid();
        $pdo->prepare("INSERT INTO forms (id, slug, label, description, actif, created_at) VALUES (?, ?, ?, ?, 1, datetime('now'))")
            ->execute([$acces_si_id, 'acces_si', 'Demande d\'accès SI', 'Formulaire de demande d\'accès aux systèmes d\'information — création, modification ou suppression de comptes et droits']);

        $acces_si_fields = [
            ['Agent',                  'Prénom',              'text',     'prenom',             null,                                                                                               '', 1, 1],
            ['Agent',                  'Nom',                 'text',     'nom',                null,                                                                                               '', 1, 2],
            ['Agent',                  'Email',               'text',     'email',              null,                                                                                               '', 1, 3],
            ['Agent',                  'Service',             'text',     'service',            null,                                                                                               '', 1, 4],
            ['Agent',                  'Fonction',            'text',     'fonction',           null,                                                                                               '', 1, 5],
            ['Agent',                  'Date de prise de poste','date',  'date_prise_poste',   null,                                                                                               '', 1, 6],
            ['Accès demandés',         'Type d\'accès',       'select',   'type_acces',         '["Nouvel accès","Modification","Suppression"]',                                                     '', 1, 7],
            ['Accès demandés',         'Systèmes',            'textarea', 'systemes',           null,                                                                                               'Ex : APB, ENLAP, RPVN, MESSAGERIE, RÉSEAU, APPLICATIONS MÉTIER', 1, 8],
            ['Accès demandés',         'Justification',       'textarea', 'justification',      null,                                                                                               '', 1, 9],
            ['Accès demandés',         'Urgence',             'select',   'urgence',            '["Normale","Urgente - sous 48h"]',                                                                  '', 1, 10],
            ['Matériel',               'Poste de travail',    'select',   'poste_travail',      '["Poste fixe","Portable","Aucun"]',                                                                 '', 1, 11],
            ['Matériel',               'Téléphone',           'select',   'telephone',          '["Fixe","Mobile","Aucun"]',                                                                         '', 1, 12],
            ['Matériel',               'Périphériques',       'text',     'peripheriques',      null,                                                                                               'Ex : écran supplémentaire, clavier, souris, imprimante', 1, 13],
        ];
        $stmt_si = $pdo->prepare("INSERT INTO form_fields (id, card_group, label, field_type, field_name, options, hint, required, ordre, form_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($acces_si_fields as $row) {
            $stmt_si->execute([generate_uuid(), $row[0], $row[1], $row[2], $row[3], $row[4], $row[5], $row[6], $row[7], $acces_si_id]);
        }

        // Etapes : Chef de service (ordre 1) → DSI (ordre 2) → RSSI (ordre 3)
        $acces_si_step1_id = generate_uuid();
        $stmt_step = $pdo->prepare("INSERT INTO steps (id, form_id, label, ordre, actif) VALUES (?, ?, ?, ?, 1)");
        $stmt_step->execute([$acces_si_step1_id, $acces_si_id, 'Chef de service', 1]);
        $acces_si_step2_id = generate_uuid();
        $stmt_step->execute([$acces_si_step2_id, $acces_si_id, 'DSI', 2]);
        $acces_si_step3_id = generate_uuid();
        $stmt_step->execute([$acces_si_step3_id, $acces_si_id, 'RSSI', 3]);

        // Destinataires des étapes
        $stmt_sr = $pdo->prepare("INSERT INTO step_recipients (id, step_id, email) VALUES (?, ?, ?)");
        $stmt_sr->execute([generate_uuid(), $acces_si_step1_id, 'chef.service@exemple.invalid']);
        $stmt_sr->execute([generate_uuid(), $acces_si_step2_id, 'dsi@exemple.invalid']);
        $stmt_sr->execute([generate_uuid(), $acces_si_step3_id, 'rssi@exemple.invalid']);
    }
}
