# Journal des versions

Toutes les modifications notables de ce projet sont documentées ici.

## 0.6.0-alpha.1 - 2026-10-11

Cycle Car Rental complet. Détail : `docs/25_Retour_croquis_depot_et_facture_0.6.0.md`.

### Ajouté

- Croquis tactile des dommages à la remise et au retour, imprimé dans le contrat.
- Écran de retour : kilométrage contrôlé, carburant et accessoires comparés au départ, dommages, photos, signature du client.
- Frais du retour appliqués seulement sur case cochée : nettoyage 20 USD, kilométrage supplémentaire selon le contrat, autres frais réservés à l'administration.
- Règlement du dépôt de garantie par l'administration : libération ou retenue motivée.
- Facture numérotée sur huit chiffres, contenu figé, PDF envoyé au client (sans plaque ni permis).
- Permissions `rental.deposits.settle` et `rental.invoices.issue`.

### Corrigé

- Courriels : l'image est présentée comme une illustration de catégorie, jamais comme la photo du véhicule.
## 0.5.1-alpha.1 - 2026-10-08

### Corrigé

- Mise en circulation : les conditions du contrat saisies après la connexion n'étaient pas reconnues. L'écran relit maintenant la configuration de la société à l'ouverture, et l'enregistrement des conditions met à jour la société active immédiatement.

## 0.5.0-alpha.1 - 2026-10-10

Livraison 2 des retours de recette. Détail : `docs/24_Permis_fiche_de_sortie_signatures_et_contrat_0.5.0.md`.

### Ajouté

- Écran de mise en circulation en trois étapes : permis, fiche de sortie, signatures. Le bouton final reste grisé tant qu'un élément manque.
- Permis international : pays émetteur (ISO 3166, en français), État ou province seulement pour les pays concernés (États-Unis, Canada, Mexique, Australie, Brésil, Inde), photos recto et verso obligatoires, conducteur additionnel.
- Fiche de sortie : kilométrage (jamais inférieur au dernier relevé, met à jour la fiche véhicule), carburant, accessoires, dommages et jusqu'à 12 photos.
- Signatures tactiles du locataire et du loueur, enregistrées en PNG privé avec empreinte SHA-256.
- Contrat PDF A4 signé, construit à partir d'une copie figée (loueur, conditions, véhicule), stocké en privé et rattaché définitivement à la réservation. Reconstruction possible depuis le détail si la création échoue.
- Conditions du contrat de location saisies par le propriétaire dans la configuration de la société. Une remise est refusée tant qu'elles sont vides.
- Détail de la réservation : contrat, fiche de sortie et permis, selon les droits.

### Sécurité

- Le contrat n'est pas envoyé par courriel automatiquement : il contient la plaque et le numéro de permis.
- Photos du permis visibles seulement avec `rental.documents.sensitive`.

## 0.4.0-alpha.1 - 2026-10-09

Livraison 1 des retours de recette. Détail : `docs/23_Reservations_paiements_et_design_0.4.0.md`.

### Modifié

- Design Microsoft Fluent 2 : Segoe UI, échelle typographique standard, chiffres non étirés, dialogues et espacements alignés sur Windows et Microsoft 365. La police embarquée Archivo est retirée.
- Le tarif de réservation vient de la fiche véhicule. Seul l'administrateur peut le modifier (`rental.reservations.override_rate`), contrôle appliqué par le serveur.
- Champs obligatoires marqués d'un astérisque, liste des éléments manquants et bouton d'enregistrement grisé tant que la saisie est incomplète.
- Durée minimale de deux jours affichée en avertissement ; prix du kilomètre supplémentaire facultatif.

### Ajouté

- Modification complète d'une réservation non remise : client, coordonnées, véhicule de toute catégorie, période, lieux, tarif selon permission. Envoi et renvoi de la confirmation au client.
- Paiements en espèces USD ou HTG, par virement Sogebank avec photo ou PDF du reçu, et crédit réservé à l'administrateur ou au propriétaire (`rental.payments.credit`).
- Fiche véhicule : photo réelle, interrupteur Actif ou Inactif avec confirmation, caractéristiques du contrat (couleur, carburant, transmission, cylindrée, portes, numéro de série).
- Identité légale de la société (raison sociale, représentant, NIF, siège, téléphones) pour l'en-tête du contrat.
- Stockage privé des fichiers : volume `clientele-documents`, 10 Mo au plus, SHA-256, isolation par société, lecture selon permission.

