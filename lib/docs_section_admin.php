<?php
declare(strict_types=1);

/**
 * Section de documentation - extraite de docs.php (P-DOCS refactor).
 * Renvoie le HTML rendu de la section via render_docs_section_admin().
 */

function render_docs_section_admin(): string
{
    ob_start();
    ?>
  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 4. GUIDE DE L'ADMINISTRATEUR                               -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="guide-administrateur">
    <h2>4. Guide de l'administrateur — Configurer et superviser</h2>

    <p>En tant qu'administrateur, vous configurez les formulaires, supervisez les demandes et gérez la conformité RGPD.</p>

    <!-- ── Accès admin ── -->
    <h3><span aria-hidden="true">🔑</span> Obtenir l'accès administrateur</h3>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Accédez à la page</strong> <code>index.php?p=admin_access</code> et cliquez sur <em>« Demander l'accès admin »</em>.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Votre demande est envoyée</strong> par email au super administrateur, qui peut l'approuver ou la refuser.</p>
      </div>
    </div>

    <img src="index.php?p=screenshot&f=09_admin_access.png" alt="Gestion des accès administrateur — demande et approbation" class="screenshot">
    <p class="screenshot-caption">Gestion des accès administrateur — processus de demande et d'approbation par le super administrateur</p>

    <img src="index.php?p=screenshot&f=02_index_admin.png" alt="Page d'accueil administrateur — accès au back office" class="screenshot">
    <p class="screenshot-caption">Page d'accueil vue par un administrateur — accès direct au back office et aux outils de gestion</p>

    <!-- ── Tableau de bord ── -->
    <h3><span aria-hidden="true">📊</span> Tableau de bord</h3>

    <p>Le <strong>tableau de bord</strong> (<code>index.php?p=dashboard</code>) est votre centre de commande. Il affiche :</p>

    <ul>
      <li><strong>Statistiques</strong> — Nombre total de demandes, en cours, clôturées</li>
      <li><strong>Filtres</strong> — Par statut (tous, en cours, clôturés) et par formulaire</li>
      <li><strong>Badges du circuit de validation</strong> — Chaque étape est représentée par un badge coloré :
        <ul>
          <li style="color:#1a6b3c;">■ <strong>Vert</strong> = validé</li>
          <li style="color:#b45309;">■ <strong>Orange</strong> = en attente (étape courante)</li>
          <li style="color:#595959;">■ <strong>Gris</strong> = pas encore démarré</li>
        </ul>
      </li>
      <li><strong>Bouton « détail »</strong> — Affiche l'historique des validations et les données du formulaire</li>
      <li><strong>Export CSV</strong> — Téléchargez les données au format tableur</li>
      <li><strong>Relance manuelle</strong> — Relancez individuellement un validateur en attente</li>
      <li><strong>Annulation</strong> — Annulez une demande en cours</li>
    </ul>

    <div class="tip-box">
      <p>Depuis le tableau de bord, vous pouvez aussi accéder aux pages de détail de chaque demande pour voir l'historique complet, les pièces jointes et les commentaires des validateurs.</p>
    </div>

    <img src="index.php?p=screenshot&f=07_dashboard.png" alt="Tableau de bord administrateur — statistiques et liste des demandes" class="screenshot">
    <p class="screenshot-caption">Tableau de bord — vue d'ensemble des demandes avec filtres, badges du circuit de validation et actions rapides</p>

    <!-- ── Gestion des formulaires ── -->
    <h3><span aria-hidden="true">📝</span> Gestion des formulaires</h3>

    <p>Depuis la page <strong>index.php?p=admin_forms</strong>, vous pouvez :</p>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Créer un formulaire</strong> — Donnez un libellé (nom affiché) et une description. L'identifiant technique est généré automatiquement à partir du libellé.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Modifier un formulaire</strong> — Changez le libellé, la description ou désactivez-le.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">3</span>
      <div class="step-text">
        <p><strong>Configurer les champs</strong> — Ajoutez, modifiez ou réorganisez les champs du formulaire (texte, liste déroulante, case à cocher, etc.).</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">4</span>
      <div class="step-text">
        <p><strong>Prévisualiser</strong> — Visualisez le formulaire tel que le verront les agents avant de le publier.</p>
      </div>
    </div>

    <div class="info-box">
      <p><strong>Astuce :</strong> Pour qu'une étape nécessite la validation de <strong>tous</strong> ses destinataires, mettez-les dans la même étape. Pour qu'ils valident <strong>séquentiellement</strong>, créez des étapes distinctes avec des ordres croissants.</p>
    </div>

    <!-- ── Types de champs ── -->
    <h4><span aria-hidden="true">📋</span> Référence des types de champs</h4>
    <p>Les champs suivants sont disponibles lors de la configuration d'un formulaire :</p>
    <table class="schema-table">
      <thead><tr><th>Type</th><th>Code</th><th>Description</th></tr></thead>
      <tbody>
        <tr><td><span aria-hidden="true">📝</span> Texte court</td><td><code>text</code></td><td>Champ texte simple sur une ligne (nom, prénom, numéro…)</td></tr>
        <tr><td><span aria-hidden="true">📅</span> Date</td><td><code>date</code></td><td>Sélecteur de date (jj/mm/aaaa) — date de naissance, prise de poste…</td></tr>
        <tr><td><span aria-hidden="true">📋</span> Liste déroulante</td><td><code>select</code></td><td>Choix unique parmi une liste prédéfinie (corps/grade, type de poste…)</td></tr>
        <tr><td><span aria-hidden="true">☑️</span> Case à cocher</td><td><code>checkbox</code></td><td>Choix multiples à cocher (options IT, actions RH…)</td></tr>
        <tr><td><span aria-hidden="true">📄</span> Zone de texte</td><td><code>textarea</code></td><td>Champ texte multiligne pour les commentaires ou descriptions longues</td></tr>
        <tr><td><span aria-hidden="true">📎</span> Fichier / Pièce jointe</td><td><code>file</code></td><td>Téléversement de fichier (stockage sécurisé en BDD, accès par lien sécurisé)</td></tr>
      </tbody>
    </table>

    <img src="index.php?p=screenshot&f=10_admin_forms.png" alt="Page d'administration des formulaires — gestion des formulaires et champs" class="screenshot">
    <p class="screenshot-caption">Administration des formulaires — créer, modifier et configurer les champs et le circuit de validation</p>

    <img src="index.php?p=screenshot&f=18_form_preview.png" alt="Prévisualisation du formulaire — vue telle que les agents la verront" class="screenshot">
    <p class="screenshot-caption">Prévisualisation du formulaire — l'administrateur peut visualiser le formulaire tel que le verront les agents avant publication</p>

    <!-- ── Gestion des étapes et destinataires ── -->
    <h3><span aria-hidden="true">🔄</span> Gestion des étapes et destinataires</h3>

    <p>Pour chaque formulaire, vous définissez le <strong>circuit de validation</strong> :</p>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Ajoutez des étapes</strong> — Chaque étape a un libellé (ex : « Informatique », « RH », « Direction ») et un numéro d'ordre.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Ordre = séquence</strong> — L'ordre détermine la séquence du circuit de validation :
          <ul>
            <li><strong>Ordres différents</strong> = étapes <strong>séquentielles</strong> (l'étape 2 ne démarre qu'après la validation de l'étape 1)</li>
            <li><strong>Même ordre</strong> = étapes <strong>parallèles</strong> (elles démarrent en même temps)</li>
          </ul>
        </p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">3</span>
      <div class="step-text">
        <p><strong>Ajoutez des destinataires</strong> — Pour chaque étape, ajoutez les adresses email des validateurs. Plusieurs destinataires sur la même étape recevront tous une notification (validation parallèle).</p>
      </div>
    </div>

    <div class="info-box">
      <p><strong>Exemple de circuit :</strong><br>
      Ordre 1 : « Informatique » → doit être validé en premier<br>
      Ordre 2 : « Ressources Humaines » + « Logistique » → démarrent en parallèle après l'ordre 1<br>
      Ordre 3 : « Direction » → démarre quand l'ordre 2 est entièrement validé</p>
    </div>

    <div class="tip-box">
      <p>Modifier l'ordre des étapes n'affecte que les <em>nouvelles</em> demandes. Les demandes déjà en cours conservent l'ordre qui était en vigueur au moment de leur création.</p>
    </div>

    <!-- ── Alertes de deadline ── -->
    <h3><span aria-hidden="true">⏰</span> Configuration des alertes</h3>

    <p>La page <strong>index.php?p=admin_alerts</strong> permet de configurer des <strong>alertes automatiques</strong> quand une demande approche d'une date limite :</p>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Créez une règle d'alerte</strong> — Choisissez le formulaire concerné, le nombre de jours avant la deadline, et qui doit être notifié (admin ou email personnalisé).</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Le type de condition</strong> — Par exemple, « étapes incomplètes » déclenche l'alerte si des étapes ne sont pas encore validées.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">3</span>
      <div class="step-text">
        <p><strong>Activez ou désactivez</strong> chaque règle indépendamment.</p>
      </div>
    </div>

    <img src="index.php?p=screenshot&f=11_admin_alerts.png" alt="Page de configuration des alertes — règles de deadline" class="screenshot">
    <p class="screenshot-caption">Configuration des alertes — définir des règles de notification avant les dates limites</p>

    <!-- ── Surveillance ── -->
    <h3><span aria-hidden="true">🔍</span> Surveillance et supervision</h3>

    <p>La page <strong>index.php?p=monitoring</strong> vous donne une vue d'ensemble de l'état du système :</p>
    <ul>
      <li><strong>Temps moyen de traitement</strong> — Combien de temps prennent les demandes en moyenne</li>
      <li><strong>Taux de validation</strong> — Pourcentage de demandes validées / refusées / en cours</li>
      <li><strong>Tokens bloqués</strong> — Les validations en attente depuis trop longtemps</li>
      <li><strong>Tokens expirés</strong> — Les liens de validation qui ont dépassé leur date d'expiration</li>
      <li><strong>Alertes actives</strong> — Les demandes proches de leur deadline</li>
      <li><strong>Activité récente</strong> — Les dernières actions (validations, refus, créations)</li>
    </ul>

    <div class="tip-box">
      <p>Consultez régulièrement la page de surveillance pour identifier les validateurs qui tardent à répondre et les relancer si nécessaire.</p>
    </div>

    <img src="index.php?p=screenshot&f=08_monitoring.png" alt="Page de surveillance — état du système et alertes" class="screenshot">
    <p class="screenshot-caption">Surveillance — temps moyen, taux de validation, jetons bloqués et activité récente</p>

    <!-- ── Mode persona ── -->
    <h3><span aria-hidden="true">🎭</span> Mode persona — visualiser l'interface comme un agent</h3>

    <p>Le <strong>mode persona</strong> permet à un administrateur de <strong>visualiser l'interface exactement comme la verrait un agent utilisateur</strong>. C'est un outil précieux pour vérifier qu'un formulaire, un circuit de validation ou une page fonctionne correctement du point de vue métier, sans avoir à demander à un agent de tester ou à utiliser ses identifiants.</p>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Ouvrez le menu utilisateur</strong> — Dans la barre latérale (en bas à gauche), cliquez sur votre <em>carte utilisateur</em> (votre nom et avatar). Un menu déroulant apparaît.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Cliquez sur « 👤 Vue agent »</strong> — Le système génère alors un <strong>jeton de persona</strong> aléatoire (stocké en base dans la table <code>persona_tokens</code>) et vous redirige avec le paramètre <code>?persona_token=XXX</code> ajouté à l'URL.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">3</span>
      <div class="step-text">
        <p><strong>Naviguez comme un agent</strong> — Toutes les pages affichent désormais les données telles qu'un agent les verrait : la section d'administration de la barre latérale est masquée et les demandes, formulaires et notifications sont filtrés selon la vue agent.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">4</span>
      <div class="step-text">
        <p><strong>Quittez le mode persona</strong> — Cliquez sur le bouton <strong>« ✕ Quitter »</strong> de la bannière jaune pour revenir à votre session administrateur. Le jeton est révoqué et la barre latérale d'administration réapparaît.</p>
      </div>
    </div>

    <div class="info-box">
      <p><strong>Ce qui change en mode persona :</strong></p>
      <ul>
        <li>La section <em>Administration</em> de la barre latérale est <strong>masquée</strong> (la fonction <code>is_admin_effective()</code> renvoie <code>false</code>).</li>
        <li>Les <strong>données affichées sont filtrées</strong> comme si vous étiez l'agent sélectionné (demandes, formulaires, historique).</li>
        <li>Une <strong>bannière jaune « 🎭 Mode persona »</strong> apparaît en haut de chaque page avec un bouton <strong>« ✕ Quitter »</strong>.</li>
        <li>Le jeton est <strong>propagé automatiquement</strong> dans toutes les URLs via la fonction <code>persona_rewrite_urls()</code> — vous n'avez rien à gérer manuellement.</li>
      </ul>
    </div>

    <div class="warn-box">
      <p><strong><span aria-hidden="true">🔒</span> Sécurité :</strong> Le mode persona ne fait que <strong>rétrograder</strong> les privilèges (admin → agent). Il ne permet jamais à un agent de <strong>monter</strong> en privilèges vers administrateur. Ainsi, même si un jeton fuite, l'attaquant ne ferait que visualiser l'interface en tant qu'utilisateur simple. Le jeton <strong>expire automatiquement après 8 heures</strong> et devient également inactif si l'administrateur qui l'a créé perd ses droits entre-temps.</p>
    </div>

    <div class="tip-box">
      <p>Le mode persona est idéal pour : vérifier qu'un formulaire nouvellement créé s'affiche correctement, contrôler ce qu'un agent voit dans son tableau de bord, ou reproduire un signalement d'utilisateur sans avoir besoin de ses identifiants.</p>
    </div>

    <!-- ── Statistiques ── -->
    <h3><span aria-hidden="true">📈</span> Statistiques et reporting</h3>

    <p>La page <strong>index.php?p=stats</strong> fournit des statistiques détaillées :</p>
    <ul>
      <li><strong>Statistiques globales</strong> — Total, en cours, validés, refusés</li>
      <li><strong>Statistiques par période</strong> — Vue par semaine, mois ou année</li>
      <li><strong>Statistiques par formulaire</strong> — Nombre de demandes et temps moyen de traitement pour chaque formulaire</li>
      <li><strong>Statistiques par validateur</strong> — Nombre de validations, temps de réponse moyen</li>
      <li><strong>Graphique de répartition</strong> — Visualisation des statuts sous forme de graphique</li>
    </ul>

    <!-- ── RGPD ── -->
    <h3><span aria-hidden="true">🔒</span> Conformité RGPD</h3>

    <p>La page <strong>index.php?p=rgpd</strong> vous permet de gérer la conformité au Règlement Général sur la Protection des Données :</p>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Mentions légales</strong> — Modifiez le texte affiché aux utilisateurs en bas des formulaires. Ce texte doit informer les utilisateurs du traitement de leurs données.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Durée de conservation</strong> — Configurez le nombre de mois de conservation des données après clôture (par défaut : 24 mois).</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">3</span>
      <div class="step-text">
        <p><strong>Exporter les données d'un utilisateur</strong> — Saisissez une adresse email et téléchargez toutes les données associées au format JSON (droit d'accès RGPD).</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">4</span>
      <div class="step-text">
        <p><strong>Supprimer les données d'un utilisateur</strong> — Anonymisez les données d'une personne (droit d'effacement RGPD). Les données sont rendues anonymes, pas supprimées, pour préserver l'historique statistique.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">5</span>
      <div class="step-text">
        <p><strong>Purge automatique</strong> — Les demandes clôturées depuis plus longtemps que la durée de conservation sont automatiquement supprimées par la purge.</p>
      </div>
    </div>

    <!-- ── Webhooks ── -->
    <h3><span aria-hidden="true">🔗</span> Webhooks pour l'intégration SI</h3>

    <p>Les webhooks permettent de <strong>connecter l'application à votre système d'information</strong>. Quand un événement se produit (validation, refus, annulation, circuit de validation terminé), le système envoie automatiquement une notification à l'adresse configurée.</p>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Configurez l'URL du webhook</strong> — Dans les paramètres (<code>index.php?p=admin_settings</code>), renseignez l'URL qui recevra les notifications.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Choisissez les événements</strong> — Sélectionnez quels événements déclencheront l'envoi : circuit de validation terminé, validation effectuée, demande annulée, etc.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">3</span>
      <div class="step-text">
        <p><strong>Les notifications sont envoyées automatiquement</strong> — Chaque notification contient le type d'événement, l'horodatage et les données associées au format JSON.</p>
      </div>
    </div>

    <div class="tip-box">
      <p>Les webhooks sont optionnels. Si vous n'avez pas de système d'information à connecter, vous pouvez ignorer cette fonctionnalité.</p>
    </div>

    <!-- ── Health check ── -->
    <h3><span aria-hidden="true">💚</span> Health check (vérification de santé)</h3>

    <p>La page <strong>index.php?p=health</strong> vérifie automatiquement l'état de santé de l'application :</p>
    <ul>
      <li><strong>Base de données</strong> — SQLite est-elle accessible ?</li>
      <li><strong>Version PHP</strong> — Est-elle compatible ?</li>
      <li><strong>Répertoire de données</strong> — Est-il accessible en écriture ?</li>
      <li><strong>Schéma de base</strong> — Toutes les tables sont-elles présentes ?</li>
      <li><strong>Configuration SMTP</strong> — L'envoi d'emails est-il configuré ?</li>
    </ul>
    <p>Cette page retourne un statut HTTP 200 si tout va bien, ou 503 si un problème est détecté. Elle peut être utilisée par les outils de supervision externes.</p>

    <!-- ── Sauvegarde et restauration ── -->
    <h3><span aria-hidden="true">💾</span> Sauvegarde et restauration</h3>

    <p>La page <strong>index.php?p=backup</strong> permet de sauvegarder et restaurer la base de données :</p>

    <div class="step-row">
      <span class="step-num">1</span>
      <div class="step-text">
        <p><strong>Télécharger une sauvegarde</strong> — Crée une copie complète de la base de données au format .db que vous pouvez enregistrer sur votre poste.</p>
      </div>
    </div>
    <div class="step-row">
      <span class="step-num">2</span>
      <div class="step-text">
        <p><strong>Restaurer une sauvegarde</strong> — Importez un fichier .db précédemment sauvegardé pour remettre le système dans l'état correspondant.</p>
      </div>
    </div>

    <div class="warn-box">
      <p><strong><span aria-hidden="true">⚠</span> Attention :</strong> La restauration remplace toutes les données actuelles. Effectuez toujours une sauvegarde avant de restaurer. La restauration est irréversible.</p>
    </div>

    <div class="tip-box">
      <p>Prenez l'habitude de télécharger une sauvegarde régulièrement (par exemple chaque semaine). En cas de problème, vous pourrez toujours revenir à une version antérieure.</p>
    </div>

    <!-- ── Paramètres SMTP ── -->
    <h3><span aria-hidden="true">⚙️</span> Configuration des paramètres</h3>

    <p>La page <strong>index.php?p=admin_settings</strong> (réservée au super administrateur) permet de configurer :</p>
    <ul>
      <li><strong>Serveur SMTP</strong> — L'adresse du serveur d'envoi d'emails (ex : smtp.social.gouv.fr)</li>
      <li><strong>Port SMTP</strong> — Le port du serveur (ex : 25)</li>
      <li><strong>Expéditeur</strong> — L'adresse email d'expédition (ex : <?= h(get_setting('smtp_from', 'workflow@exemple.invalid')) ?>)</li>
      <li><strong>Nom de l'expéditeur</strong> — Le nom affiché (ex : CircuitDémat)</li>
      <li><strong>Délai de relance</strong> — Le nombre d'heures avant l'envoi d'un rappel automatique (ex : 48h)</li>
      <li><strong>URL du webhook</strong> — L'adresse pour les notifications automatiques</li>
      <li><strong>Événements webhook</strong> — Les événements à notifier</li>
    </ul>
    <div class="warn-box">
      <p><strong><span aria-hidden="true">⚠</span> Accès restreint :</strong> La page de paramètres est réservée au <strong>super administrateur</strong>.</p>
    </div>

    <img src="index.php?p=screenshot&f=12_admin_settings.png" alt="Page des paramètres — configuration SMTP et relances" class="screenshot">
    <p class="screenshot-caption">Paramètres — configuration SMTP, délai de relance et webhooks (réservé au super admin)</p>

    <img src="index.php?p=screenshot&f=13_docs.png" alt="Page de documentation et d'aide en ligne" class="screenshot">
    <p class="screenshot-caption">Page d'aide et documentation — guide complet accessible à tous les utilisateurs</p>

    <img src="index.php?p=screenshot&f=14_changelog.png" alt="Journal des modifications — historique des versions" class="screenshot">
    <p class="screenshot-caption">Journal des modifications — historique des évolutions et corrections par version</p>

    <!-- ── Admin vs Super admin ── -->
    <h3><span aria-hidden="true">👑</span> Admin vs Super admin</h3>
    <table class="schema-table">
      <thead>
        <tr>
          <th>Fonctionnalité</th>
          <th><span class="role-badge role-admin">Admin</span></th>
          <th><span class="role-badge role-superadmin">Super admin</span></th>
        </tr>
      </thead>
      <tbody>
        <tr><td>Voir le tableau de bord</td><td>✓</td><td>✓</td></tr>
        <tr><td>Gérer les formulaires et étapes</td><td>✓</td><td>✓</td></tr>
        <tr><td>Consulter les statistiques</td><td>✓</td><td>✓</td></tr>
        <tr><td>Accéder à la surveillance</td><td>✓</td><td>✓</td></tr>
        <tr><td>Configurer les alertes</td><td>✓</td><td>✓</td></tr>
        <tr><td>Sauvegarder et restaurer</td><td>✓</td><td>✓</td></tr>
        <tr><td>Gérer la conformité RGPD</td><td>✓</td><td>✓</td></tr>
        <tr><td>Approuver / refuser les demandes d'accès</td><td></td><td>✓</td></tr>
        <tr><td>Gérer la liste des administrateurs</td><td></td><td>✓</td></tr>
        <tr><td>Configurer les paramètres SMTP</td><td></td><td>✓</td></tr>
        <tr><td>Configurer les webhooks</td><td></td><td>✓</td></tr>
      </tbody>
    </table>
    <p>
      Le super administrateur est défini par son adresse email dans la configuration. Il s'agit généralement du premier administrateur, qui ne peut pas être supprimé.
    </p>
  </div>
    <?php
    // rtrim supprime les espaces de l indentation avant la balise fermante
    return rtrim((string) ob_get_clean(), " \t");
}
