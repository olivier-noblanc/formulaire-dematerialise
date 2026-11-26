---
titre: Cahier des charges — Dématérialisation du workflow d'arrivée d'un agent
sous-titre: DREETS Bourgogne-Franche-Comté — Phase 1
porteur: DREETS Bourgogne-Franche-Comté — Groupe de travail Workflow
destinataire: Secrétaire générale
prestataire: ATOL CD
objet: Dématérialisation et pilotage du workflow d'arrivée d'un agent (phase 1)
version: 0.1 — projet pour relecture
date: 13 octobre 2026
statut: Projet — document de travail à diffusion restreinte
mentions: Ne constitue pas un engagement contractuel.
---

## 1. Objet du document

Le présent cahier des charges a pour objet de décrire le besoin de la DREETS Bourgogne-Franche-Comté en matière de dématérialisation et de pilotage du circuit administratif d'arrivée d'un agent. Il expose le contexte, les objectifs, le périmètre, les acteurs, les étapes du circuit, les informations à collecter ainsi que les attentes à l'égard du prestataire. Il est destiné à servir de base à la consultation d'ATOL CD et à la formalisation ultérieure d'un engagement contractuel.

### 1.1 Objet

Le document décrit :
- le besoin métier et le contexte dans lequel il s'inscrit ;
- les objectifs poursuivis et le périmètre retenu pour une première phase ;
- les acteurs concernés et la répartition de leurs responsabilités ;
- le déroulement du circuit d'arrivée d'un agent, de son déclenchement à sa clôture ;
- les informations à recueillir et les services chargés de les renseigner ;
- les notifications, les relances et les moyens de suivi de l'avancement ;
- les exigences d'authentification et de contrôle d'accès ;
- les livrables, les compétences et l'accompagnement attendus du prestataire ;
- les critères de réussite métier et les points restant à confirmer.

### 1.2 Destinataires

Ce document est établi à l'attention de la secrétaire générale de la DREETS Bourgogne-Franche-Comté, des responsables des services concernés et du prestataire ATOL CD. Il est rédigé de manière à être lisible par des responsables administratifs non spécialistes des technologies de l'information. Les éléments strictement techniques susceptibles d'intéresser la mise en œuvre seront précisés par le prestataire dans sa proposition, et non dans le présent document.

### 1.3 Comment lire ce document

Le document décrit une organisation et un besoin, sans entrer dans les choix de mise en œuvre. Il n'impose aucune technologie ni aucun outil : la solution pourra reposer sur l'adaptation d'un outil existant, sur l'évolution et le maintien de l'outil déjà utilisé, ou sur un développement spécifique, l'appréciation de la meilleure voie relevant de la proposition du prestataire. Les rubriques « Points à confirmer » rassemblent les décisions qui restent à prendre par le commanditaire avant la rédaction du contrat définitif.

## 2. Contexte et origine du besoin

### 2.1 Le besoin

L'arrivée d'un agent dans un service mobilise plusieurs intervenants : le service qui accueille l'agent, la fonction RH, la communication, la logistique et le service informatique. Chacun doit, avant la date d'arrivée, accomplir des tâches précises : préparer l'affectation et le poste de travail, commander le mobilier et le matériel informatique, ouvrir les accès aux applications métiers, anticiper la communication interne et recueillir les avis nécessaires.

Aujourd'hui, cette coordination repose largement sur des échanges informels entre services. Elle ne garantit ni l'exhaustivité des informations, ni le respect des délais, ni la traçabilité des décisions. Le besoin consiste à disposer d'un circuit unique, structuré et partagé, qui déclenche chaque intervention au bon moment et rend l'avancement visible pour tous.

### 2.2 La situation actuelle

Un premier formulaire de gestion de l'arrivée d'un agent existe déjà au sein de l'outil de dématérialisation interne de la DREETS. Il constitue une base de travail utile : il recense les informations à collecter et les services à solliciter. Ce formulaire doit toutefois être complété, clarifié et outillé afin de répondre pleinement au besoin, notamment en matière de suivi des délais, de lisibilité des rubriques et de réutilisabilité.

