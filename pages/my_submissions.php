<?php
// my_submissions.php — Page "Mes demandes" pour l'agent connecté
require_once dirname(__DIR__) . '/helpers.php';

$user = get_auth_user();
$pdo  = get_pdo();
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['statut'] ?? 'tous';

// ── ITER1-C / Action B : simplification anti-jargon des libellés de formulaires ──
// M. Robert (70 ans) ne comprenait pas « Accès SI » (note jargon 5/10 → ≥9/10).
// Le libellé vient de la DB (table forms.label) — on ne peut pas modifier la DB,
// et on ne peut pas modifier helpers.php pour étendre t_jargon(). On crée donc
// une fonction locale qui :
//   1. Traduit d'abord les libellés courts techniques ("Accès SI", "Onboarding",
//      "Outboarding") en phrases claires en français courant.
//   2. Appelle ensuite t_jargon() sur le résultat pour traiter tout jargon
//      restant (« Circuit de validation », « Workflow », etc. — S4-UI).
// Mapping extensible : ajouter d'autres libellés ici si besoin.
if (!function_exists('simplify_form_label')) {
    function simplify_form_label(string $label): string {
        // 1) Mapping DB jargon → français courant (insensible à la casse pour
        //    tolerer les variations de saisie côté admin).
        //    On utilise une correspondance exacte (trim + strcmp insensible casse)
        //    pour ne pas toucher aux libellés qui contiendraient ces mots en
        //    sous-chaîne (ex. « Accès SI distant » ne doit pas devenir
        //    « Demande d'accès aux outils informatiques distant »).
        $map = [
            'Accès SI'    => 'Demande d\'accès aux outils informatiques',
            'Onboarding'  => 'Accueil d\'un nouvel agent',
            'Outboarding' => 'Départ d\'un agent',
        ];
        $trimmed = trim($label);
        foreach ($map as $jargon => $clair) {
            if (strcasecmp($trimmed, $jargon) === 0) {
                $label = $clair;
                break;
            }
        }
        // 2) On applique ensuite t_jargon() sur le résultat pour traiter le
        //    jargon générique restant (S4-UI : « Circuit de validation » →
        //    « Étapes de validation », « Workflow » → « Parcours », etc.).
        //    Idempotent (garanti par placeholders \x01/\x02 dans t_jargon).
        return t_jargon($label);
    }
}

