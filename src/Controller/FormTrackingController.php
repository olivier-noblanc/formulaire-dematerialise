<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;
use App\Enum\FilledBy;
use App\Enum\SubmissionStatus;

/**
 * Contrôleur de la page Tableau de suivi propriétaire (form_tracking).
 */
final class FormTrackingController extends BaseController
{
    public function handle(): void
    {
        App::auth()->getUser();
        $formUuid = trim($_GET['f'] ?? '');

        $form = null;
        if ($formUuid !== '' && $formUuid !== '0') {
            $form = get_form_by_uuid($formUuid);
        }

        if ($form === null) {
            new \App\Render\ErrorRenderer()->errorPage(
                404,
                'Formulaire introuvable',
                'Le formulaire que vous cherchez n\'existe pas ou a été désactivé.',
                'Vérifiez l\'adresse dans votre navigateur.' . "\n" . 'Si vous avez suivi un lien, contactez l\'expéditeur pour obtenir le bon lien.'
            );
            return;
        }

        $formId = $form['id'];

        $isAdmin = App::auth()->isAdmin() || App::auth()->isSuperAdmin();
        $isOwner = App::auth()->isFormOwner($formId);

        if (!$isAdmin && !$isOwner) {
            new \App\Render\ErrorRenderer()->errorPage(
                403,
                'Accès refusé',
                'Vous n\'êtes pas propriétaire de ce formulaire. Seuls les propriétaires désignés et les administrateurs peuvent accéder au tableau de suivi.',
                'Si vous pensez que vous devriez avoir accès, contactez un administrateur pour vérifier vos droits de propriétaire sur ce formulaire.'
            );
            return;
        }

        $fields = App::validatorData()->getFormFields($formId, FilledBy::Demandeur->value);

        $keyFields = [];
        $allFieldNames = [];
        foreach ($fields as $field) {
            $allFieldNames[$field['field_name']] = $field['label'];
            $fn = $field['field_name'];
            if (in_array($fn, ['nom', 'prenom', 'email', 'service', 'type_sortie', 'nature_depense',
                'montant', 'date_depense', 'type_materiel', 'nature_besoin', 'date_prescription',
                'urgence', 'date_sortie', 'heure_debut', 'heure_fin'], true)) {
                $keyFields[] = $field;
            }
        }

        // Pagination
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per_page = 25;
        try {
            $page = validate_input($page, 'int', ['min' => 1, 'max' => 10000]);
        } catch (\InvalidArgumentException) {
            // @silent-ok: fallback resets to safe default
            $page = 1;
        }
        $page = (int) $page;

        // Count total
        $total_rows = $this->submissionRepo->countByForm($formId);
        $total_pages = max(1, (int) ceil($total_rows / $per_page));
        if ($page > $total_pages) {
            $page = $total_pages;
        }
        $offset = ($page - 1) * $per_page;

        // Count by status (stats)
        $statusCounts = $this->submissionRepo->getStatusCountsByForm($formId);
        $total = $total_rows;
        $enCours = 0;
        $valide = 0;
        $refuse = 0;
        foreach ($statusCounts as $statusCount) {
            if ($statusCount['status'] === SubmissionStatus::EnCours->value) {
                $enCours = (int) $statusCount['cnt'];
            } elseif ($statusCount['status'] === SubmissionStatus::Valide->value) {
                $valide = (int) $statusCount['cnt'];
            } elseif ($statusCount['status'] === SubmissionStatus::Refuse->value) {
                $refuse = (int) $statusCount['cnt'];
            }
        }

        // Paginated fetch
        $submissions = $this->submissionRepo->findPaginatedByForm($formId, $per_page, $offset);

        ob_start();
        ?>
  <h1><span aria-hidden="true">📊</span> Suivi — <?= \App\Core\App::html()->escape($form['label']) ?></h1>

  <div class="stats">
    <a href="#" class="stat active"><strong><?= $total ?></strong><span>Total</span></a>
    <a href="#" class="stat en-cours"><strong><?= $enCours ?></strong><span>En cours</span></a>
    <a href="#" class="stat valide"><strong><?= $valide ?></strong><span>Validées</span></a>
    <a href="#" class="stat refuse"><strong><?= $refuse ?></strong><span>Refusées</span></a>
  </div>

  <?php if ($submissions === []): ?>
    <div class="empty-state">
      <div class="empty-icon" aria-hidden="true">📋</div>
      <p>Aucune soumission pour ce formulaire.</p>
    </div>
  <?php else: ?>
    <div class="card u-ov-auto">
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Demandeur</th>
            <?php foreach ($keyFields as $kf): ?>
              <th><?= \App\Core\App::html()->escape($kf['label']) ?></th>
            <?php endforeach; ?>
            <th>Statut</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($submissions as $submission):
            $data = json_decode($submission['data'], true) ?? [];
            $status = $submission['status'];
            $badgeCls = $status === SubmissionStatus::Valide->value ? 'badge-ok' : ($status === SubmissionStatus::Refuse->value ? 'badge-err' : ($status === SubmissionStatus::Annule->value ? 'badge-annule' : 'badge-warn'));
            $statusLabel = $status === SubmissionStatus::Valide->value ? 'Validée' : ($status === SubmissionStatus::Refuse->value ? 'Refusée' : ($status === SubmissionStatus::Annule->value ? 'Annulée' : 'En cours'));
            ?>
          <tr>
            <td class="u-fs-sm-ws-nowrap"><?= \App\Core\App::html()->escape(date('d/m/Y H:i', (int) strtotime($submission['submitted_at'] ?? ''))) ?></td>
            <td><?= \App\Core\App::html()->escape(($data['prenom'] ?? '') . ' ' . ($data['nom'] ?? '')) ?></td>
            <?php foreach ($keyFields as $keyField):
                $val = $data[$keyField['field_name']] ?? '';
                $valStr = is_array($val) ? implode(', ', $val) : (string) $val;
                $valShort = mb_strimwidth($valStr, 0, 40, '…', 'UTF-8');
                ?>
              <td title="<?= \App\Core\App::html()->escape($valStr) ?>"><?= \App\Core\App::html()->escape($valShort) ?></td>
            <?php endforeach; ?>
            <td><span class="badge <?= $badgeCls ?>"><?= $statusLabel ?></span></td>
            <td>
              <a href="index.php?p=submission_view&id=<?= urlencode((string) ($submission['id'] ?? '')) ?>" class="btn btn-secondary u-fs-xxs-p-xxs">Voir</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?= \App\Core\App::html()->renderPagination($page, $total_pages, 'index.php?p=form_tracking&f=' . urlencode($formUuid)) ?>
<?php
        $content = (string) ob_get_clean();
        echo $this->renderPage('Suivi — ' . $form['label'], 'form_tracking', '', $content);
    }
}