### Corrigé

- La mise à jour des caractéristiques d'un véhicule ne vide plus son numéro de série lorsqu'il n'est pas envoyé.

### Exploitation

- Remplacer `$HOME/deploy-preprod.sh` sur le serveur par `infra/deploy-preprod.sh` avant de déployer.

## 0.3.0-alpha.1 - 2026-10-08

### Modifié

- L’interface est réorganisée en pages, composants et services : `App.vue` (4 492 lignes) et `styles.css` (2 764 lignes) sont remplacés par 58 fichiers par domaine, le plus long faisant 633 lignes. Les routes API, les règles métier et les permissions serveur sont inchangées.
- Chaque écran a sa propre adresse : une réservation ou un véhicule peut être rouvert directement, le bouton Retour du téléphone fonctionne et les filtres de liste sont conservés.
- Les permissions sont vérifiées à la navigation : un écran non autorisé n’est plus atteignable depuis l’interface.
- Nouveau système visuel mobile d’abord : onglets en bas sur téléphone, rail de navigation sur grand écran, numéros, plaques, heures et montants en grand format, police embarquée pour un rendu identique hors ligne.

### Corrigé (P0 Car Rental)

- Nouvelle réservation : les véhicules disponibles s’affichent dès l’ouverture, dans le même panneau que les dates, et se mettent à jour à chaque changement. Toutes les catégories sont proposées par défaut.
- Rendu mobile : le résumé et le bouton d’enregistrement restent visibles au pouce ; les résultats ne tombent plus en bas de page.
- Toute réservation et tout véhicule affichés dans une liste, le planning ou un détail sont ouvrables.
- Mise en circulation : le calcul de la location, le montant approuvé, le dépôt requis et le dépôt retenu sont affichés sans ressaisie. L’action finale reste désactivée tant qu’une exigence manque, et chaque exigence manquante est nommée.
- Fiche véhicule en sections courtes : état opérationnel tactile avec confirmation, réservations actives, documents, tarification, identité.
- Planning : grille véhicules par jour, ouverte sur le jour courant, réservations cliquables, navigation par mois.

### Ajouté

- Écran Aujourd’hui : départs du jour, retours attendus, retours en retard et état de la flotte du bureau actif.
- Tests unitaires de la PWA (dates de Cap-Haïtien, période par défaut, devises, textes), exécutés dans la CI.
- Le service worker sert l’application pour toutes les adresses, y compris hors ligne. Les réponses `/api` ne sont jamais mises en cache.

### Sécurité

- `deploy-preprod.sh` retire l’authentification au registre GHCR même si le téléchargement des images échoue.

### Non couvert, en attente d’une évolution de l’API

- Interrupteur Actif ou Inactif du véhicule, photo du permis, pays et province du permis, virement Sogebank avec pièce justificative. Voir `docs/22_Interface_modulaire_0.3.0.md`.

## 0.2.0-alpha.14 - 2026-10-08

### Ajouté

- Le véhicule exige un tarif quotidien USD et un dépôt minimum USD. Les références connues proposent les tarifs validés : Suzuki Jimny 120 USD, Suzuki Vitara 130 USD, pick-up 200 USD. Les autres modèles restent à confirmer.
- Une nouvelle réservation propose la date et l’heure actuelles à Cap-Haïtien, le retour le lendemain et le bureau actif du préposé.
- Le parcours Réservations est séparé en vue d’ensemble, nouvelle réservation et recherche. Le détail s’ouvre dans un dialogue par tâche, puis la création affiche un récapitulatif.
- La mise en circulation exige le permis du conducteur, sa vérification, un paiement de location approuvé et un dépôt USD retenu au minimum requis.
- L’approbation d’un paiement de dépôt crée une retenue de dépôt journalisée.
- Les notifications client Car Rental utilisent la marque et le modèle avec une image générique de catégorie. Elles ne contiennent pas la plaque d’immatriculation.

