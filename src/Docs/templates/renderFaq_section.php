
  <!-- ═══════════════════════════════════════════════════════════ -->
  <!-- 7. FAQ                                                     -->
  <!-- ═══════════════════════════════════════════════════════════ -->
  <div class="card" id="faq">
    <h2>7. FAQ — Questions fréquentes</h2>

    <details>
      <summary>Je n'ai pas reçu l'email de validation</summary>
      <div class="detail-body">
        <p>Pas de panique ! Voici les vérifications à effectuer dans l'ordre :</p>
        <ol>
          <li><strong>Courrier indésirable (spam)</strong> — Regardez dans votre dossier spam ou courrier indésirable. C'est la cause la plus fréquente.</li>
          <li><strong>Adresse email</strong> — Vérifiez que votre adresse email est bien celle enregistrée comme destinataire de l'étape. Demandez à l'administrateur de vérifier.</li>
          <li><strong>Délai</strong> — L'email peut mettre quelques minutes à arriver. Attendez 15 minutes avant de conclure qu'il n'arrivera pas.</li>
          <li><strong>Relance automatique</strong> — Si l'email ne vous parvient pas, le système vous renverra un email après le délai configuré (48h par défaut).</li>
          <li><strong>Configuration SMTP</strong> — Si le problème persiste, l'administrateur peut vérifier les paramètres SMTP dans index.php?p=admin_settings.</li>
        </ol>
      </div>
    </details>

    <details>
      <summary>Comment annuler ma demande ?</summary>
      <div class="detail-body">
        <p>Pour annuler une demande en cours :</p>
        <ol>
          <li>Rendez-vous sur la page <strong>« Mes demandes »</strong>.</li>
          <li>Trouvez la demande que vous souhaitez annuler.</li>
          <li>Cliquez sur le bouton <strong>« Annuler »</strong>.</li>
          <li>Confirmez l'annulation.</li>
        </ol>
        <p><strong>Attention :</strong> L'annulation est irréversible. La demande sera clôturée et les validateurs ne recevront plus de relances. Pour soumettre à nouveau, il faudra remplir un nouveau formulaire.</p>
      </div>
    </details>

    <details>
      <summary>Puis-je déléguer ma validation ?</summary>
      <div class="detail-body">
        <p><strong>Oui !</strong> Si vous n'êtes pas la bonne personne pour valider, vous pouvez déléguer :</p>
        <ol>
          <li>Accédez à <strong>« Mes validations »</strong>.</li>
          <li>Trouvez la demande à déléguer et cliquez sur <strong>« Déléguer »</strong>.</li>
          <li>Entrez l'adresse email du collègue à qui vous déléguez.</li>
          <li>Ajoutez un motif (facultatif) et validez.</li>
        </ol>
        <p>Le système annule votre lien de validation et envoie un nouveau lien au délégataire. La délégation est enregistrée dans l'historique.</p>
      </div>
    </details>

    <details>
      <summary>Combien de temps est conservée ma demande ?</summary>
      <div class="detail-body">
        <p>Les données sont conservées pendant la <strong>durée configurée par l'administrateur</strong> (par défaut : <strong>24 mois</strong> après la clôture de la demande).</p>
        <p>Après ce délai, les données sont automatiquement supprimées par la <strong>purge automatique RGPD</strong>. Cette purge s'exécute périodiquement pour garantir la conformité au RGPD.</p>
        <p>Vous pouvez demander la suppression anticipée de vos données en contactant l'administrateur ou le <?= \App\Core\App::html()->escape(\App\Core\App::settings()->get('rgpd_contact', 'CIL DREETS')) ?>.</p>
      </div>
    </details>

    <details>
      <summary>Comment ajouter un nouveau formulaire ?</summary>
      <div class="detail-body">
        <p>Pour créer un nouveau formulaire (réservé aux administrateurs) :</p>
        <ol>
          <li>Accédez à <strong>index.php?p=admin_forms</strong>.</li>
          <li>Dans la section « Ajouter un formulaire », renseignez :
            <ul>
              <li><strong>Libellé</strong> — Le titre affiché (ex : « Demande de congé »). L'identifiant technique (slug) est généré automatiquement à partir du libellé.</li>
              <li><strong>Description</strong> — Un texte explicatif affiché en haut du formulaire.</li>
            </ul>
          </li>
          <li>Cliquez sur <strong>Ajouter</strong>.</li>
          <li>Ajoutez les <strong>champs du formulaire</strong> (texte, liste, case à cocher…).</li>
          <li>Ajoutez les <strong>étapes de validation</strong> et les <strong>destinataires</strong> pour chaque étape.</li>
        </ol>
      </div>
    </details>

    <details>
      <summary>Comment configurer les alertes ?</summary>
      <div class="detail-body">
        <p>Les alertes permettent d'être prévenu quand une demande approche de sa date limite :</p>
        <ol>
          <li>Accédez à <strong>index.php?p=admin_alerts</strong> (réservé aux administrateurs).</li>
          <li>Cliquez sur <strong>« Ajouter une règle »</strong>.</li>
          <li>Choisissez le <strong>formulaire</strong> concerné.</li>
          <li>Indiquez le <strong>nombre de jours avant la deadline</strong> pour déclencher l'alerte.</li>
          <li>Choisissez le <strong>type de condition</strong> (ex : étapes incomplètes).</li>
          <li>Indiquez <strong>qui doit être notifié</strong> (admin ou adresse email personnalisée).</li>
          <li>Donnez un <strong>libellé</strong> à la règle et validez.</li>
        </ol>
      </div>
    </details>

    <details>
      <summary>Que faire si un validateur ne répond pas ?</summary>
      <div class="detail-body">
        <p>Plusieurs solutions s'offrent à vous :</p>
        <ol>
          <li><strong>Attendre la relance automatique</strong> — Le système envoie un rappel automatique après le délai configuré (48h par défaut).</li>
          <li><strong>Relance manuelle</strong> — L'administrateur peut relancer le validateur directement depuis le tableau de bord.</li>
          <li><strong>Délégation</strong> — Le validateur peut déléguer sa validation à un collègue plus disponible.</li>
          <li><strong>Consulter la surveillance</strong> — L'administrateur peut identifier les validateurs en retard depuis la page index.php?p=monitoring.</li>
        </ol>
      </div>
    </details>

    <details>
      <summary>Puis-je modifier une demande déjà envoyée ?</summary>
      <div class="detail-body">
        <p><strong>Non.</strong> Une fois le formulaire envoyé, les données ne peuvent plus être modifiées. C'est une garantie d'intégrité : les validateurs voient exactement ce qui a été soumis.</p>
        <p>Si vous avez fait une erreur :</p>
        <ul>
          <li><strong>Annulez</strong> la demande en cours depuis « Mes demandes ».</li>
          <li><strong>Soumettez</strong> un nouveau formulaire avec les bonnes informations.</li>
        </ul>
        <p>Pour les petites corrections (ex : numéro de bureau), contactez l'administrateur qui peut vous guider.</p>
      </div>
    </details>

    <details>
      <summary>Comment exporter les données ?</summary>
      <div class="detail-body">
        <p>Plusieurs options d'export sont disponibles :</p>
        <ul>
          <li><strong>Export CSV</strong> — Depuis le tableau de bord, vous pouvez télécharger les données au format tableur (CSV) pour les ouvrir dans Excel.</li>
          <li><strong>Export RGPD</strong> — Depuis la page index.php?p=rgpd, les administrateurs peuvent exporter toutes les données d'un utilisateur au format JSON (droit d'accès RGPD).</li>
          <li><strong>Sauvegarde complète</strong> — Depuis index.php?p=backup, vous pouvez télécharger une copie complète de la base de données.</li>
        </ul>
      </div>
    </details>

    <details>
      <summary>Comment accéder aux statistiques ?</summary>
      <div class="detail-body">
        <p>Les statistiques sont accessibles aux administrateurs depuis la page <strong>index.php?p=stats</strong>. Vous y trouverez :</p>
        <ul>
          <li>Le nombre total de demandes, en cours, validées et refusées</li>
          <li>Les statistiques par <strong>période</strong> (semaine, mois, année)</li>
          <li>Les statistiques par <strong>formulaire</strong> (nombre de demandes, temps moyen)</li>
          <li>Les statistiques par <strong>validateur</strong> (nombre de validations, temps de réponse)</li>
          <li>Un <strong>graphique</strong> de répartition des statuts</li>
        </ul>
      </div>
    </details>

    <details>
      <summary>Le système est-il conforme au RGPD ?</summary>
      <div class="detail-body">
        <p><strong>Oui.</strong> L'application a été conçue pour être conforme au Règlement Général sur la Protection des Données :</p>
        <ul>
          <li><strong>Droit d'accès</strong> — Chaque agent peut consulter ses données depuis « Mes demandes ». L'administrateur peut exporter les données d'un utilisateur.</li>
          <li><strong>Droit de rectification</strong> — Contactez l'administrateur pour corriger des données erronées.</li>
          <li><strong>Droit d'effacement</strong> — L'administrateur peut anonymiser les données d'un utilisateur depuis index.php?p=rgpd.</li>
          <li><strong>Purge automatique</strong> — Les données sont automatiquement supprimées après la durée de conservation configurée (24 mois par défaut).</li>
          <li><strong>Mentions légales</strong> — Un texte d'information est affiché aux utilisateurs en bas de chaque formulaire.</li>
          <li><strong>Journal d'audit</strong> — Toutes les actions sont tracées.</li>
        </ul>
      </div>
    </details>

    <details>
      <summary>L'application est-elle conforme au RGAA ?</summary>
      <div class="detail-body">
        <p>CircuitDémat est <strong>partiellement conforme</strong> au RGAA 4.1 (Référentiel Général d'Amélioration de l'Accessibilité).</p>
        <ul>
          <li><strong>Déclaration d'accessibilité</strong> — La déclaration d'accessibilité est disponible dans <code>docs/declaration-rgaa.md</code>.</li>
          <li><strong>10 critères conformes</strong> — Navigation clavier, contraste des couleurs, structure des titres, formulaires étiquetés, textes alternatifs, etc.</li>
          <li><strong>3 non-conformités connues</strong> — Diagrammes SVG (non restituables par les lecteurs d'écran), tri des tableaux (pas d'alternative clavier), notifications toast (disparition trop rapide pour la lecture).</li>
          <li><strong>Droit de recours</strong> — En cas de difficulté d'accès, contactez le responsable accessibilité (voir la déclaration) ou le Défenseur des droits (<code>https://formulaire.defenseurdesdroits.fr/</code>).</li>
        </ul>
      </div>
    </details>

    <details>
      <summary>Qui a accès à mes données ?</summary>
      <div class="detail-body">
        <p>Seules les personnes directement concernées par le processus de validation peuvent voir vos données :</p>
        <ul>
          <li><strong>Vous-même</strong> — Vous voyez vos propres demandes depuis « Mes demandes ».</li>
          <li><strong>Les validateurs</strong> — Les personnes qui doivent valider votre demande voient les informations nécessaires pour la traiter.</li>
          <li><strong>Les administrateurs</strong> — Ils peuvent voir toutes les demandes pour les superviser et les gérer.</li>
        </ul>
        <p>Les validateurs externes (hors réseau DREETS) ne voient que les données liées aux étapes qu'ils doivent valider.</p>
      </div>
    </details>

    <details>
      <summary>Pourquoi les adresses email sont-elles affichées sans le domaine ?</summary>
      <div class="detail-body">
        <p>Pour réduire la surcharge visuelle (tous les agents sont dans le même domaine <code>@exemple.invalid</code>), le système masque automatiquement le domaine des emails affichés :</p>
        <ul>
          <li><strong>Masquage du domaine</strong> — Quand l'utilisateur connecté est dans le même domaine que l'email affiché, le domaine est masqué (ex : <code>jean.dupont@exemple.invalid</code> → <code>jean.dupont@</code>).</li>
          <li><strong>« Vous »</strong> — Si l'email affiché correspond à l'utilisateur connecté, le texte <strong>« Vous »</strong> s'affiche à la place de l'email.</li>
          <li><strong>Domaine différent</strong> — Si l'email appartient à un domaine différent (validateur externe), l'email complet est affiché car cette information est alors utile.</li>
          <li><strong>Email complet accessible</strong> — L'email complet reste accessible via le tooltip (survol de la souris) sur l'élément affiché.</li>
        </ul>
        <p>Ce comportement est géré par la méthode <code>displayUser()</code> de <code>HtmlService</code>.</p>
      </div>
    </details>

    <details>
      <summary>Comment fonctionne la purge automatique ?</summary>
      <div class="detail-body">
        <p>La purge automatique est un mécanisme qui supprime les données anciennes pour respecter le RGPD :</p>
        <ol>
          <li>La purge supprime les demandes <strong>clôturées</strong> (validées ou refusées) depuis plus longtemps que la <strong>durée de conservation</strong> configurée (24 mois par défaut).</li>
          <li>Elle supprime également les pièces jointes, les tokens et les logs d'alerte associés.</li>
          <li>Elle s'exécute <strong>automatiquement</strong> de façon périodique (via le <strong>lazy cron</strong> intégré — voir la section « Prérequis de déploiement » ci-dessous).</li>
          <li>Chaque purge est enregistrée dans le <strong>journal d'audit</strong>.</li>
        </ol>
        <p>Vous pouvez modifier la durée de conservation dans la page RGPD (<code>index.php?p=rgpd</code>).</p>
      </div>
    </details>

    <details>
      <summary>Que faire en cas de problème technique ?</summary>
      <div class="detail-body">
        <p>En cas de problème, voici les premiers réflexes :</p>
        <ol>
          <li><strong>Consultez le health check</strong> — La page <code>index.php?p=health</code> vérifie automatiquement l'état de l'application (base de données, configuration email, etc.).</li>
          <li><strong>Vérifiez votre connexion</strong> — Assurez-vous d'être bien sur le réseau DREETS pour les pages qui nécessitent une authentification.</li>
          <li><strong>Essayez un autre navigateur</strong> — Certains problèmes peuvent être liés au navigateur.</li>
          <li><strong>Contactez votre administrateur</strong> — Il a accès à la surveillance, aux logs et aux paramètres pour diagnostiquer le problème.</li>
          <li><strong>Consultez le journal d'audit</strong> — L'administrateur peut vérifier les actions récentes pour comprendre ce qui s'est passé.</li>
        </ol>
        <p>En cas d'urgence, l'administrateur peut toujours annuler une demande bloquée et en recréer une nouvelle.</p>
      </div>
    </details>

    <details>
      <summary>J'ai cliqué sur le lien mais il indique « Déjà validé »</summary>
      <div class="detail-body">
        <p>Cela signifie que l'action a déjà été effectuée pour ce lien. Plusieurs causes possibles :</p>
        <ul>
          <li>Vous avez déjà cliqué sur le lien précédemment (volontairement ou par accident).</li>
          <li>Un collègue partageant la même adresse email a validé l'étape.</li>
          <li>Vous avez cliqué deux fois sur le bouton lors de la première visite.</li>
          <li>La validation a été déléguée à quelqu'un d'autre.</li>
        </ul>
        <p>Vérifiez sur le tableau de bord (si vous êtes admin) que l'étape apparaît bien comme validée. En cas de doute, contactez votre administrateur.</p>
      </div>
    </details>

    <details>
      <summary>Je suis validateur mais je ne fais pas partie du réseau DREETS. Puis-je valider ?</summary>
      <div class="detail-body">
        <p><strong>Oui.</strong> La page de validation (<code>index.php?p=validate</code>) est accessible sans authentification Windows. Le lien contenu dans l'email suffit pour valider ou refuser une étape. Vous n'avez pas besoin d'un compte sur le réseau DREETS.</p>
      </div>
    </details>

    <details>
      <summary>Puis-je annuler une validation déjà effectuée ?</summary>
      <div class="detail-body">
        <p><strong>Non.</strong> Une fois une action effectuée (validation ou refus), elle est irréversible. Le lien est marqué comme utilisé et ne peut plus servir.</p>
        <p>En cas d'erreur de validation :</p>
        <ul>
          <li>Contactez l'administrateur qui pourra examiner la situation depuis le tableau de bord.</li>
          <li>L'administrateur peut annuler la demande complète et vous demander de la soumettre à nouveau.</li>
        </ul>
      </div>
    </details>

    <details>
      <summary>Comment changer l'ordre de validation ?</summary>
      <div class="detail-body">
        <p>L'ordre de validation est déterminé par le <strong>numéro d'ordre</strong> de chaque étape :</p>
        <ol>
          <li>Accédez à <strong>index.php?p=admin_forms</strong>.</li>
          <li>Sélectionnez le formulaire concerné.</li>
          <li>Pour chaque étape, modifiez le champ <strong>Ordre</strong>.</li>
          <li>Un numéro plus petit = validation plus tôt dans le processus.</li>
          <li>Deux étapes avec le même numéro seront traitées <strong>en parallèle</strong>.</li>
        </ol>
        <div class="info-box">
          <p><strong>Attention :</strong> Modifier l'ordre n'affecte que les <em>nouvelles</em> demandes. Les demandes déjà en cours conservent l'ordre qui était en vigueur au moment de leur création.</p>
        </div>
      </div>
    </details>

    <details>
      <summary><span aria-hidden="true">🖥️</span> Prérequis de déploiement et installation (pour l'équipe IT)</summary>
      <div class="detail-body">
        <p>Cette section est destinée au personnel technique chargé de déployer ou maintenir l'application.</p>
        <h4>Prérequis système</h4>
        <ul>
          <li><strong>Serveur web</strong> — IIS 7+ sur Windows Server (authentification Windows intégrée activée)</li>
          <li><strong>PHP 8+</strong> — Obligatoire pour le support UUID v4 (fonctions <code>random_bytes()</code>, <code>bin2hex()</code>). PHP 7.x n'est plus compatible.</li>
          <li><strong>Extension PHP SQLite3</strong> — Activée par défaut, vérifiez avec <code>php -m | grep sqlite3</code></li>
          <li><strong>Extension PHP OpenSSL</strong> — Requise pour la génération sécurisée des tokens</li>
          <li><strong>Extension PHP mbstring</strong> — Recommandée pour le bon fonctionnement de PHPMailer</li>
          <li><strong>Accès en écriture</strong> — Le répertoire <code>db/</code> doit être accessible en écriture par le compte du pool IIS (IIS_IUSRS)</li>
        </ul>
        <h4>Installation</h4>
        <ol>
          <li>Déployez les fichiers dans le répertoire web du serveur IIS (ex : <code>C:\inetpub\wwwroot\workflow\</code>)</li>
          <li>Configurez l'authentification Windows dans IIS (Anonymous = Disabled, Windows Authentication = Enabled)</li>
          <li>Vérifiez les permissions du répertoire <code>db/</code> (accès en écriture pour IIS_IUSRS)</li>
          <li>Accédez à <code>install.php</code> dans votre navigateur pour générer le fichier <code>config.php</code> (SMTP, admin, URL de base) à partir d'un template — l'assistant vous guide étape par étape</li>
          <li>La base de données SQLite est créée automatiquement au premier accès — aucune opération manuelle nécessaire</li>
          <li>Accédez à <code>index.php?p=health</code> pour vérifier que tout fonctionne correctement</li>
        </ol>
        <h4>Tâches planifiées (lazy cron intégré depuis v4.2.0)</h4>
        <ul>
          <li><strong>Relance automatique</strong> — <code>remind.php</code> est exécuté automatiquement par le <strong>lazy cron</strong> intégré (une fois par heure, au premier accès PDO). Aucune tâche planifiée Windows à configurer.</li>
          <li><strong>Vérification des alertes J-N</strong> — <code>alert_check.php</code> est exécuté automatiquement par le lazy cron (une fois par jour, au premier accès PDO).</li>
          <li><strong>Purge RGPD</strong> — Déclenchée <strong>manuellement</strong> par un administrateur depuis la page <code>index.php?p=rgpd</code> (bouton « Purge automatique ») — supprime les demandes clôturées au-delà de la durée de conservation configurée (24 mois par défaut).</li>
        </ul>
        <div class="info-box">
          <p>Le mécanisme de <strong>lazy cron</strong> (introduit en v4.2.0) exécute les tâches planifiées au premier accès à la base de données, sans dépendre d'un Planificateur de tâches Windows externe. La table <code>lazy_cron</code> trace la dernière exécution de chaque tâche et un verrou atomique prévient les exécutions concurrentes.</p>
        </div>
        <h4>Sauvegardes</h4>
        <p>Le fichier <code>db/workflow.db</code> contient toutes les données. Sauvegardez-le régulièrement. La page <code>index.php?p=backup</code> permet aussi de télécharger une copie depuis l'interface.</p>
        <div class="tip-box">
          <p>Après le déploiement, accédez à <code>index.php?p=health</code> pour vérifier que tous les prérequis sont satisfaits (PHP 8+, SQLite accessible, répertoire inscriptible, SMTP configuré).</p>
        </div>
      </div>
    </details>

    <details>
      <summary>Comment configurer l'envoi d'emails ?</summary>
      <div class="detail-body">
        <p>Les paramètres d'envoi d'emails peuvent être configurés de deux manières :</p>
        <ol>
          <li><strong>Via l'interface</strong> — Accédez à <strong>index.php?p=admin_settings</strong> (réservé au super administrateur). Vous y trouverez les champs pour le serveur SMTP, le port, l'expéditeur, le nom et le délai de relance.</li>
          <li><strong>Via le fichier de configuration</strong> — Éditez le fichier <code>config.php</code> pour modifier les valeurs dans <code>SETTINGS_DEFAULTS</code> (smtp_host, smtp_port, etc.). Ces valeurs servent de fallback si le setting n'existe pas en base de données.</li>
        </ol>
        <p>Si l'interface index.php?p=admin_settings est disponible, préférez cette méthode : elle ne nécessite pas d'accès au serveur de fichiers.</p>
      </div>
    </details>
  </div>