Le présent cahier des charges ne présuppose pas que ce formulaire doive être conservé en l'état. Il appartient au prestataire d'examiner l'existant et de proposer la solution la plus adaptée : adaptation de l'outil en place, évolution et maintien de celui-ci, ou développement d'une solution spécifique.

### 2.3 L'ambition

L'ambition du groupe de travail est de doter la DREETS d'un circuit fiable, partagé et réutilisable, dans lequel chaque acteur sait ce qu'il doit faire et à quelle échéance, et dans lequel la direction dispose d'une vision consolidée de l'avancement. Le circuit doit pouvoir être dupliqué pour d'autres motifs (départ, mutation, congé de longue durée, etc.) sans qu'il soit nécessaire de développer de nouveau logiciel.

## 3. Objectifs

Les objectifs retenus par le groupe de travail sont les suivants.

| Réf | Objectif |
|---|---|
| O-1 | Formaliser le circuit d'arrivée d'un agent, de son déclenchement à sa clôture. |
| O-2 | Sécuriser les délais en garantissant la réalisation des tâches avant la date d'arrivée. |
| O-3 | Assurer une traçabilité complète et horodatée des sollicitations, des avis et des décisions. |
| O-4 | Rendre le modèle réutilisable pour d'autres motifs par simple adaptation, sans développement. |
| O-5 | Clarifier les rôles et responsabilités de chaque service intervenant. |
| O-6 | Offrir à la direction une visibilité consolidée sur l'état des arrivées en cours. |

## 4. Périmètre

### 4.1 Périmètre de la phase 1

La phase 1 porte uniquement sur l'arrivée d'un agent. Elle couvre la description du circuit, les informations à collecter, les acteurs et leur articulation, les notifications, les relances et les moyens de suivi de l'avancement.

| Élément | Position |
|---|---|
| Motif couvert en phase 1 | Arrivée d'un agent uniquement. |
| Extensibilité | Le modèle doit être réutilisable pour d'autres motifs (départ, mutation…) sans refonte. |
| Étendue fonctionnelle | Déclenchement, circulation entre services, collecte des informations, avis, clôture, suivi. |
| Hors périmètre de la phase 1 | Refonte des autres formulaires ; hébergement de la solution ; révision du dispositif d'authentification institutionnel. |

### 4.2 Extensibilité

Le modèle d'arrivée doit être conçu de manière à pouvoir être adapté à d'autres motifs de mouvement du personnel. Cette adaptation doit être réalisable par paramétrage ou par simple dérivation du modèle, sans développement spécifique, afin de limiter les coûts et les délais des phases ultérieures.

### 4.3 Hors périmètre

Sont hors du périmètre de la phase 1 : la refonte des autres formulaires de la DREETS, l'hébergement de la solution, ainsi que la révision du dispositif d'authentification institutionnel, lequel relève de l'infrastructure existante et est décrit au chapitre 9.

## 5. Acteurs et responsabilités

### 5.1 Les acteurs

| Acteur | Mission dans le circuit |
|---|---|
| Acteur déclencheur (à confirmer) | À l'initiative du circuit, dès la connaissance du recrutement ; saisit l'identité de l'agent et le service d'affectation destinataire. |
| Service d'affectation | Reçoit la première étape, complète et valide les informations d'affectation, d'accès, de logistique et de matériel. |
| Communication | Émet un avis au titre de la communication interne et des supports. |
| Logistique | Émet un avis au titre du mobilier et des moyens matériels. |
| ESIC | Émet un avis au titre des systèmes d'information et de la communication électronique. |
| RH | Émet un avis au titre de la gestion des ressources humaines. |
| Propriétaire du circuit | Assure le suivi de l'avancement et le contrôle des relances. |
| Direction / secrétaire générale | Dispose d'une vision consolidée et tranche les arbitrages. |