### Corrigé

- La règle de valeur par défaut est documentée et appliquée aux nouvelles réservations.
- Le caractère typographique U+2014 est interdit dans les fichiers versionnés. Le tiret ASCII est la seule forme utilisée pour séparer deux éléments de texte.

## 0.2.0-alpha.13 - 2026-10-08

### Corrigé

- L’adresse de site est préremplie avec `Pont Parois, Route Nationale 6` dans Configuration système.
- Le préremplissage d’une fiche véhicule sélectionne ce site lorsqu’il existe dans la société active. Aucune adresse ni aucun véhicule ne sont créés automatiquement.

## 0.2.0-alpha.12 - 2026-10-08

### Ajouté

- Catalogue de préremplissage Car Rental issu des publications publiques fournies pour la flotte Clientèle Group.
- Références visibles : Nissan Frontier `AA-85177`, Suzuki Jimny `LO-01727` et Great Wall Poer `DM-00849`, avec photo de référence versionnée.
- Fiches à vérifier avant enregistrement : Suzuki Jimny `LO-01724`, BAIC BJ40 `DM-00437` et Great Wall Poer `DM-00835`.
- La photo de référence choisie est conservée sur la fiche véhicule et renvoyée par l’API de flotte.
- Documentation de provenance, limites et recette dans `docs/21_Flotte_car_rental_references_publiques_alpha12.md`.

### Sécurité et intégrité

- Les véhicules du catalogue ne sont jamais créés automatiquement : l’adresse autorisée et le kilométrage relevé restent obligatoires.
- Les années, VIN, documents, kilométrages et états opérationnels ne sont pas déduits des publications publiques.
- L’API n’accepte qu’une clé d’image présente dans la liste versionnée. Une URL ou un fichier arbitraire ne peut pas être injecté dans une fiche véhicule.

## 0.2.0-alpha.11 - 2026-10-08

### Ajouté

- Gestion opérationnelle des réservations Car Rental : recherche par référence ou plaque, modification avant remise, mise en circulation, prolongation, retour et annulation.
- Le planning et la liste des réservations chargent le mois courant par défaut. Pendant les sept derniers jours du mois, la vue inclut aussi les sept premiers jours du mois suivant.
- Courriels client Car Rental au même format que le code de sécurité : confirmation de réservation, remise du véhicule, prolongation et retour.
- Service de notification de facture prêt à joindre un PDF réel et validé. Un contrat signé peut être joint par son module lorsqu’il existe réellement.
- Gestion complète d’un utilisateur Car Rental : modification du rôle et des adresses, désactivation/réactivation locale, réinitialisation du mot de passe et suppression définitive après double confirmation.
- Courriel de création de compte envoyé au nouvel utilisateur, sans mot de passe dans le message ou le journal.

### Corrigé

- Les trois types de plaques Car Rental sont `Démonstration`, `Location` et `Normale`. Les anciennes valeurs techniques sont converties vers `Normale` par migration.
- Le calendrier mobile utilise la date Cap-Haïtien lors du calcul de sa période par défaut.
- Les dialogues de confirmation sont affichés pour les actions qui modifient une réservation ou un compte.

### Sécurité

- Une prolongation qui chevauche une réservation future est refusée sans révéler l’identité du client concerné.
- Un retour anticipé conserve le tarif, la date contractuelle et les paiements existants. Aucun remboursement n’est généré automatiquement.
- La suppression définitive est refusée pour le propriétaire système ou pour un compte rattaché à une autre société. Les transactions et le journal d’audit ne sont pas supprimés.
- Les courriels client ne sont journalisés qu’avec l’état de l’envoi et le nombre de pièces jointes, sans courriel, téléphone, document ou contenu PDF dans l’audit.

## 0.2.0-alpha.10 - 2026-10-08

### Ajouté

