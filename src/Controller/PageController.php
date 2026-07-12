<?php

declare(strict_types=1);

namespace App\Controller;

/**
 * Contrôleur générique — wrappe une page PHP existante.
 * Pattern de migration progressive : chaque page peut être convertie
 * individuellement en héritant de ce contrôleur puis en surchargeant handle().
 */
final class PageController extends BaseController
{
    public function __construct(private readonly string $pageFile)
    {
        parent::__construct();
    }

    public function handle(): void
    {
        // Rendre les services accessibles globalement pour les fonctions façade
        $GLOBALS['_app_services'] = [
            'db' => $this->db,
            'auth' => $this->auth,
            'settings' => $this->settings,
            'fields' => $this->fields,
            'security' => $this->security,
            'mail' => $this->mail,
            'audit' => $this->audit,
            'cache' => $this->cache,
            'html' => $this->html,
            'workflow' => $this->workflow,
            'conditions' => $this->conditions,
        ];

        require $this->pageFile;
    }
}
