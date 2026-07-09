<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;

/**
 * Contrôleur de la page Tableau de suivi propriétaire (form_tracking).
 */
final class FormTrackingController extends BaseController
{
    public function handle(): void
    {
        require_once dirname(__DIR__, 2) . '/lib/render_form_tracking.php';

        $user = App::auth()->getUser();
        $pdo = $this->db->getPdo();
        $formUuid = trim($_GET['f'] ?? '');

        $form = null;
        if (!empty($formUuid)) {
            $form = get_form_by_uuid($formUuid);
        }

        if (!$form) {
            render_error_page(404, 'Formulaire introuvable',
                'Le formulaire que vous cherchez n\'existe pas ou a été désactivé.',
                'Vérifiez l\'adresse dans votre navigateur.\nSi vous avez suivi un lien, contactez l\'expéditeur pour obtenir le bon lien.');
        }

        $formId = $form['id'];

        $isAdmin = App::auth()->isAdmin() || App::auth()->isSuperAdmin();
        $isOwner = App::auth()->isFormOwner($formId);

        if (!$isAdmin && !$isOwner) {
            render_error_page(403, 'Accès refusé',
                'Vous n\'êtes pas propriétaire de ce formulaire. Seuls les propriétaires désignés et les administrateurs peuvent accéder au tableau de suivi.',
                'Si vous pensez que vous devriez avoir accès, contactez un administrateur pour vérifier vos droits de propriétaire sur ce formulaire.');
        }

        $fields = get_form_fields($formId, 'demandeur');

        $keyFields = [];
        $allFieldNames = [];
        foreach ($fields as $f) {
            $allFieldNames[$f['field_name']] = $f['label'];
            $fn = $f['field_name'];
            if (in_array($fn, ['nom', 'prenom', 'email', 'service', 'type_sortie', 'nature_depense',
                'montant', 'date_depense', 'type_materiel', 'nature_besoin', 'date_prescription',
                'urgence', 'date_sortie', 'heure_debut', 'heure_fin'])) {
                $keyFields[] = $f;
            }
        }

        $submissions = $pdo->prepare("
            SELECT s.id, s.data, s.submitted_by, s.submitted_at, s.status, s.closed_at
            FROM submissions s
            WHERE s.form_id = ?
            ORDER BY s.submitted_at DESC
        ");
        $submissions->execute([$formId]);
        $submissions = $submissions->fetchAll(\PDO::FETCH_ASSOC);

        $total = count($submissions);
        $enCours = 0;
        $valide = 0;
        $refuse = 0;
        foreach ($submissions as $s) {
            if ($s['status'] === 'en_cours') $enCours++;
            elseif ($s['status'] === 'valide') $valide++;
            elseif ($s['status'] === 'refuse') $refuse++;
        }

        ob_start();
        ?>
  <h1><span aria-hidden="true">📊</span> Suivi — <?= h($form['label']) ?></h1>

  <div class="stats">
    <a href="#" class="stat active"><strong><?= $total ?></strong><span>Total</span></a>
    <a href="#" class="stat en-cours"><strong><?= $enCours ?></strong><span>En cours</span></a>
    <a href="#" class="stat valide"><strong><?= $valide ?></strong><span>Validées</span></a>
    <a href="#" class="stat refuse"><strong><?= $refuse ?></strong><span>Refusées</span></a>
  </div>

  <?php if (empty($submissions)): ?>
    <div class="empty-state">
      <div class="empty-icon" aria-hidden="true">📋</div>
      <p>Aucune soumission pour ce formulaire.</p>
    </div>
  <?php else: ?>
    <div class="card" style="overflow-x:auto;">
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Demandeur</th>
            <?php foreach ($keyFields as $kf): ?>
              <th><?= h($kf['label']) ?></th>
            <?php endforeach; ?>
            <th>Statut</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($submissions as $sub):
          $data = json_decode($sub['data'], true) ?: [];
          $status = $sub['status'];
          $badgeCls = $status === 'valide' ? 'badge-ok' : ($status === 'refuse' ? 'badge-err' : ($status === 'annule' ? 'badge-annule' : 'badge-warn'));
          $statusLabel = $status === 'valide' ? 'Validée' : ($status === 'refuse' ? 'Refusée' : ($status === 'annule' ? 'Annulée' : 'En cours'));
        ?>
          <tr>
            <td style="white-space:nowrap;font-size:.85rem;"><?= h(date('d/m/Y H:i', strtotime($sub['submitted_at']))) ?></td>
            <td><?= h(($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? '')) ?></td>
            <?php foreach ($keyFields as $kf):
              $val = $data[$kf['field_name']] ?? '';
              $valStr = is_array($val) ? implode(', ', $val) : (string)$val;
              $valShort = mb_strimwidth($valStr, 0, 40, '…', 'UTF-8');
            ?>
              <td title="<?= h($valStr) ?>"><?= h($valShort) ?></td>
            <?php endforeach; ?>
            <td><span class="badge <?= $badgeCls ?>"><?= $statusLabel ?></span></td>
            <td>
              <a href="index.php?p=submission_view&id=<?= urlencode($sub['id']) ?>" class="btn btn-secondary" style="font-size:.75rem;padding:.2rem .5rem;">Voir</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
<?php
        $content = (string)ob_get_clean();
        echo $this->renderPage('Suivi — ' . $form['label'], 'form_tracking', '', $content);
    }
}