- La plaque d’immatriculation en cours est désormais l’identifiant unique visible d’un véhicule Car Rental. Aucun code interne distinct n’est demandé.
- Une plaque `Démonstration`, `Location` ou `Normale` peut être remplacée. Le changement est journalisé et l’ancienne plaque reste dans l’historique du véhicule.
- Suivi simple des papiers de la flotte : immatriculation, assurance OAVCT et permis de vitres teintées. Les échéances OAVCT et vitres teintées sont affichées comme à jour, proche, expirée ou non renseignée.
- Création d’utilisateurs Car Rental depuis Configuration système : profil, portée de toutes les adresses ou d’adresses sélectionnées, courriel personnel, mot de passe initial et 2FA par courriel à la première connexion.
- L’adresse du bureau sélectionné est conservée comme lieu de départ par défaut. Les frais de prise en charge et de retour à l’Aéroport International du Cap-Haïtien peuvent être sélectionnés séparément, à 20 USD chacun.
- Documentation fonctionnelle des véhicules, papiers et utilisateurs dans `docs/19_Vehicules_documents_et_utilisateurs_alpha10.md`.

### Interface

- Le menu propriétaire utilise le libellé `Configuration système`.
- La gestion de flotte affiche la plaque, le type de plaque et l’état des documents. Les contrôles de gestion des papiers restent accessibles au toucher après sélection d’un véhicule.
- Le formulaire d’utilisateur affiche la règle complète du mot de passe avant l’enregistrement.

### Sécurité

- Les papiers et l’historique d’immatriculation sont isolés par société avec Row Level Security PostgreSQL.
- Les références de papiers, les courriels et les mots de passe sont exclus des métadonnées d’audit.

## 0.2.0-alpha.9 - 2026-10-08

### Corrigé

- La création d’une société accepte une saisie lisible dans le code interne : `Clientèle Rent a Car` est normalisé en `CLIENTELE-RENT-A-CAR` avant validation.
- Les codes de société, d’adresse et de caisse sont également normalisés par l’API ; les appels hors interface suivent donc la même règle.
- Les réponses de validation sont affichées en français près du champ concerné. La clé technique `validation.regex` n’est plus présentée à l’utilisateur.

### Interface

- La configuration système utilise des actions courtes et explicites : ajouter une société, une adresse ou une caisse.
- Les champs de configuration ont désormais un libellé associé, un identifiant, une aide ciblée et un état d’erreur accessible.
- Les options d’impression automatique et d’écran client sont retirées du formulaire de caisse tant que les dispositifs ne sont pas intégrés.
- Les titres des cartes de configuration sont réduits pour conserver la priorité sur les champs et les actions, sur ordinateur comme sur mobile.

## 0.2.0-alpha.8 - 2026-10-08

### Corrigé

- La disponibilité Car Rental retourne exclusivement les véhicules actifs dont l’état est `Disponible`. Les véhicules en préparation, lavage, garage ou en circulation ne peuvent plus être proposés par erreur.
- Le code interne d’un véhicule est normalisé et contrôlé : lettres majuscules, chiffres, tiret et soulignement uniquement.

### Interface

- Les titres de connexion, de sélection de société et de configuration globale sont réduits pour privilégier la tâche et l’action principale.
- Le standard d’interface précise la densité attendue des écrans et la priorité du formulaire sur mobile.

## 0.2.0-alpha.7 - 2026-10-08

### Ajouté

- Espace de configuration globale réservé au propriétaire du système : création explicite des sociétés, de leurs adresses opérationnelles et de leurs caisses.
- Attribution automatique du propriétaire créateur à la nouvelle société, avec rôle local `owner`, portée de toutes les adresses et permissions explicites.
- Préparation par caisse des indicateurs d’impression automatique des reçus et d’écran client, sans déclarer les pilotes Epson ni l’impression comme opérationnels.
- API protégée par un contrôle de rôle système distinct des rôles métier de société.
- Journalisation des créations de société, d’adresse et de caisse, sans adresse complète dans les métadonnées d’audit.
- Tests d’autorisation propriétaire, d’isolement d’adresse entre sociétés et de création de caisse.

### Sécurité