Le rôle exact de l'acteur déclencheur reste à confirmer (voir chapitre 12). La contribution du service RH est, en l'état, un avis éclairé et non une décision portant sur l'opportunité du recrutement ; ce point est également à confirmer.

### 5.2 Matrice de responsabilités

La matrice ci-dessous récapitule, pour chaque activité, la part prise par les acteurs. Elle se lit ainsi : **R** pour « réalise », **A** pour « approuve » (responsable final), **C** pour « consulté » et **I** pour « informé ».

| Activité | Déclencheur | Service d'affectation | COM | LOGIS | ESIC | RH | Propriétaire | Direction |
|---|---|---|---|---|---|---|---|---|
| Connaissance du recrutement et lancement | R/A | I | | | | C | I | |
| Saisie de l'identité et du service destinataire | R | | | | | | I | |
| Complétion et validation de l'affectation | I | R/A | I | I | I | I | C | |
| Préparation du poste, des accès, de la logistique et du matériel | | R | | | | | | |
| Avis communication | | C | R/A | | | | I | |
| Avis logistique | | C | | R/A | | | I | |
| Avis ESIC | | C | | | R/A | | I | |
| Avis RH | | C | | | | R/A | I | |
| Suivi de l'avancement, relances et clôture | C | I | I | I | I | I | R/A | C |
| Rendu de la vision consolidée à la direction | | | | | | | I | A |

## 6. Le circuit d'arrivée d'un agent

### 6.1 Vue d'ensemble

Le circuit se déroule en quatre temps : un déclenchement amont, une étape de complétion et de validation par le service d'affectation, une phase d'avis émis en parallèle par quatre services, puis une clôture. Des mécanismes transverses — notifications, relances et suivi de l'avancement — accompagnent chaque transition.

```
   ACTEUR DÉCLENCHEUR
   saisit l'identité de l'agent et le service d'affectation destinataire
                              │
                              ▼
        ┌───────────────────────────────────────────┐
        │  SOUMISSION                                │
        │  → courriel de confirmation                │
        │  → courriel de sollicitation du service    │
        └───────────────────────┬───────────────────┘
                                ▼
   ┌──────────────────────────────────────────────────────────┐
   │ ÉTAPE 1 — Service d'affectation                           │
   │ complète et valide : affectation, parapheur, accès,        │
   │ logistique, matériel informatique                          │
   │ décision : valider / refuser                               │
   └───────────────────────────┬──────────────────────────────┘
                               ▼
   ┌──────────────────────────────────────────────────────────┐
   │ ÉTAPE 2 — quatre avis en parallèle                        │
   │   Communication  ·  Logistique  ·  ESIC  ·  RH             │
   │ chacune : avis écrit et décision valider / refuser         │
   └───────────────────────────┬──────────────────────────────┘
                               ▼
        ┌───────────────────────────────────────────┐
        │  CLÔTURE                                   │
        │  statut « validé » ou « refusé »           │
        │  → courriel de notification de décision    │
        └───────────────────────────────────────────┘
```

### 6.2 Déclenchement

Le circuit est déclenché par un acteur habilité dès qu'il a connaissance du recrutement. Cet acteur saisit l'identité de l'agent concerné, une adresse de contact personnelle et le service d'affectation destinataire. La soumission du formulaire crée une demande au statut « en cours » et adresse un courriel de confirmation à son auteur.

