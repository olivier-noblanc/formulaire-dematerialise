<?php
declare(strict_types=1);

namespace App\Controller;

use App\Core\App;
use App\Enum\AdminRequestStatus;

/**
 * Contrôleur de la page d'accès admin (demandes d'accès, approbation/refus).
 */
final class AdminAccessController extends BaseController
{
    public function handle(): void
    {
        $confirmData = null;
        $successMsg = '';
        $errorMsg = '';
        $warningMsg = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->security->requireCsrf();
            $action = $_POST['action'] ?? '';

            if ($action === 'request_access') {
                $email = App::auth()->getUser();
                $result = App::auth()->processAdminRequest($email);
                if ($result['success']) {
                    if ($result['reason'] === 'already_admin') {
                        $successMsg = 'Vous êtes déjà administrateur.';
                    } elseif ($result['reason'] === 'dry_run') {
                        $warningMsg = 'Votre demande a été enregistrée, mais l\'envoi d\'email est désactivé (mail_dry_run=1). Contactez directement l\'administrateur principal : ' . \App\Core\App::html()->escape(App::auth()->getAdminEmail());
                    } else {
                        $successMsg = 'Votre demande d\'accès admin a été envoyée. Vous recevrez un email lorsque l\'administrateur principal aura pris une décision.';
                    }
                } else {
                    if ($result['reason'] === AdminRequestStatus::Pending->value) {
                        $errorMsg = 'Vous avez déjà une demande d\'accès admin en attente. L\'administrateur principal doit l\'approuver. Contactez-le directement : ' . \App\Core\App::html()->escape(App::auth()->getAdminEmail());
                    } elseif ($result['reason'] === 'mail_failed') {
                        $errorMsg = 'Votre demande a été enregistrée mais l\'email n\'a pas pu être envoyé. Contactez directement l\'administrateur : ' . \App\Core\App::html()->escape(App::auth()->getAdminEmail());
                    } elseif ($result['reason'] === 'exception') {
                        $errorMsg = 'Erreur lors de la demande : ' . \App\Core\App::html()->escape($result['error'] ?? 'erreur inconnue');
                    } else {
                        $errorMsg = 'Une erreur est survenue. Contactez l\'administrateur : ' . \App\Core\App::html()->escape(App::auth()->getAdminEmail());
                    }
                }
            } elseif ($action === 'approve' && App::auth()->isSuperAdmin()) {
                $token = $_POST['token'] ?? '';
                $adminRepo = App::getInstance()->get(\App\Repository\AdminRepository::class);
                $request = $adminRepo->findByToken($token);
                if ($request) {
                    if (App::auth()->approveAdminRequest($request['email'], $request['id'] ?? null)) {
                        $successMsg = 'Demande d\'accès approuvée pour ' . \App\Core\App::html()->escape($request['email']) . '.';
                    } else {
                        $errorMsg = 'Erreur lors de l\'approbation de la demande.';
                    }
                } else {
                    $errorMsg = 'Demande invalide ou déjà traitée.';
                }
            } elseif ($action === 'reject' && App::auth()->isSuperAdmin()) {
                $token = $_POST['token'] ?? '';
                $adminRepo = App::getInstance()->get(\App\Repository\AdminRepository::class);
                $request = $adminRepo->findByToken($token);
                if ($request) {
                    if (App::auth()->rejectAdminRequest($request['email'], $request['id'] ?? null)) {
                        $successMsg = 'Demande d\'accès refusée pour ' . \App\Core\App::html()->escape($request['email']) . '.';
                    } else {
                        $errorMsg = 'Erreur lors du refus de la demande.';
                    }
                } else {
                    $errorMsg = 'Demande invalide ou déjà traitée.';
                }
            } elseif ($action === 'approve_request' && App::auth()->isSuperAdmin()) {
                $email = $_POST['email'] ?? '';
                if (App::auth()->approveAdminRequest($email)) {
                    $successMsg = 'Demande d\'accès approuvée.';
                } else {
                    $errorMsg = 'Erreur lors de l\'approbation de la demande.';
                }
            } elseif ($action === 'reject_request' && App::auth()->isSuperAdmin()) {
                $email = $_POST['email'] ?? '';
                if (App::auth()->rejectAdminRequest($email)) {
                    $successMsg = 'Demande d\'accès refusée.';
                } else {
                    $errorMsg = 'Erreur lors du refus de la demande.';
                }
            } elseif ($action === 'remove_admin' && App::auth()->isSuperAdmin()) {
                $email = $_POST['email'] ?? '';
                if (App::auth()->removeAdmin($email)) {
                    $successMsg = 'Administrateur retiré.';
                } else {
                    $errorMsg = 'Impossible de retirer cet administrateur (auto-suppression non autorisée, ou email invalide).';
                }
            }
        }

        // Handle GET token link (from email)
        if (isset($_GET['token']) && App::auth()->isSuperAdmin()) {
            $token = $_GET['token'];
            $adminRepo = App::getInstance()->get(\App\Repository\AdminRepository::class);
            $confirmData = $adminRepo->findByToken($token);
            if (!$confirmData) {
                $errorMsg = 'Lien invalide ou demande déjà traitée.';
                $confirmData = null;
            }
            // Store the action from the email link for the confirmation page
            $confirmAction = $_GET['action'] ?? 'approve';
        }

        $isSuperAdmin = App::auth()->isSuperAdmin();
        $pendingRequests = [];
        $allAdmins = [];
        $currentAdmin = App::auth()->getUser();