- La lecture globale des adresses et caisses s’exécute dans le contexte RLS de chaque société ; aucun contournement de l’isolation PostgreSQL n’est ajouté.
- Les utilisateurs non propriétaires reçoivent un refus explicite et journalisé avant toute lecture ou modification de la configuration globale.

### Interface

- Écran de connexion et choix de société simplifiés : titres courts, texte fonctionnel, aucune promesse ou formule décorative.
- Le menu Car Rental n’affiche que les fonctions actuellement actives ; les inspections, dépôts et rapports ne sont plus présentés comme des écrans utilisables.
- Les actions importantes restent des boutons tactiles d’au moins 48 px, placés après les champs qu’elles valident.

### Limites connues

- La gestion des utilisateurs par société, les imprimantes réelles, les dispositifs kiosque et les écrans clients restent à réaliser.
- Aucune société, adresse ou caisse réelle n’est créée par le déploiement : le propriétaire les saisit après validation de leurs informations.

## 0.2.0-alpha.6 - 2026-10-08

### Ajouté

- Gestion des véhicules Car Rental par société et adresse autorisée : code interne, catégorie, kilométrage et état opérationnel.
- Planning par période qui affiche les réservations actives et l’état de la flotte, sans données clients.
- Permissions distinctes pour consulter les véhicules, gérer les véhicules et consulter le planning.
- Contraintes par société pour le code interne, l’immatriculation et le VIN, avec normalisation des identifiants.
- Journalisation de la création d’un véhicule et de chaque changement d’état.
- Tests d’accès par adresse, de non-divulgation dans le planning et d’unicité des identifiants.

### Modifié

- L’écran de connexion mobile présente le formulaire avant le contenu descriptif et utilise des commandes tactiles d’au moins 48 px.
- Les textes de connexion et de vérification utilisent un vocabulaire direct et explicite en français.

### Corrigé

- Les fichiers de déploiement utilisent des noms Traefik statiques et distincts pour la production et la préproduction.
- Les services applicatifs disposent d’un réseau de sortie séparé pour le DNS et le SMTP, sans port entrant publié.

### Limites connues

- Le planning est une vue opérationnelle par période. Le contrat, le check-out, les inspections, le dépôt, le retour, les reçus et l’impression thermique restent à réaliser.

## 0.2.0-alpha.5 - 2026-10-08

### Corrigé

- Rendu du courriel de code de connexion : le modèle d’authentification par courriel est envoyé correctement.

### Vérifié

- Préproduction : routage HTTPS, santé API, PostgreSQL, Redis, SMTP et connexion propriétaire avec code envoyé par courriel.
- La référence technique validée est conservée dans `baseline/0.2.0-alpha.5-preprod` et documentée dans `docs/15_Registre_validation_preproduction_0_2_0_alpha_5.md`.

## 0.2.0-alpha.4 - 2026-10-07

### Ajouté

- Premier périmètre API Car Rental : véhicules SUV, Mid SUV et Pick-up, disponibilité par adresse, réservation concurrente protégée et numéro à huit chiffres affiché au format `0000 0001`.
- Premier écran PWA de réservation : choix obligatoire de la société et de l’adresse autorisée, période, catégorie, véhicule disponible, client, trajet, devise et kilométrage.
- Calendrier métier basé sur les réservations actives : un véhicule réservé ou en circulation ne peut pas être alloué sur une période qui se chevauche.
- Prise en charge et drop-off au site, à l’Aéroport International du Cap-Haïtien ou à une adresse personnalisée.
- Profils clients locaux chiffrés par société ; l’identité maître ne conserve aucune coordonnée et ne donne aucun accès inter-sociétés.
- API de soumission de paiement Car Rental en espèces ou virement Sogebank, avec preuve hashée obligatoire pour un virement et approbation distincte.
- Schéma versionné des dépôts de garantie, inspections pré/post-location, croquis, photos hashées, signatures hashées et kilométrage.
- Tests de réservation, chevauchement, périmètre de site, virement Sogebank et absence de coordonnées bancaires dans l’audit.

### Sécurité

