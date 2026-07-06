<?php
/**
 * tests/test_unit_wave6.php — Section 14 : Wave 6 — S2-CTO (Brouillons P-02 + Indicateur progression U-08)
 *
 * Module thématique extrait de test_unit.php (refactor P-TESTS).
 * Dépendances : test_bootstrap.php (test), tests/test_unit_helpers.php (helpers shared).
 */

declare(strict_types=1);

/**
 * Section 14 : Wave 6 — S2-CTO (Brouillons P-02 + Indicateur progression U-08)
 */
function run_tests_unit_wave6(): void {
echo "── 14. Tests Wave 6 — S2-CTO (Brouillons P-02 + Indicateur progression U-08) ──\n";

// ── Setup : s'assurer que la table drafts existe dans la DB test ──
// La DB test est pré-seedée avec schema_version=900 (>12), donc la migration
// v12 est skippée. On crée la table ici (CREATE TABLE IF NOT EXISTS — idempotent)
// pour permettre aux tests de s'exécuter sans dépendre d'un reset DB.
test('Setup : table drafts existe en DB test (P-02)', function() {
    $pdo = get_pdo();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS drafts (
            id TEXT PRIMARY KEY NOT NULL,
            form_id TEXT NOT NULL,
            user_email TEXT NOT NULL,
            data TEXT NOT NULL DEFAULT '{}',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_drafts_user_test ON drafts(user_email)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_drafts_updated_test ON drafts(updated_at)");
    $exists = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='drafts'")->fetchColumn();
    return $exists === 'drafts' ? true : 'Table drafts non créée';
});

// ── 14.1 save_draft() crée un nouveau brouillon avec un UUID v4 valide ──
test('save_draft() crée un brouillon avec UUID v4 valide (P-02)', function() {
    $pdo = get_pdo();
    $onb_id = $pdo->query("SELECT id FROM forms WHERE slug='onboarding' LIMIT 1")->fetchColumn();
    if (!$onb_id) return 'Form onboarding non trouvé en DB test';
    $user = 's2_cto_draft_create_' . bin2hex(random_bytes(4)) . '@dreets.gouv.fr';
    try {
        $draft_id = save_draft($onb_id, $user, ['nom' => 'Dupont', 'prenom' => 'Jean', 'date_prise_poste' => '2025-12-01']);
        // Vérifier UUID v4
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $draft_id)) {
            return 'UUID invalide (pas v4) : ' . $draft_id;
        }
        // Vérifier que le brouillon est bien en DB
        $stmt = $pdo->prepare("SELECT form_id, user_email, data FROM drafts WHERE id = ?");
        $stmt->execute([$draft_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return 'Brouillon non trouvé en DB après save_draft()';
        if ($row['form_id'] !== $onb_id) return 'form_id mismatch';
        if ($row['user_email'] !== $user) return 'user_email mismatch';
        $decoded = json_decode($row['data'], true);
        if ($decoded['nom'] !== 'Dupont') return 'data.nom mismatch : ' . ($decoded['nom'] ?? 'null');
        if ($decoded['date_prise_poste'] !== '2025-12-01') return 'data.date_prise_poste mismatch';
        return true;
    } finally {
        // Cleanup
        $pdo->prepare("DELETE FROM drafts WHERE user_email = ?")->execute([$user]);
    }
});

// ── 14.2 get_draft() récupère le brouillon avec les bonnes données ──
test('get_draft() récupère un brouillon par son ID (P-02)', function() {
    $pdo = get_pdo();
    $onb_id = $pdo->query("SELECT id FROM forms WHERE slug='onboarding' LIMIT 1")->fetchColumn();
    if (!$onb_id) return 'Form onboarding non trouvé en DB test';
    $user = 's2_cto_draft_get_' . bin2hex(random_bytes(4)) . '@dreets.gouv.fr';
    try {
        $draft_id = save_draft($onb_id, $user, ['nom' => 'Martin', 'commentaire' => 'Ceci est un test']);
        // Récupération avec vérif propriétaire
        $draft = get_draft($draft_id, $user);
        if (!$draft) return 'Brouillon non récupéré par get_draft()';
        if ($draft['id'] !== $draft_id) return 'id mismatch';
        if ($draft['form_id'] !== $onb_id) return 'form_id mismatch';
        if (!isset($draft['data_array'])) return 'data_array non pré-décodé';
        if ($draft['data_array']['nom'] !== 'Martin') return 'data_array.nom mismatch';
        if ($draft['data_array']['commentaire'] !== 'Ceci est un test') return 'data_array.commentaire mismatch';
        return true;
    } finally {
        $pdo->prepare("DELETE FROM drafts WHERE user_email = ?")->execute([$user]);
    }
});

// ── 14.3 save_draft() met à jour un brouillon existant (upsert via draft_id) — reprise brouillon ──
test('save_draft() met à jour un brouillon existant via draft_id (P-02 — reprise)', function() {
    $pdo = get_pdo();
    $onb_id = $pdo->query("SELECT id FROM forms WHERE slug='onboarding' LIMIT 1")->fetchColumn();
    if (!$onb_id) return 'Form onboarding non trouvé en DB test';
    $user = 's2_cto_draft_update_' . bin2hex(random_bytes(4)) . '@dreets.gouv.fr';
    try {
        // 1re sauvegarde
        $draft_id_v1 = save_draft($onb_id, $user, ['nom' => 'V1', 'etape' => '1']);
        // 2e sauvegarde avec draft_id → doit update, pas créer
        $draft_id_v2 = save_draft($onb_id, $user, ['nom' => 'V2', 'etape' => '2', 'commentaire' => 'ajout'], $draft_id_v1);
        if ($draft_id_v2 !== $draft_id_v1) return 'Update a créé un nouveau brouillon au lieu d\'updater : v1=' . $draft_id_v1 . ', v2=' . $draft_id_v2;
        // Vérifier que les données sont bien les nouvelles
        $draft = get_draft($draft_id_v1, $user);
        if (!$draft) return 'Brouillon introuvable après update';
        if ($draft['data_array']['nom'] !== 'V2') return 'Data non mise à jour : nom=' . ($draft['data_array']['nom'] ?? 'null');
        if ($draft['data_array']['etape'] !== '2') return 'Data non mise à jour : etape';
        if (!isset($draft['data_array']['commentaire'])) return 'Nouveau champ commentaire absent';
        // Vérifier qu'il n'y a qu'un seul brouillon pour cet user (l'update n'a pas créé de doublon)
        $count = (int)$pdo->query("SELECT COUNT(*) FROM drafts WHERE user_email = " . $pdo->quote($user))->fetchColumn();
        if ($count !== 1) return "Plusieurs brouillons après update : $count (attendu 1)";
        return true;
    } finally {
        $pdo->prepare("DELETE FROM drafts WHERE user_email = ?")->execute([$user]);
    }
});

// ── 14.4 list_drafts() retourne les brouillons de l'utilisateur avec form_label/slug ──
test('list_drafts() retourne les brouillons avec form_label/slug (P-02)', function() {
    $pdo = get_pdo();
    $onb_id = $pdo->query("SELECT id FROM forms WHERE slug='onboarding' LIMIT 1")->fetchColumn();
    if (!$onb_id) return 'Form onboarding non trouvé en DB test';
    $user = 's2_cto_draft_list_' . bin2hex(random_bytes(4)) . '@dreets.gouv.fr';
    try {
        // Créer 2 brouillons pour cet utilisateur
        save_draft($onb_id, $user, ['nom' => 'B1']);
        save_draft($onb_id, $user, ['nom' => 'B2']);
        $drafts = list_drafts($user);
        if (count($drafts) < 2) return 'Pas assez de brouillons retournés : ' . count($drafts);
        // Vérifier que form_label et form_slug sont bien joints
        $first = $drafts[0];
        if (!isset($first['form_label'])) return 'form_label manquant';
        if (!isset($first['form_slug'])) return 'form_slug manquant';
        if ($first['form_slug'] !== 'onboarding') return 'form_slug mismatch : ' . $first['form_slug'];
        // Vérifier le tri (updated_at DESC) — le 1er doit être plus récent
        if (count($drafts) >= 2) {
            $t1 = strtotime($drafts[0]['updated_at']);
            $t2 = strtotime($drafts[1]['updated_at']);
            if ($t1 < $t2) return 'Tri incorrect : premier brouillon plus ancien que le second';
        }
        return true;
    } finally {
        $pdo->prepare("DELETE FROM drafts WHERE user_email = ?")->execute([$user]);
    }
});

// ── 14.5 delete_draft() supprime le brouillon — suppression brouillon ──
test('delete_draft() supprime un brouillon appartenant à l\'utilisateur (P-02)', function() {
    $pdo = get_pdo();
    $onb_id = $pdo->query("SELECT id FROM forms WHERE slug='onboarding' LIMIT 1")->fetchColumn();
    if (!$onb_id) return 'Form onboarding non trouvé en DB test';
    $user = 's2_cto_draft_del_' . bin2hex(random_bytes(4)) . '@dreets.gouv.fr';
    try {
        $draft_id = save_draft($onb_id, $user, ['nom' => 'ToDelete']);
        $result = delete_draft($draft_id, $user);
        if ($result !== true) return 'delete_draft() a retourné false au lieu de true';
        // Vérifier que le brouillon n'est plus en DB
        $check = $pdo->prepare("SELECT id FROM drafts WHERE id = ?");
        $check->execute([$draft_id]);
        if ($check->fetchColumn()) return 'Brouillon toujours présent en DB après delete_draft()';
        return true;
    } finally {
        $pdo->prepare("DELETE FROM drafts WHERE user_email = ?")->execute([$user]);
    }
});

// ── 14.6 delete_draft() refuse de supprimer un brouillon appartenant à un autre user (sécurité) ──
test('delete_draft() refuse la suppression d\'un brouillon d\'autrui (P-02 — sécurité)', function() {
    $pdo = get_pdo();
    $onb_id = $pdo->query("SELECT id FROM forms WHERE slug='onboarding' LIMIT 1")->fetchColumn();
    if (!$onb_id) return 'Form onboarding non trouvé en DB test';
    $owner = 's2_cto_draft_owner_' . bin2hex(random_bytes(4)) . '@dreets.gouv.fr';
    $attacker = 's2_cto_draft_attacker_' . bin2hex(random_bytes(4)) . '@dreets.gouv.fr';
    try {
        $draft_id = save_draft($onb_id, $owner, ['nom' => 'OwnerOnly']);
        // L'attaquant tente de supprimer le brouillon de l'owner
        $result = delete_draft($draft_id, $attacker);
        if ($result === true) return 'delete_draft() a supprimé un brouillon appartenant à un autre user !';
        // Vérifier que le brouillon existe toujours
        $check = $pdo->prepare("SELECT id FROM drafts WHERE id = ?");
        $check->execute([$draft_id]);
        if (!$check->fetchColumn()) return 'Brouillon supprimé par delete_draft() d\'un autre user alors que return false';
        return true;
    } finally {
        $pdo->prepare("DELETE FROM drafts WHERE user_email = ?")->execute([$owner]);
        $pdo->prepare("DELETE FROM drafts WHERE user_email = ?")->execute([$attacker]);
    }
});

// ── 14.7 save_draft() ne peut pas updater un brouillon d'autrui (sécurité — vol d'ID) ──
test('save_draft() avec draft_id d\'autrui crée un nouveau brouillon (P-02 — sécurité)', function() {
    $pdo = get_pdo();
    $onb_id = $pdo->query("SELECT id FROM forms WHERE slug='onboarding' LIMIT 1")->fetchColumn();
    if (!$onb_id) return 'Form onboarding non trouvé en DB test';
    $owner = 's2_cto_save_owner_' . bin2hex(random_bytes(4)) . '@dreets.gouv.fr';
    $attacker = 's2_cto_save_attacker_' . bin2hex(random_bytes(4)) . '@dreets.gouv.fr';
    try {
        $owner_draft_id = save_draft($onb_id, $owner, ['nom' => 'OwnerData']);
        // L'attaquant fournit le draft_id de l'owner : save_draft doit créer un nouveau brouillon
        // pour l'attaquant (et NE PAS modifier le brouillon de l'owner).
        $attacker_draft_id = save_draft($onb_id, $attacker, ['nom' => 'AttackerData'], $owner_draft_id);
        if ($attacker_draft_id === $owner_draft_id) return 'save_draft() a updaté le brouillon d\'autrui au lieu de créer un nouveau !';
        // Vérifier que le brouillon de l'owner n'a pas été modifié
        $owner_draft = get_draft($owner_draft_id, $owner);
        if (!$owner_draft) return 'Brouillon owner disparu après save_draft() de l\'attaquant';
        if ($owner_draft['data_array']['nom'] !== 'OwnerData') return 'Brouillon owner modifié par l\'attaquant : nom=' . $owner_draft['data_array']['nom'];
        return true;
    } finally {
        $pdo->prepare("DELETE FROM drafts WHERE user_email = ?")->execute([$owner]);
        $pdo->prepare("DELETE FROM drafts WHERE user_email = ?")->execute([$attacker]);
    }
});

// ── 14.8 get_draft() retourne null pour un ID introuvable ──
test('get_draft() retourne null pour un ID introuvable (P-02)', function() {
    $fake_id = generate_uuid();
    $result = get_draft($fake_id, 'anyone@dreets.gouv.fr');
    return $result === null ? true : 'Attendu null, obtenu : ' . json_encode($result);
});

// ── 14.9 get_draft() retourne null pour un ID vide ──
test('get_draft() retourne null pour un ID vide (P-02 — robustesse)', function() {
    $result = get_draft('', 'anyone@dreets.gouv.fr');
    return $result === null ? true : 'Attendu null pour ID vide';
});

// ── 14.10 cleanup_old_drafts() supprime les brouillons de plus de N jours ──
test('cleanup_old_drafts() supprime les brouillons > 30 jours (P-02 — cron)', function() {
    $pdo = get_pdo();
    $onb_id = $pdo->query("SELECT id FROM forms WHERE slug='onboarding' LIMIT 1")->fetchColumn();
    if (!$onb_id) return 'Form onboarding non trouvé en DB test';
    $user = 's2_cto_cleanup_' . bin2hex(random_bytes(4)) . '@dreets.gouv.fr';
    try {
        // Créer un brouillon récent (ne doit pas être supprimé)
        $new_id = save_draft($onb_id, $user, ['nom' => 'Recent']);
        // Créer un brouillon ancien (updated_at = il y a 40 jours)
        $old_id = generate_uuid();
        $old_date = gmdate('Y-m-d H:i:s', time() - 40 * 86400);
        $pdo->prepare("INSERT INTO drafts (id, form_id, user_email, data, created_at, updated_at) VALUES (?, ?, ?, '{}', ?, ?)")
            ->execute([$old_id, $onb_id, $user, $old_date, $old_date]);
        // Lancer le cleanup avec seuil 30 jours
        $nb_deleted = cleanup_old_drafts($pdo, 30);
        if ($nb_deleted < 1) return 'Aucun brouillon supprimé par cleanup_old_drafts() (au moins 1 attendu)';
        // Vérifier que l'ancien est supprimé et le récent est conservé
        $old_check = $pdo->prepare("SELECT id FROM drafts WHERE id = ?");
        $old_check->execute([$old_id]);
        if ($old_check->fetchColumn()) return 'Brouillon ancien toujours présent après cleanup';
        $new_check = $pdo->prepare("SELECT id FROM drafts WHERE id = ?");
        $new_check->execute([$new_id]);
        if (!$new_check->fetchColumn()) return 'Brouillon récent supprimé par cleanup (ne devrait pas)';
        return true;
    } finally {
        $pdo->prepare("DELETE FROM drafts WHERE user_email = ?")->execute([$user]);
    }
});

// ── 14.11 render_form_progress_indicator() retourne '' pour un formulaire mono-section ──
test('render_form_progress_indicator() retourne chaîne vide pour mono-section (U-08)', function() {
    $grouped = ['Général' => [
        ['field_name' => 'nom', 'label' => 'Nom', 'field_type' => 'text', 'required' => 1],
        ['field_name' => 'prenom', 'label' => 'Prénom', 'field_type' => 'text', 'required' => 1],
    ]];
    $html = render_form_progress_indicator($grouped);
    return $html === '' ? true : 'Attendu chaîne vide, obtenu : ' . substr($html, 0, 100);
});

// ── 14.12 render_form_progress_indicator() retourne '' pour un formulaire sans section ──
test('render_form_progress_indicator() retourne chaîne vide pour 0 section (U-08)', function() {
    $html = render_form_progress_indicator([]);
    return $html === '' ? true : 'Attendu chaîne vide, obtenu : ' . substr($html, 0, 100);
});

// ── 14.13 render_form_progress_indicator() génère role="progressbar" pour multi-section ──
test('render_form_progress_indicator() génère role=progressbar pour multi-section (U-08)', function() {
    $grouped = [
        'Identité' => [
            ['field_name' => 'nom', 'label' => 'Nom', 'field_type' => 'text', 'required' => 1],
            ['field_name' => 'prenom', 'label' => 'Prénom', 'field_type' => 'text', 'required' => 1],
        ],
        'Poste' => [
            ['field_name' => 'date_prise_poste', 'label' => 'Date de prise de poste', 'field_type' => 'date', 'required' => 1],
        ],
    ];
    $html = render_form_progress_indicator($grouped);
    $errors = [];
    if ($html === '') $errors[] = 'HTML vide pour un formulaire multi-sections';
    if (strpos($html, 'role="progressbar"') === false) $errors[] = 'role="progressbar" manquant';
    if (strpos($html, 'aria-valuemin="0"') === false) $errors[] = 'aria-valuemin manquant';
    if (strpos($html, 'aria-valuemax="3"') === false) $errors[] = 'aria-valuemax incorrect (attendu 3 = 2+1 champs)';
    if (strpos($html, 'aria-valuenow="0"') === false) $errors[] = 'aria-valuenow initial manquant';
    if (strpos($html, 'Étape') === false) $errors[] = 'Label "Étape" manquant';
    if (strpos($html, 'sur 2') === false) $errors[] = 'Nombre de sections (2) manquant';
    if (strpos($html, 'id="form-progress-fill"') === false) $errors[] = 'ID form-progress-fill manquant';
    if (strpos($html, 'id="form-progress-bar"') === false) $errors[] = 'ID form-progress-bar manquant';
    return empty($errors) ? true : implode(' | ', $errors);
});

// ── 14.14 render_form_progress_indicator() exclut les champs file du total ──
test('render_form_progress_indicator() exclut les champs file du total (U-08)', function() {
    $grouped = [
        'Section A' => [
            ['field_name' => 'nom', 'label' => 'Nom', 'field_type' => 'text', 'required' => 1],
            ['field_name' => 'cv', 'label' => 'CV (PDF)', 'field_type' => 'file', 'required' => 1],
        ],
        'Section B' => [
            ['field_name' => 'email', 'label' => 'Email', 'field_type' => 'email', 'required' => 1],
        ],
    ];
    $html = render_form_progress_indicator($grouped);
    // 3 champs au total mais 1 file → total = 2
    if (strpos($html, 'aria-valuemax="2"') === false) return 'aria-valuemax incorrect (attendu 2, file exclu) : ' . substr($html, 0, 200);
    return true;
});

// ── 14.15 Régression : la migration v12 crée bien la table drafts via db_migrate ──
test('Régression : db_migrate() contient le bloc migration v12 (P-02)', function() {
    // Vérifier que le code de migration v12 est bien présent dans DatabaseMigrations.php
    // (le test ne ré-exécute pas la migration — il valide que le code est bien là)
    $file = dirname(__DIR__) . '/classes/DatabaseMigrations.php';
    if (!file_exists($file)) return 'classes/DatabaseMigrations.php introuvable';
    $content = file_get_contents($file);
    $errors = [];
    if (strpos($content, 'CREATE TABLE IF NOT EXISTS drafts') === false) {
        $errors[] = 'CREATE TABLE drafts manquant';
    }
    // Le marqueur de version est execute([12]) (bindé côté PHP comme pour les autres versions)
    if (strpos($content, '->execute([12])') === false) {
        $errors[] = 'Marqueur schema_version=12 manquant';
    }
    if (strpos($content, 'idx_drafts_user') === false) $errors[] = 'Index idx_drafts_user manquant';
    if (strpos($content, 'idx_drafts_updated') === false) $errors[] = 'Index idx_drafts_updated manquant';
    return empty($errors) ? true : implode(' | ', $errors);
});

echo "\n";
}
