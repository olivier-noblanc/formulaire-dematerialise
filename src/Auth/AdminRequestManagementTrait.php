<?php

declare(strict_types=1);

namespace App\Auth;

use App\Enum\AdminRequestStatus;

/**
 * Trait regroupant les méthodes de gestion des demandes d'accès admin.
 *
 * Utilisé par AuthService. Attend les membres suivants dans la classe hôte :
 *   - public AdminRepository $adminRepository
 *   - private function getMailer(): MailInterface
 *   - public function getAdminEmail(): string
 *   - public function getUser(): string
 *   - public function isAdmin(): bool
 */
trait AdminRequestManagementTrait
{
    // ── ADMIN REQUEST MANAGEMENT ────────────────────────────────

    /**
     * Traite une demande d'accès admin.
     *
     * @return array{success: bool, reason: string}
     */
    public function processAdminRequest(string $email): array
    {
        try {
            if ($this->isAdmin()) {
                return ['success' => true, 'reason' => 'already_admin'];
            }

            if ($this->adminRepository->hasPendingRequestByEmail($email)) {
                return ['success' => false, 'reason' => AdminRequestStatus::Pending->value];
            }

            $token = bin2hex(random_bytes(32));
            $ar_id = generate_uuid();
            $this->adminRepository->createAdminRequest($ar_id, $email, gmdate('Y-m-d H:i:s'), AdminRequestStatus::Pending->value, $token);

            \App\Core\App::audit()->log('admin_request', 'admin:' . $email, 'Demande d\'accès admin', $email);

            $subject = 'Demande d\'accès admin - ' . \App\Render\NavigationRenderer::getAppName();
            $body = $this->buildAdminRequestEmailBody($email, $token);

            $cc_email = \App\Core\App::settings()->get('admin_email_cc', '');
            $mail_sent = $this->getMailer()->send($this->getAdminEmail(), $subject, $body);
            if ($cc_email !== '' && $cc_email !== $this->getAdminEmail()) {
                $this->getMailer()->send($cc_email, '[CC] ' . $subject, $body);
            }

            $dry_run = \App\Core\App::settings()->get('mail_dry_run', '0') === '1';
            if ($dry_run) {
                return ['success' => true, 'reason' => 'dry_run'];
            }
            if (!$mail_sent) {
                return ['success' => false, 'reason' => 'mail_failed'];
            }

            return ['success' => true, 'reason' => 'sent'];
        } catch (\Exception $e) {
            error_log('Erreur lors de la demande d\'accès admin : ' . $e->getMessage());
            return ['success' => false, 'reason' => 'exception', 'error' => $e->getMessage()];
        }
    }

    public function approveAdminRequest(string $email, ?string $requestId = null): bool
    {
        try {
            if ($requestId === null) {
                $request = $this->adminRepository->findPendingByEmail($email);
                if ($request === null) {
                    return false;
                }
                $requestId = $request['id'];
            }

            $this->adminRepository->approveRequest($requestId, $this->getUser());

            $subject = 'Accès admin approuvé - ' . \App\Render\NavigationRenderer::getAppName();
            $body = $this->buildApprovalEmailBody();

            $this->getMailer()->send($email, $subject, $body);
            \App\Core\App::audit()->log('admin_approve', 'admin:' . $email, 'Accès admin approuvé');
            return true;
        } catch (\Exception $e) {
            error_log('Erreur lors de l\'approbation de la demande admin : ' . $e->getMessage());
            return false;
        }
    }

    public function rejectAdminRequest(string $email, ?string $requestId = null): bool
    {
        try {
            if ($requestId === null) {
                $request = $this->adminRepository->findPendingByEmail($email);
                if ($request === null) {
                    return false;
                }
                $requestId = $request['id'];
            }

            $this->adminRepository->rejectRequest($requestId, $this->getUser());

            $subject = 'Demande d\'accès admin refusée - ' . \App\Render\NavigationRenderer::getAppName();
            $body = $this->buildRejectionEmailBody();

            $this->getMailer()->send($email, $subject, $body);
            \App\Core\App::audit()->log('admin_reject', 'admin:' . $email, 'Accès admin refusé');
            return true;
        } catch (\Exception $e) {
            error_log('Erreur lors du refus de la demande admin : ' . $e->getMessage());
            return false;
        }
    }

    public function removeAdmin(string $email): bool
    {
        if ($email === $this->getAdminEmail()) {
            return false;
        }

        try {
            $this->adminRepository->deleteByEmail($email);
            \App\Core\App::audit()->log('admin_remove', 'admin:' . $email, 'Admin supprimé', $email);
            return true;
        } catch (\Exception $e) {
            error_log('Erreur lors de la suppression d\'un admin : ' . $e->getMessage());
            return false;
        }
    }

    // ── PRIVATE EMAIL BUILDERS ──────────────────────────────────

    private function buildAdminRequestEmailBody(string $email, string $token): string
    {
        $approve_url = resolve_base_url() . '/index.php?p=admin_access&action=approve&token=' . $token;
        $reject_url = resolve_base_url() . '/index.php?p=admin_access&action=reject&token=' . $token;
        return '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body>
    <h2>Demande d\'accès admin</h2>
    <p>Un utilisateur a demandé l\'accès admin au back office du workflow :</p>
    <p><strong>Utilisateur :</strong> ' . \App\Core\App::html()->escape($email) . '</p>
    <p><strong>Date :</strong> ' . gmdate('d/m/Y H:i:s') . ' UTC</p>
    <p><a href="' . $approve_url . '" style="background:#1a6b3c;color:#fff;padding:10px 15px;text-decoration:none;border-radius:4px;display:inline-block;margin-right:10px;">Approuver</a>
    <a href="' . $reject_url . '" style="background:#c0392b;color:#fff;padding:10px 15px;text-decoration:none;border-radius:4px;display:inline-block;">Refuser</a></p>
</body>
</html>';
    }

    private function buildApprovalEmailBody(): string
    {
        return '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body>
    <h2>Accès admin approuvé</h2>
    <p>Votre demande d\'accès admin au back office du workflow a été approuvée.</p>
    <p>Vous pouvez maintenant accéder au back office en cliquant sur le lien ci-dessous :</p>
    <p><a href="' . resolve_base_url() . '/index.php?p=admin_access">Accéder au back office</a></p>
</body>
</html>';
    }

    private function buildRejectionEmailBody(): string
    {
        return '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body>
    <h2>Demande d\'accès admin refusée</h2>
    <p>Votre demande d\'accès admin au back office du workflow a été refusée.</p>
</body>
</html>';
    }
}