- Les détails de lieu personnalisés, coordonnées client, référence bancaire, notes et légendes d’inspection sont chiffrés au repos par Laravel.
- Toutes les nouvelles données de société Car Rental sont protégées par Row Level Security PostgreSQL et par permission d’API explicite.

### Limites connues

- Le contrat PDF, la capture réelle des signatures et photos, le check-out, l’inspection post-location, le calcul final de kilométrage, le workflow de retenue/libération du dépôt et l’impression sont les prochaines itérations du pilote.
- Aucune donnée client, passeport ou preuve de paiement réelle ne doit être importée avant la recette préproduction et l’activation du stockage chiffré des fichiers.

## 0.2.0-alpha.3 - 2026-10-07

### Ajouté

- Connexion API par mot de passe puis code à six chiffres envoyé au courriel personnel, valable dix minutes, à usage unique et stocké sous hash.
- Réinitialisation personnelle du mot de passe par code courriel, avec révocation de toutes les sessions existantes après succès.
- Jetons de session opaques, conservés uniquement sous empreinte SHA-256, avec durée absolue et délai d’inactivité configurables.
- Modèle d’accès par société avec rôle local, permissions explicites et périmètre de sites ; aucun rôle système ne contourne l’autorisation de société.
- Middleware de société active, corrélation de requête, refus de permission et route de contexte qui ne renvoie que les sites autorisés.
- PWA : écran de connexion, vérification en deux étapes, récupération de mot de passe, choix explicite de société et fermeture de session.
- Commande `php artisan identity:provision-owner` pour créer le premier propriétaire sans jamais mettre son mot de passe dans Git ou une commande.
- Tests de flux 2FA, révocation après réinitialisation, nettoyage des données sensibles dans l’audit et isolement entre sociétés.

### Sécurité

- Argon2id est le hachage par défaut des mots de passe.
- Le serveur refuse de délivrer un code de sécurité lorsque le transport de courriel est `log`, `array` ou un repli non sûr hors tests, afin que le code ne soit jamais écrit dans les journaux.
- Les métadonnées d’audit éliminent récursivement mots de passe, codes, jetons, courriels, téléphones, cookies et données de carte.

### Limites connues

- La préproduction reste sur `MAIL_MAILER=log` : aucun compte humain ne doit donc y être créé avant la configuration d’un SMTP transactionnel réel.
- Taux BRH, caisses, reçus, impression, appareil kiosque, réservations et données Car Rental ne sont pas encore construits.

## 0.2.0-alpha.2 - 2026-10-07

### Corrigé

- Les labels Traefik dynamiques utilisent maintenant la syntaxe liste `clé=valeur` : les noms de routeur et de service sont donc bien interpolés par Docker Compose.
- Le routeur temporaire de préproduction est une vraie solution de repli à priorité faible ; le routeur ERP de préproduction prend explicitement le dessus.
- La validation de déploiement exige désormais la réponse JSON de l'API ERP et non un simple code HTTP 200 provenant d'un service temporaire.

## 0.2.0-alpha.1 - 2026-10-07

### Ajouté

- Socle Laravel 13 versionné dans `apps/api`, avec API de santé, vérification PostgreSQL/Redis et premier contrat de démarrage sans données de production.
- Première migration Laravel pour les sociétés, sites, postes de vente, accès locaux et événements d'audit UUID.
- Isolation RLS PostgreSQL pour les sites, postes et événements d'audit, plus déclencheur empêchant la modification ou suppression du journal d'audit.
- PWA Vue 3 et TypeScript dans `apps/web`, en français, avec l’écran initial Clientèle Rent a Car, le fuseau Cap-Haïtien et une détection de connexion.
- Images Docker applicatives, Nginx/PHP-FPM, compose de build local séparé et workflow GitHub de publication GHCR.

### Limites connues

- Cette préversion ne contient ni authentification, ni 2FA, ni rôles métier, ni taux BRH, ni caisse, ni reçu, ni donnée réelle.
- Elle est réservée à la recette technique de préproduction.

### Corrigé

- L'image PHP conserve désormais les dépendances de compilation jusqu'à l'installation de l'extension Redis, puis retire les outils de build inutiles de l'image finale.
- L'image crée explicitement les répertoires Laravel requis au démarrage ; aucun dossier vide de stockage ne dépend désormais de Git.