L'identité de l'acteur déclencheur (service RH, service d'affectation ou gestionnaire RH centralisant les demandes) reste à confirmer ; ce choix détermine à la fois les habilitations et le point de départ du délai.

### 6.3 Étape 1 — service d'affectation

La première étape est adressée au service d'affectation. Ce service complète et valide l'ensemble des informations opérationnelles : affectation (pôle, direction ou service, fonction, date du mouvement, localisation), parapheur électronique, accès informatiques, logistique et matériel informatique. Il se prononce ensuite par une décision de validation ou de refus. Aucune autre étape n'est ouverte tant que cette première étape n'a pas été franchie.

La sollicitation est adressée à la boîte fonctionnelle du service d'affectation désignée par le déclencheur. Une variante consistant à solliciter également le responsable hiérarchique de l'agent reste à confirmer.

### 6.4 Étape 2 — avis en parallèle

Une fois l'étape 1 franchie, quatre services sont sollicités simultanément : la communication, la logistique, le service ESIC et le service RH. Chacun saisit un avis écrit et se prononce. Les quatre avis sont recueillis en parallèle, ce qui évite qu'un service retarde les autres.

La nature exacte de cette étape — avis consultatifs recueillis en parallèle, avis bloquants pour la clôture, ou avis rendus successivement — reste à confirmer. Elle conditionne les règles de clôture et le calcul des délais.

### 6.5 Clôture

Lorsque toutes les étapes sont favorables, la demande passe au statut « validé » et un courriel de notification de décision est adressé aux parties concernées. En cas de refus d'une étape, la demande passe au statut « refusé » ; l'événement est tracé et notifié.

### 6.6 Boîtes fonctionnelles sollicitées

Chaque étape est adressée à la boîte fonctionnelle du service concerné, afin que la sollicitation soit traitée collectivement et ne dépende pas de la disponibilité d'une personne. Les boîtes sollicitées sont les suivantes.

| Service | Boîte fonctionnelle |
|---|---|
| Communication | dreets-bfc.communication@dreets.gouv.fr |
| Logistique | dreets-bfc.logis@dreets.gouv.fr |
| ESIC | dreets-bfc.esic@dreets.gouv.fr |
| RH | dreets-bfc.rh@dreets.gouv.fr |

L'adresse du service d'affectation n'est pas figée : elle est désignée par l'acteur déclencheur au moment de la soumission, ce qui permet de traiter des affectations relevant de services différents.

## 7. Informations collectées

Les informations recueillies par le circuit sont réparties en sept rubriques. Pour chaque information sont précisés l'intitulé, le service chargé de la renseigner et la finalité poursuivie. Le caractère obligatoire de chaque information reste à confirmer par le commanditaire : il doit être arrêté avant la mise en œuvre, car il conditionne la possibilité de soumettre ou de valider une demande.

### 7.1 Identité de l'agent

Cette rubrique est renseignée par l'acteur déclencheur.

| Intitulé | Renseigné par | Finalité | Obligatoire |
|---|---|---|---|
| Nom | Acteur déclencheur | Identifier l'agent concerné. | À confirmer |
| Prénom | Acteur déclencheur | Identifier l'agent concerné. | À confirmer |
| Messagerie personnelle | Acteur déclencheur | Permettre la prise de contact avec l'agent. | À confirmer |
| Courriel du service d'affectation | Acteur déclencheur | Désigner le destinataire de la première étape. | À confirmer |

La collecte de la messagerie personnelle doit être justifiée et limitée à la finalité de contact ; son usage devra être conforme aux règles applicables à la protection des données personnelles.

### 7.2 Affectation

Cette rubrique est renseignée par le service d'affectation.

| Intitulé | Renseigné par | Finalité | Obligatoire |
|---|---|---|---|
| Pôle | Service d'affectation | Situer l'agent dans l'organisation. | À confirmer |
| Direction / Service | Service d'affectation | Préciser le rattachement hiérarchique. | À confirmer |
| Fonction exercée | Service d'affectation | Décrire le poste tenu. | À confirmer |
| Date du mouvement | Service d'affectation | Fixer la date d'arrivée et servir de repère aux délais. | À confirmer |
| Localisation | Service d'affectation | Préciser le site ou le bureau d'affectation. | À confirmer |

### 7.3 Parapheur électronique

Cette rubrique est renseignée par le service d'affectation.

| Intitulé | Renseigné par | Finalité | Obligatoire |
|---|---|---|---|
| Niveau dans le parapheur numérique | Service d'affectation | Déterminer le rôle de l'agent dans le circuit de validation (chef de service, secrétaire, collaborateur). | À confirmer |
| Implication dans les processus du parapheur électronique | Service d'affectation | Préciser les droits et responsabilités attendus. | À confirmer |

### 7.4 Accès informatiques

Cette rubrique est renseignée par le service d'affectation.

| Intitulé | Renseigné par | Finalité | Obligatoire |
|---|---|---|---|
| KELIO (gestion du temps) | Service d'affectation | Ouvrir l'accès à la gestion des temps et activités. | À confirmer |
| Applications métiers | Service d'affectation | Ouvrir l'accès aux applications nécessaires au poste. | À confirmer |
| Messagerie institutionnelle | Service d'affectation | Attribuer une boîte de messagerie professionnelle. | À confirmer |
| Accès répertoires | Service d'affectation | Ouvrir les droits sur les espaces partagés. | À confirmer |

### 7.5 Logistique

Cette rubrique est renseignée par le service d'affectation.

| Intitulé | Renseigné par | Finalité | Obligatoire |
|---|---|---|---|
| Mobilier demandé | Service d'affectation | Préparer le mobilier nécessaire à l'agent. | À confirmer |
| Clé machine à boissons | Service d'affectation | Prévoir les moyens d'accueil matériels. | À confirmer |
| Précisions mobilier | Service d'affectation | Apporter toute précision utile à la logistique. | À confirmer |

### 7.6 Matériel informatique

Cette rubrique est renseignée par le service d'affectation.

| Intitulé | Renseigné par | Finalité | Obligatoire |
|---|---|---|---|
| Téléphone | Service d'affectation | Attribuer un moyen de téléphonie (aucun, fixe, portable). | À confirmer |
| Badge | Service d'affectation | Prévoir le contrôle d'accès aux locaux. | À confirmer |
| Écran | Service d'affectation | Équiper le poste de travail. | À confirmer |
| Casque | Service d'affectation | Équiper l'agent pour les échanges téléphoniques. | À confirmer |
| Station d'accueil | Service d'affectation | Permettre l'usage nomade du poste. | À confirmer |
| Webcam | Service d'affectation | Permettre les visioconférences. | À confirmer |
| Ordinateur portable | Service d'affectation | Équiper l'agent en informatique mobile. | À confirmer |
| Autres équipements | Service d'affectation | Recueillir tout besoin complémentaire. | À confirmer |

### 7.7 Avis des services

Cette rubrique est renseignée par les services sollicités à l'étape 2.

| Intitulé | Renseigné par | Finalité | Obligatoire |
|---|---|---|---|
| Avis communication | Communication | Formaliser la position du service communication. | À confirmer |
| Avis logistique | Logistique | Formaliser la position du service logistique. | À confirmer |
| Avis ESIC | ESIC | Formaliser la position du service informatique et communication électronique. | À confirmer |
| Avis RH | RH | Formaliser la position du service des ressources humaines. | À confirmer |

## 8. Notifications, relances et suivi de l'avancement

### 8.1 Notifications

Le circuit adresse des courriels aux acteurs à chacune de ses étapes.

| Notification | Moment d'envoi | Destinataire |
|---|---|---|
| Confirmation de soumission | À la soumission du formulaire | Auteur de la demande |
| Sollicitation de validation | À l'ouverture d'une étape | Service chargé de l'étape |
| Notification de décision | À la clôture (validation ou refus) | Parties concernées |

Les courriels rappellent l'objet de la sollicitation et conduisent directement à l'étape concernée. Ils sont adressés aux boîtes fonctionnelles afin d'assurer la continuité de service.

### 8.2 Relances

Lorsqu'une étape n'est pas traitée dans le délai prévu, des relances automatiques sont adressées au service concerné. Le rythme des relances et le nombre maximal de relances sont paramétrables. Le propriétaire du circuit peut suivre et, le cas échéant, ajuster ce dispositif.

### 8.3 Suivi de l'avancement, visibilité sur les étapes, alertes, relances et indicateurs

Le suivi de l'avancement, la visibilité sur les étapes, les alertes, les relances et les indicateurs constituent une attente centrale du besoin. Le dispositif doit permettre, à tout moment :

- de connaître l'état d'avancement de chaque arrivée et l'étape en cours ;
- d'identifier les étapes non traitées et les services à relancer ;
- d'alerter les intéressés à l'approche de la date d'arrivée, sur la base de la date du mouvement ;
- de produire des indicateurs consolidés, tels que le nombre d'arrivées en cours, le délai moyen de traitement ou la part de demandes clôturées avant la date d'arrivée.

Le repère temporel du suivi est la date d'arrivée de l'agent. La mise en œuvre effective des alertes à l'approche de cette date suppose que ce repère soit renseigné et que les destinataires des alertes soient définis ; ce point reste à confirmer.

## 9. Authentification et contrôle d'accès

L'authentification établit l'identité de la personne qui accède à l'outil. Elle conditionne l'ensemble du circuit : elle détermine l'auteur d'une demande, les services appelés à se prononcer, les personnes habilitées à consulter les dossiers, ainsi que la traçabilité des opérations.

### 9.1 Fonctionnement actuel

L'outil est hébergé sur un serveur web institutionnel, intégré à l'environnement Windows de l'établissement. L'accès est protégé par l'authentification intégrée au système d'information : le poste de travail, le serveur et l'annuaire de l'établissement échangent automatiquement les informations nécessaires à la vérification de l'identité, sans que l'agent ait à saisir son mot de passe dans l'outil. L'agent déjà connecté à son poste ouvre l'outil sans ressaisir d'identifiant ni de mot de passe.

| Élément | Fonctionnement observé |
|---|---|
| Serveur | Serveur web institutionnel intégré à l'environnement Windows. |
| Authentification | Intégrée au système d'information, négociée automatiquement entre le poste, l'annuaire et le serveur. |
| Identité transmise | Identité Windows de l'agent, transmise à l'outil par le serveur. |
| Expérience de l'agent | Accès sans ressaisie des identifiants pour l'agent déjà connecté à son poste. |
| Comptes applicatifs | Aucun compte ni mot de passe propres à l'outil. |

### 9.2 Une authentification intégrée et transparente

Le groupe de travail privilégie une authentification intégrée et transparente : l'outil ne gère ni comptes, ni mots de passe, ni écran de connexion ou de déconnexion propres à l'application. L'identité déjà établie par le poste de travail et l'annuaire de l'établissement fait foi. Idéalement, l'outil s'appuie sur une identification unique, valable pour l'ensemble des applications de l'établissement, afin que l'agent n'ait à mémoriser qu'un seul jeu d'identifiants.

### 9.3 Exigences de sécurité

- L'habilitation est distincte de l'authentification : être identifié ne confère aucun droit par défaut. Les droits d'accès sont attribués nommément, selon le principe du moindre privilège, chacun ne disposant que des droits strictement nécessaires à sa mission.
- En l'absence d'identité reconnue, ou lorsque l'identité présentée n'est pas autorisée, l'accès est refusé de manière explicite ; aucune donnée n'est traitée partiellement.
- L'outil ne se fie jamais à une identité transmise par le navigateur : seule l'identité fournie par le dispositif d'authentification de l'établissement fait foi.
- Toute opération est inscrite au journal des opérations (auteur, date et heure, action, étape concernée).
- Toute modification des droits d'accès (attribution, modification ou retrait) est journalisée et traçable.

### 9.4 Situations particulières

Certaines situations sortent du cadre de l'authentification intégrée et méritent une décision. Le principe reste constant : prévoir soit une solution d'annuaire couvrant la situation, soit un parcours exceptionnel expressément validé, sans jamais affaiblir le niveau de contrôle.

| Situation | Réponse à arbitrer |
|---|---|
| Poste ou session hors du domaine de l'établissement | Refus par défaut, sauf parcours exceptionnel validé. |
| Accès à distance ou en télétravail | Fédération avec l'annuaire ou accès par le réseau sécurisé de l'établissement. |
| Intervenant prestataire ou externe | Compte nominatif temporaire, à droits minimaux et limité dans le temps. |
| Indisponibilité du dispositif d'authentification | Mécanisme de secours documenté, activé et journalisé, à durée limitée. |

Les mécanismes de secours éventuels restent nominatifs, limités dans le temps et journalisés ; ils sont validés au préalable et ne doivent en aucun cas affaiblir le niveau de contrôle d'accès.

## 10. Livrables attendus du prestataire

Le présent cahier des charges ne réduit pas la demande au paramétrage de l'outil actuellement utilisé. Il appartient à ATOL CD d'analyser l'existant, de proposer une ou plusieurs solutions et de les comparer objectivement. Les livrables attendus sont les suivants.

### 10.1 Analyse de l'existant et des besoins

Le prestataire examine l'outil et le formulaire existants, les besoins des services, les contraintes d'exploitation et les attentes exprimées au présent document. Cette analyse est restituée dans un document de synthèse, validé avec le groupe de travail, préalablement à toute proposition de solution.

### 10.2 Scénarios de solution et comparaison

Le prestataire présente plusieurs scénarios de solution et les compare. Les scénarios à examiner comprennent au minimum :
- l'adaptation d'un outil existant, éventuellement différent de l'outil en place ;
- l'évolution et le maintien de l'outil actuellement utilisé par la DREETS ;
- le développement d'une solution spécifique.

Pour chaque scénario, le prestataire expose les avantages, les limites, les coûts, les délais, les impacts sur l'exploitation et les conditions de réversibilité. La comparaison doit permettre au commanditaire de choisir en connaissance de cause, et non de se voir proposer une solution unique.

### 10.3 Démonstrateur

Lorsque cela est utile, le prestataire présente un démonstrateur ou une maquette permettant de visualiser le parcours proposé pour chaque acteur, avant tout engagement de réalisation.

### 10.4 Réalisation et intégration

Le prestataire réalise la solution retenue, l'intègre dans l'environnement de la DREETS et la met en service. Cette étape comprend la configuration des étapes, des informations et des notifications, ainsi que les développements éventuels, les tests et le déploiement.

### 10.5 Documentation

Le prestataire fournit une documentation à destination des utilisateurs et des administrateurs, permettant de mettre en œuvre, d'exploiter et d'adapter le circuit. La documentation doit notamment décrire la procédure de duplication du modèle pour un autre motif.

### 10.6 Accompagnement et transfert de compétences

Le prestataire accompagne la prise en main par les services : formation des acteurs, aide au démarrage et conduite du changement. Il assure un transfert de compétences permettant à la DREETS ou à son exploitant de faire évoluer le circuit de manière autonome pour les adaptations courantes.

### 10.7 Maintenance et support

Le prestataire précise les modalités de maintenance (corrective et évolutive) et de support, les niveaux de service proposés, les horaires et les modalités de prise en charge des incidents. Il distingue clairement ce qui relève du support courant de ce qui relève d'une évolution facturée.

### 10.8 Coûts et délais

Le prestataire fournit un chiffrage détaillé et un planning de réalisation. Le chiffrage distingue l'analyse, la réalisation, l'intégration, la documentation, l'accompagnement, la maintenance et le support. Le planning identifie les jalons, les points de validation et les engagements réciproques du commanditaire et du prestataire.

## 11. Critères de réussite métier

Les critères ci-dessous décrivent le résultat attendu du point de vue métier. Ils permettent d'apprécier, après la mise en service, si le besoin est satisfait.

### 11.1 Critères de réussite

| Réf | Critère de réussite |
|---|---|
| R-01 | L'agent dispose, à sa date d'arrivée, de son poste de travail, de ses accès et de son matériel. |
| R-02 | Chaque service est sollicité au bon moment et son avis est recueilli et tracé. |
| R-03 | Le circuit est déclenché dès la connaissance du recrutement, sans ressaisie multiple. |
| R-04 | L'avancement de chaque arrivée est visible à tout moment par les acteurs concernés. |
| R-05 | Les échéances sont respectées ou, à défaut, les retards sont détectés et relancés. |
| R-06 | Les informations recueillies sont fiables, complètes et suffisantes. |
| R-07 | L'accès à l'outil est sécurisé et transparent pour l'agent, sans compte supplémentaire. |
| R-08 | Le modèle est réutilisable pour un autre motif par simple adaptation. |
| R-09 | Les acteurs sont accompagnés et disposent d'une documentation utilisable. |

### 11.2 Indicateurs de pilotage

Pour mesurer la réussite, l'outil doit permettre de produire, sans traitement manuel lourd, les indicateurs suivants : nombre d'arrivées en cours, délai moyen de traitement, part des demandes clôturées avant la date d'arrivée, nombre de relances émises et nombre d'étapes en retard.

## 12. Points à confirmer

Les décisions suivantes restent à prendre par le commanditaire avant la rédaction du contrat définitif.

| Réf | Point à confirmer | Enjeu |
|---|---|---|
| P-01 | Quel est l'acteur déclencheur du circuit (service RH, service d'affectation ou gestionnaire RH) ? | Habilitations, point de départ du délai. |
| P-02 | L'étape 1 est-elle adressée à la seule boîte fonctionnelle du service d'affectation, ou également au responsable hiérarchique ? | Qui complète l'affectation. |
| P-03 | Les avis de l'étape 2 sont-ils purement consultatifs, bloquants pour la clôture, ou rendus successivement ? | Conditions de clôture, délais. |
| P-04 | Le rôle du service RH est-il un simple avis ou une validation décisionnelle ? | Portée décisionnelle. |
| P-05 | Le suivi des délais s'appuie-t-il sur la date du mouvement ? | Alertes avant la date d'arrivée. |
| P-06 | Quelles informations sont réellement obligatoires ? | Possibilité de soumettre ou de valider une demande. |
| P-07 | Comment traiter les accès hors domaine, à distance, des prestataires et en cas d'indisponibilité de l'authentification ? | Périmètre des personnes autorisées, continuité de service. |