        if ($isSuperAdmin) {
            $adminRepo = App::getInstance()->get(\App\Repository\AdminRepository::class);
            $pendingRequests = $adminRepo->getPendingRequestsDesc();
            $allAdmins = $adminRepo->getAll();
        }

        $pageTitle = $isSuperAdmin ? 'Gestion des accès administrateur' : 'Accès administrateur';

        ob_start();
        ?>
  <h1><span aria-hidden="true">🔐</span> <?= \App\Core\App::html()->escape($pageTitle) ?></h1>

  <?= new \App\Render\ErrorRenderer()->messages(['success' => $successMsg, 'error' => $errorMsg, 'warning' => $warningMsg]) ?>

  <?php if ($confirmData): ?>
  <div class="card">
    <h2><?= ($confirmAction ?? 'approve') === 'reject' ? 'Confirmer le refus' : 'Confirmer l\'approbation' ?></h2>
    <p><?= ($confirmAction ?? 'approve') === 'reject' ? 'Refuser' : 'Approuver' ?> la demande d'accès de <strong><?= \App\Core\App::html()->escape($confirmData['email']) ?></strong> ?</p>
    <p style="font-size:.85rem;color:#555;">Demande créée le <?= \App\Core\App::html()->escape($confirmData['created_at']) ?></p>
    <div style="display:flex;gap:.5rem;margin-top:1rem;">
      <form method="POST">
        <?= $this->security->csrfField() ?>
        <input type="hidden" name="action" value="approve_request">
        <input type="hidden" name="email" value="<?= \App\Core\App::html()->escape($confirmData['email']) ?>">
        <button type="submit" class="btn btn-primary">Approuver</button>
      </form>
      <form method="POST">
        <?= $this->security->csrfField() ?>
        <input type="hidden" name="action" value="reject_request">
        <input type="hidden" name="email" value="<?= \App\Core\App::html()->escape($confirmData['email']) ?>">
        <button type="submit" class="btn btn-danger">Refuser</button>
      </form>
      <a href="index.php?p=admin_access" class="btn btn-secondary">Annuler</a>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!$isSuperAdmin): ?>
  <div class="card">
    <h2>Demande d'accès administrateur</h2>
    <p style="margin-bottom:1rem;color:#555;font-size:.9rem;">
      Vous n'êtes pas administrateur. Vous pouvez demander l'accès en cliquant ci-dessous.
      L'administrateur principal recevra un email et pourra approuver ou refuser votre demande.
    </p>
    <form method="POST">
      <?= $this->security->csrfField() ?>
      <input type="hidden" name="action" value="request_access">
      <button type="submit" class="btn btn-primary">Demander l'accès administrateur</button>
    </form>
  </div>
  <?php else: ?>

  <?php if (!empty($pendingRequests)): ?>
  <div class="card">
    <h2>Demandes en attente (<?= count($pendingRequests) ?>)</h2>
    <?php foreach ($pendingRequests as $pendingRequest): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:.75rem;border-bottom:1px solid #eee;">
      <div>
        <strong><?= \App\Core\App::html()->escape($pendingRequest['email']) ?></strong>
        <div style="font-size:.8rem;color:#555;">Demandé le <?= \App\Core\App::html()->escape($pendingRequest['requested_at']) ?></div>
      </div>
      <div style="display:flex;gap:.5rem;">
        <form method="POST" style="display:inline;">
          <?= $this->security->csrfField() ?>
          <input type="hidden" name="action" value="approve_request">
          <input type="hidden" name="email" value="<?= \App\Core\App::html()->escape($pendingRequest['email']) ?>">
          <button type="submit" class="btn btn-primary" style="font-size:.8rem;padding:.3rem .6rem;">Approuver</button>
        </form>
        <form method="POST" style="display:inline;">
          <?= $this->security->csrfField() ?>
          <input type="hidden" name="action" value="reject_request">
          <input type="hidden" name="email" value="<?= \App\Core\App::html()->escape($pendingRequest['email']) ?>">
          <button type="submit" class="btn btn-danger" style="font-size:.8rem;padding:.3rem .6rem;">Refuser</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2>Administrateurs actuels (<?= count($allAdmins) ?>)</h2>
    <table>
      <thead>
        <tr><th>Email</th><th>Rôle</th><th>Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($allAdmins as $allAdmin):
          $isSuper = App::getInstance()->get(\App\Repository\AdminRepository::class)->isSuperAdmin($allAdmin['email']);
          $isCurrent = $allAdmin['email'] === $currentAdmin;
          ?>
        <tr>
          <td><?= \App\Core\App::html()->escape($allAdmin['email']) ?> <?= $isCurrent ? '(vous)' : '' ?></td>
          <td><span class="badge <?= $isSuper ? 'badge-ok' : 'badge-info' ?>"><?= $isSuper ? 'Super Admin' : 'Admin' ?></span></td>
          <td>
            <?php if (!$isCurrent && !$isSuper): ?>
            <form method="POST" style="display:inline;">
              <?= $this->security->csrfField() ?>
              <input type="hidden" name="action" value="remove_admin">
              <input type="hidden" name="email" value="<?= \App\Core\App::html()->escape($allAdmin['email']) ?>">
              <a href="index.php?p=confirm_action&action=remove_admin&email=<?= urlencode((string) ($allAdmin['email'] ?? '')) ?>" class="btn btn-danger" style="font-size:.75rem;padding:.2rem .5rem;">Retirer</a>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
<?php
        $content = (string) ob_get_clean();
        echo $this->renderPage($pageTitle, 'admin_access', '', $content);
    }
}