// Récupérer toutes les soumissions de l'agent
// SQL Safety: $where[] conditions use only hardcoded column names and operators.
// User input is always passed via prepared statement parameters (?).
// NEVER add user-controlled column names to this array.
$where = ['s.submitted_by = ?'];
$params = [$user];
if ($search) {
    $where[] = "(f.label LIKE ? OR s.data LIKE ?)";
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
if ($status_filter === 'en_cours') { $where[] = "s.status = 'en_cours'"; }
elseif ($status_filter === 'valide') { $where[] = "s.status = 'valide'"; }
elseif ($status_filter === 'refuse') { $where[] = "s.status = 'refuse'"; }
elseif ($status_filter === 'annule') { $where[] = "s.status = 'annule'"; }
$where_sql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT s.id, s.form_id, s.data, s.submitted_at, s.status, s.closed_at,
           f.label as form_label, f.slug as form_slug, f.description as form_description, f.deadline_field
    FROM submissions s
    JOIN forms f ON f.id = s.form_id
    WHERE $where_sql
    ORDER BY s.submitted_at DESC
");
$stmt->execute($params);
$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pour chaque soumission, récupérer les étapes du workflow avec leur statut
// A-13: optimisé — était N+1 (2 requêtes par soumission : get_workflow_steps + get_tokens_for_submission)
// Batch: 1 requête pour tous les workflow_steps (par form_id) + 1 requête pour tous les tokens (par submission_id).
$workflow_steps_by_form = [];
$tokens_by_sub = [];
if (!empty($submissions)) {
    // Batch workflow_steps by form_id
    $form_ids = array_values(array_unique(array_column($submissions, 'form_id')));
    if (!empty($form_ids)) {
        $fph = implode(',', array_fill(0, count($form_ids), '?'));
        $ws_stmt = $pdo->prepare("
            SELECT st.id as step_id, st.label as step_label, st.ordre, st.actif, st.form_id,
                   GROUP_CONCAT(sr.email, '|') as recipient_emails
            FROM steps st
            LEFT JOIN step_recipients sr ON sr.step_id = st.id
            WHERE st.form_id IN ($fph) AND st.actif = 1
            GROUP BY st.id
            ORDER BY st.form_id, st.ordre ASC, st.id ASC
        ");
        $ws_stmt->execute($form_ids);
        foreach ($ws_stmt->fetchAll(PDO::FETCH_ASSOC) as $ws_row) {
            $workflow_steps_by_form[$ws_row['form_id']][] = $ws_row;
        }
    }

    // Batch tokens by submission_id
    $sub_ids = array_column($submissions, 'id');
    $sph = implode(',', array_fill(0, count($sub_ids), '?'));
    $tk_stmt = $pdo->prepare("
        SELECT t.submission_id, t.email, t.done_at, t.sent_at, t.step_id,
               st.label, st.label as step_label, st.ordre
        FROM tokens t
        JOIN steps st ON st.id = t.step_id
        WHERE t.submission_id IN ($sph)
        ORDER BY t.submission_id, st.ordre ASC, st.label ASC
    ");
    $tk_stmt->execute($sub_ids);
    foreach ($tk_stmt->fetchAll(PDO::FETCH_ASSOC) as $tk_row) {
        $tokens_by_sub[$tk_row['submission_id']][] = $tk_row;
    }
}

foreach ($submissions as &$sub) {
    $sid = $sub['id'];

    $sub['workflow_steps'] = $workflow_steps_by_form[$sub['form_id']] ?? [];

    $sub['tokens'] = $tokens_by_sub[$sid] ?? [];

    $tokens_by_step = [];
    foreach ($sub['tokens'] as $tok) {
        $tokens_by_step[$tok['step_id']][] = $tok;
    }

    foreach ($sub['workflow_steps'] as &$ws) {
        $step_id = $ws['step_id'];
        /** @phpstan-ignore-next-line empty.offset */
        if (!isset($tokens_by_step[$step_id]) || empty($tokens_by_step[$step_id])) {
            $ws['step_status'] = 'upcoming';
            $ws['step_detail'] = '';
        } else {
            $all_done = true;
            $detail_parts = [];
            foreach ($tokens_by_step[$step_id] as $tok) {
                if (!empty($tok['done_at'])) {
                    $detail_parts[] = display_user($tok['email']) . ' <span aria-hidden="true">✓</span>';
                } else {
                    $all_done = false;
                    $detail_parts[] = display_user($tok['email']) . ' <span aria-hidden="true">⏳</span>';
                }
            }
            $ws['step_status'] = $all_done ? 'validated' : 'current';
            $ws['step_detail'] = implode('<br>', $detail_parts);
        }
    }
    unset($ws);

    // Calculer progression
    $total = count($sub['workflow_steps']);
    $done = count(array_filter($sub['workflow_steps'], fn($s) => $s['step_status'] === 'validated'));
    $sub['progress_pct'] = $total > 0 ? round(($done / $total) * 100) : 0;
    $sub['progress_done'] = $done;
    $sub['progress_total'] = $total;
}
unset($sub);

// v10.0.1 — Fix bug : les compteurs (total/en_cours/valide/refuse) doivent
// être calculés SANS le filtre statut, sinon quand on filtre par "valide"
// et qu'on a 0 validation, $total_count = 0 et la section stats disparaissait.
$counts_stmt = $pdo->prepare("
    SELECT status, COUNT(*) as cnt
    FROM submissions
    WHERE submitted_by = ?
    GROUP BY status
");
$counts_stmt->execute([$user]);
$total_count = 0;
$en_cours_count = 0;
$valide_count = 0;
$refuse_count = 0;
$annule_count = 0;
foreach ($counts_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $total_count += (int)$row['cnt'];
    if ($row['status'] === 'valide') $valide_count = (int)$row['cnt'];
    elseif ($row['status'] === 'refuse') $refuse_count = (int)$row['cnt'];
    elseif ($row['status'] === 'annule') $annule_count = (int)$row['cnt'];
    else $en_cours_count += (int)$row['cnt'];
}
unset($row);
?>
<?php
$page_css = '';
ob_start();
?>
  <h1><span aria-hidden="true">📋</span> Mes demandes</h1>
  <?php // v10.0.6 — subtitle supprimé (redondant avec le titre h1) ?>

  <?php if ($total_count > 0): ?>
  <?php // S4-UI / Action 5 : légende des statuts (P2 M. Robert).
       // M. Robert ne savait pas à quoi correspondent les statuts « en cours / validé / refusé »
       // sans exemple. On affiche une légende discrète en tête de liste ?>
  <div class="stats">
    <a href="index.php?p=my_submissions&statut=tous" class="stat <?= $status_filter === 'tous' ? 'active' : '' ?>"><strong><?= $total_count ?></strong><span>Total</span></a>
    <a href="index.php?p=my_submissions&statut=en_cours" class="stat en-cours <?= $status_filter === 'en_cours' ? 'active' : '' ?>"><strong><?= $en_cours_count ?></strong><span>En cours</span></a>
    <a href="index.php?p=my_submissions&statut=valide" class="stat valide <?= $status_filter === 'valide' ? 'active' : '' ?>"><strong><?= $valide_count ?></strong><span>Validées</span></a>
    <a href="index.php?p=my_submissions&statut=refuse" class="stat refuse <?= $status_filter === 'refuse' ? 'active' : '' ?>"><strong><?= $refuse_count ?></strong><span>Refusées</span></a>
    <a href="index.php?p=my_submissions&statut=annule" class="stat annule <?= $status_filter === 'annule' ? 'active' : '' ?>"><strong><?= $annule_count ?></strong><span>Annulées</span></a>
  </div>

  <!-- Barre de recherche -->
  <div style="margin-bottom:1.5rem;">
    <?= render_search_bar('index.php?p=my_submissions', $search, 'Rechercher...', ['statut' => $status_filter]) ?>
  </div>
  <?php endif; ?>

  <?php if (empty($submissions)): ?>
    <div class="empty-state">
      <div class="empty-icon" aria-hidden="true">📝</div>
      <p>Vous n'avez encore soumis aucune demande.</p>
      <?php
        $active_forms = _dbm_q($pdo, "SELECT slug, label FROM forms WHERE actif = 1 ORDER BY label")->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($active_forms)):
      ?>
        <p style="font-size:.9rem;color:#555;margin-bottom:.5rem;">Formulaires disponibles :</p>
        <?php foreach ($active_forms as $af): ?>
          <?php // ITER1-C / Action B : simplify_form_label() sur tous les
                // libellés affichés (vide-state + cartes soumissions). ?>
          <a href="index.php?p=form&f=<?= h($af['slug']) ?>" class="btn btn-primary" style="margin:.25rem;"><?= h(simplify_form_label($af['label'])) ?></a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <?php foreach ($submissions as $sub):
        $data = json_decode($sub['data'], true);
        $status = $sub['status'] ?? 'en_cours';
        $status_label = $status === 'valide' ? '✓ Validée' : ($status === 'refuse' ? '❌ Refusée' : ($status === 'annule' ? '🗑 Annulée' : '⏳ En cours'));
        $badge_cls = $status === 'valide' ? 'badge-valide' : ($status === 'refuse' ? 'badge-refuse' : ($status === 'annule' ? 'badge-annule' : 'badge-en-cours'));

        // Deadline
        $deadline_field = $sub['deadline_field'] ?? '';
        $deadline_val = $deadline_field ? ($data[$deadline_field] ?? '') : '';
        $deadline_badge = '';
        if (!empty($deadline_val) && $status === 'en_cours') {
            $dl = calculate_deadline_urgency($deadline_val, $status);
            $dl_days = $dl['days_left'];
            if ($dl_days !== null) {
                if ($dl_days < 0) $deadline_badge = '<span class="deadline-badge overdue"><span aria-hidden="true">🚨</span> J+' . abs($dl_days) . '</span>';
                elseif ($dl_days <= 2) $deadline_badge = '<span class="deadline-badge urgent"><span aria-hidden="true">⚠️</span> J-' . $dl_days . '</span>';
                elseif ($dl_days <= 5) $deadline_badge = '<span class="deadline-badge ok"><span aria-hidden="true">📅</span> J-' . $dl_days . '</span>';
            }
        }

        // Progression
        $pct = $sub['progress_pct'];
        $fill_cls = $pct === 100 ? 'complete' : ($pct > 0 ? 'in-progress' : 'in-progress');
    ?>
    <div class="sub-card">
      <a href="index.php?p=submission_view&id=<?= urlencode($sub['id']) ?>" style="text-decoration:none;color:inherit;">
      <div class="sub-card-header">
        <div>
          <?php // ITER1-C / Action B : simplify_form_label() sur tous les
                // form_label affichés (DB → français courant). ?>
          <div class="sub-card-title"><?= h(simplify_form_label($sub['form_label'])) ?> <?= $deadline_badge ?></div>
          <div class="sub-card-date">Soumis le <?= h(date('d/m/Y à H:i', strtotime($sub['submitted_at']))) ?> — <?= h(($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? '')) ?></div>
        </div>
        <span class="badge <?= $badge_cls ?>"><?= $status_label ?></span>
      </div>
      </a>
      <div class="sub-card-body">
        <!-- Progression bar -->
        <div class="inline-progress">
          <div class="inline-progress-bar">
            <div class="inline-progress-fill <?= $fill_cls ?>" style="width:<?= max($pct, 3) ?>%;"></div>
          </div>
          <div class="inline-progress-text"><?= $sub['progress_done'] ?>/<?= $sub['progress_total'] ?> étapes (<?= $pct ?>%)</div>
        </div>

        <!-- Timeline compact -->
        <div class="timeline-compact">
          <?php foreach ($sub['workflow_steps'] as $ws):
            $cls = $ws['step_status'] === 'validated' ? 'done' : ($ws['step_status'] === 'current' ? 'active' : 'waiting');
            $icon = $ws['step_status'] === 'validated' ? '✓' : ($ws['step_status'] === 'current' ? '⏳' : '○');
          ?>
            <div class="tl-step <?= $cls ?>">
              <span class="tl-icon" aria-hidden="true"><?= $icon ?></span>
              <span class="tl-label"><?= h($ws['step_label']) ?></span>
              <?php if (!empty($ws['step_detail'])): ?>
                <span class="tl-detail"><?= $ws['step_detail'] ?></span>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>

        <?php if ($status === 'refuse' && isset($data['validations'])): ?>
          <div class="refusal-box">
            <?php
              foreach ($data['validations'] as $v) {
                  if ($v['action'] === 'refuser') {
                      echo '<strong>Refusé par :</strong> ' . display_user($v['email']) . ' (' . h($v['step_label']) . ')';
                      if (!empty($v['commentaire'])) echo '<br><strong>Motif :</strong> ' . h($v['commentaire']);
                      break;
                  }
              }
            ?>
          </div>
        <?php elseif ($status === 'valide' && isset($data['validations'])): ?>
          <div class="validation-box">
            <?php
              // Afficher le validateur qui a clôturé la demande (dernier validateur)
              $last_validator = null;
              foreach ($data['validations'] as $v) {
                  if ($v['action'] === 'valider') {
                      $last_validator = $v;
                  }
              }
              if ($last_validator !== null) {
                  echo '<strong>Validée par :</strong> ' . display_user($last_validator['email']) . ' (' . h($last_validator['step_label']) . ')';
                  if (!empty($last_validator['commentaire'])) echo '<br><strong>Commentaire :</strong> ' . h($last_validator['commentaire']);
              } else {
                  echo '<strong>Demande validée</strong> — circuit complet';
              }
            ?>
          </div>
        <?php endif; ?>

        <div class="card-actions">
          <a href="index.php?p=submission_view&id=<?= urlencode($sub['id']) ?>" class="btn btn-primary" style="font-size:.85rem;"><span aria-hidden="true">👁</span> Voir le détail</a>
          <a href="index.php?p=form&f=<?= h($sub['form_slug']) ?>" class="btn btn-secondary" style="font-size:.85rem;">Nouvelle demande</a>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
<?php
$content = ob_get_clean();
if ($content === false) { $content = ''; }
echo render_page('Mes demandes', 'mes_demandes', $page_css, $content);