## Annexe A. Glossaire

| Terme | Définition |
|---|---|
| Circuit (workflow) | Enchaînement organisé des tâches et des validations entre plusieurs acteurs. |
| Étape | Moment du circuit au cours duquel un service complète des informations ou rend un avis. |
| Avis | Position écrite d'un service consulté sur un dossier. |
| Clôture | Achèvement du circuit, par validation ou par refus. |
| Boîte fonctionnelle | Adresse de messagerie partagée par un service, indépendante d'une personne. |
| Authentification intégrée | Vérification de l'identité reposant sur le poste de travail et l'annuaire de l'établissement. |
| Réversibilité | Capacité à changer de prestataire ou de solution sans perte de données ni de service. |

## Annexe B. Représentation des étapes

Le tableau ci-dessous récapitule les étapes du circuit et les services concernés.

| Ordre | Étape | Service sollicité | Décision attendue |
|---|---|---|---|
| 1 | Validation de l'affectation | Service d'affectation | Valider ou refuser |
| 2 | Avis communication | Communication | Avis + valider ou refuser |
| 2 | Avis logistique | Logistique | Avis + valider ou refuser |
| 2 | Avis ESIC | ESIC | Avis + valider ou refuser |
| 2 | Avis RH | RH | Avis + valider ou refuser |

Les étapes d'ordre 2 sont ouvertes simultanément dès que l'étape 1 est franchie.