## 0.1.6 - 2026-10-07

### Ajouté

- Déploiement Traefik versionné dans `infra/traefik`, avec HTTPS Let's Encrypt, redirection HTTP vers HTTPS, journal d'accès et tableau de bord non exposé.
- Service de vérification temporaire pour obtenir et contrôler les certificats de `erp.clientelegroup.tech` et `preprod.erp.clientelegroup.tech` avant l'arrivée de l'application.
- Script de démarrage reproductible de Traefik pour le VPS dédié.
- La caméra reste disponible sur autorisation du navigateur pour les inspections, photos et futures lectures mobiles ; elle n'est pas bloquée par la politique HTTP globale.

### Modifié

- Le routeur applicatif de production requiert désormais explicitement le résolveur de certificats Let's Encrypt.
- La documentation de déploiement référence les deux sous-domaines réels et les seules règles pare-feu nécessaires : 22, 80 et 443.

## 0.1.5 - 2026-10-07

### Modifié

- La maquette mobile affiche le format complet de date et heure : « 07 octobre 2026 · 10:38 AM », sous le libellé Cap-Haïtien, Haïti.

## 0.1.4 - 2026-10-07

### Ajouté

- Maquette interactive mobile-first versionnée dans design/wireframes_mobile_v1.html, avec écrans explicites pour Car Rental, Auto Parts, Guest House, Market, Hotel et confidentialité client.

## 0.1.3 - 2026-10-07

### Ajouté

- Référence d'identité visuelle Clientèle Group fondée sur le logo et les couleurs fournis : rouge #F70707, bleu #2222E6, noir #0E0E10 et blanc #FEFEFE.
- Actif de logo initial dans apps/web/public/brand/clientele-group-logo.webp et guide d'expérience mobile dans docs/11_Identite_visuelle_et_experience_mobile.md.

### Modifié

- Tous les écrans doivent afficher explicitement la société, le site, l'adresse configurée du site et le point de vente ou de service ; le libellé générique « caisse » seul est interdit.
- L'interface de la première version travaille uniquement en français et n'affiche aucun sélecteur de langue.
- La ville affichée dans l'interface est désormais Cap-Haïtien, Haïti. L'identifiant technique IANA reste America/Port-au-Prince, qui couvre Haïti.

## 0.1.2 - 2026-10-07

### Modifié

- Le KVM1 est retenu pour le démarrage budgétaire, sous un profil de production léger et isolé : Ubuntu 26.04 LTS, Docker, Traefik, sauvegardes hors VPS et seuils explicites de passage au KVM2.
- L'ordre du pilote devient : Car Rental, Auto Parts et motocyclettes, Guest House, Market, puis Hotel, Bar et Restaurant.
- Les neuf caisses connues sont consignées : Car Rental 1, Auto Parts et motocyclettes 3, Market 2, Guest House 1 et Hotel 2.
- La cible d'impression est une imprimante thermique Epson TMIII de 80 mm par caisse ; le modèle exact, l'interface et le pilote restent à valider sur le matériel reçu.
- Le modèle client distingue désormais une identité maître Clientèle Group, des profils strictement locaux par société et un consentement explicite, audité et révocable pour tout partage de données.

## 0.1.1 - 2026-10-07

### Modifié

- Recommandation d'hébergement : KVM2 est le minimum retenu pour la préproduction active et le pilote. KVM1 est limité à une démonstration ou une préproduction très légère.

## 0.1.0 - 2026-10-07

### Ajouté

- Cadrage complet du progiciel Clientèle Group.
- Architecture VPS Hostinger, Docker, Traefik et PostgreSQL.
- Règles de reçus, de kiosque, de PWA hors ligne, de devises et d'audit.
- Modèle initial de données et politiques d'isolation des sociétés.
- Feuille de route, critères de recette et procédures d'exploitation.

### À faire

- Sélection du module pilote.
- Mise en œuvre du noyau applicatif.
- Création de l'environnement de préproduction.
