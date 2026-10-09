# Reprise du projet Clientèle Group ERP

Ce document est la référence de transition à lire avant toute modification. Il a été figé le 8 octobre 2026 après le déploiement de l'alpha.14 en préproduction, puis mis à jour avec les versions 0.3.0-alpha.1 (interface modulaire), 0.4.0-alpha.1 (réservations, paiements et design Fluent) 0.5.0-alpha.1 (permis, fiche de sortie, signatures et contrat), 0.6.0-alpha.1 (retour, croquis, dépôt et facture), 0.7.0-alpha.1 (taux HTG/USD et reçus), 0.7.1-alpha.1 (courriels, clients connus) et 0.8.0-alpha.1 (taux unique du groupe).

## 1. Source de vérité et état de déploiement

| Élément | Valeur |
| --- | --- |
| Dépôt | `https://github.com/moisealexdocteur/clientele-group-erp` |
| Branche de référence | `main` |
| Commit figé | `15d247765220cc7e768befc93a05cfff7c606d94` (0.6.0-alpha.1, cycle Car Rental validé en recette le 8 octobre 2026) |
| Version du dépôt | `0.8.0-alpha.1` (taux unique du groupe dans Configuration, à déployer). Branche `fix/car-rental-decoupe` en relecture : découpe Car Rental et correctifs de sécurité, sans nouvelle version |
| Dernière version déployée | `0.6.0-alpha.1` (validée) |
| Préproduction | `https://preprod.erp.clientelegroup.tech` |
| Image API et web déployée | `sha-15d2477` |
| État technique confirmé | API, PostgreSQL, Redis et routage HTTPS opérationnels |
| Environnement | Ubuntu 26.04 LTS, Hostinger KVM1, Docker, Traefik, PostgreSQL, Redis |

La préproduction est un environnement de recette. Elle n'est pas une autorisation d'utilisation opérationnelle ou de production.

Ne jamais mettre un mot de passe, un jeton GitHub, une clé SMTP, une clé SSH, un fichier `.env` ou une donnée client réelle dans ce dépôt ou dans ce document.

## 2. Procédure de livraison déjà utilisée

1. Créer une branche depuis `main`.
2. Modifier le code et la documentation française.
3. Exécuter les tests API et PWA.
4. Ouvrir une pull request vers `main`.
5. Fusionner seulement lorsque les vérifications sont réussies.
6. Attendre la publication des images GHCR avec le tag immuable `sha-<7 premiers caractères du commit de fusion>`.
7. Sur le serveur, avec l'utilisateur `clientele-deploy`, lancer :

```bash
DEPLOY_TAG=sha-<tag> "$HOME/deploy-preprod.sh" && "$HOME/fix-preprod-routing.sh"
```

8. Vérifier ensuite `https://preprod.erp.clientelegroup.tech/api/health`.

Le script demande un jeton GitHub classique ayant seulement l'autorisation `read:packages`. Ne pas le coller dans un fichier ou une conversation.

## 3. Architecture actuelle

- Frontend : PWA Vue 3, TypeScript, Vue Router et Pinia dans `apps/web`, design Microsoft Fluent 2 (Segoe UI, jetons dans `src/styles/main.css`). Un écran par fichier dans `src/views`, composants par domaine dans `src/components`, appels serveur dans `src/api`, dates et devises dans `src/lib`. Détail : `docs/22_Interface_modulaire_0.3.0.md`. Ne jamais recréer un fichier d'interface unique.
- Backend : Laravel et PHP dans `apps/api`. Car Rental : un contrôleur invocable par action dans `app/Http/Controllers/CarRental`, règles partagées dans `app/Support/CarRental` (présentation, lecture, planning, véhicules, prix, croquis, clients). Ne jamais recréer un contrôleur unique ni dépasser 500 lignes par contrôleur.
- Montants : `App\Support\Money` en centimes entiers (taux en dix-millièmes). Interdit : `(float)` sur un prix, un dépôt, un taux ou une signature. Saisie contrôlée par `App\Rules\DecimalAmount`.
- Infrastructure locale : Docker Compose et Traefik dans `infra`.
- Base de données : PostgreSQL.
- Cache, sessions et files : Redis.
- Préproduction : `/opt/clientele/erp-preprod` sur le VPS.
- Routage : Traefik et certificat HTTPS.
- CI : `.github/workflows/verification.yml` et `.github/workflows/build-images.yml`.

Les tests API pertinents sont notamment dans :

- `apps/api/tests/Feature/AuthenticationFlowTest.php`
- `apps/api/tests/Feature/CompanyContextTest.php`
- `apps/api/tests/Feature/CarRentalReservationTest.php`
- `apps/api/tests/Feature/CarRentalVehicleAndCalendarTest.php`

## 4. Règles produit non négociables

### Langue, style et ergonomie

- Interface uniquement en français. Ne pas afficher un sélecteur de langue.
- Texte court, neutre, explicite et professionnel, proche des produits Microsoft, Android ou iOS.
- Aucun texte décoratif, slogan, formulation poétique ou promesse inutile.
- Mobile-first et tactile-first. Toute action principale est facilement atteignable au pouce et utilise une cible tactile d'au moins 48 px.
- Une page ou un dialogue sert une tâche principale. Ne pas empiler création, recherche, modification, paiement et actions métier dans le même grand formulaire.
- Les actions et erreurs doivent être compréhensibles sans connaissance technique.
- Le caractère typographique U+2014 est interdit dans tout le dépôt : code, documentation, courriels, titre, menu, dialogue et script. Pour séparer deux éléments, utiliser seulement le tiret ASCII avec des espaces : `texte - texte`.

### Valeurs par défaut

- Toujours afficher une valeur métier immédiatement. L'utilisateur confirme ou la modifie.
- Ne jamais afficher une page blanche en attendant que l'utilisateur décide d'une première action utile.
- Les listes et calendriers chargent une période utile par défaut, puis l'utilisateur affine si nécessaire.
- Pour une nouvelle réservation Car Rental : prise en charge à la date et à l'heure actuelles de Cap-Haïtien, retour proposé le lendemain à la même heure, bureau actif autorisé comme lieu de prise en charge.
- Le mois courant est affiché par défaut dans le planning. Pendant les derniers jours d'un mois, inclure aussi le début du mois suivant.

### Localisation, données et accès

- Fuseau métier : `America/Port-au-Prince`, présenté à l'utilisateur comme Cap-Haïtien, Haïti.
- Format métier demandé : jour, mois, année, heure AM ou PM, fuseau de Cap-Haïtien.
- Devise : HTG et USD. Un seul taux manuel pour tout le groupe (décision du propriétaire, 0.8.0), saisi dans Configuration > Taux de change par le propriétaire ou par les utilisateurs qu'il autorise sur leur fiche (droit de compte `can_manage_exchange_rates`, pas de permission par société). Alerte, confirmation et motif sous la référence saisie (chiffre relevé à la main, par exemple publié par la BRH ; l'application ne lit pas la BRH et ce n'est pas un contrôle officiel). Un paiement dans l'autre devise conserve son taux et son équivalent.
- Les clients peuvent avoir une identité groupe commune, mais les profils, transactions, documents et accès restent limités à la société et à l'adresse autorisées. Ne jamais révéler des données inutiles d'une autre société.
- Toute opération sensible doit être journalisée avec utilisateur ou système, date et heure.
- Les données client et les documents sensibles ne doivent pas être affichés dans les listes, calendriers, courriels, journaux ou messages non autorisés.

### Sécurité et comptes

- Connexion par courriel et mot de passe, code de sécurité par courriel, réinitialisation personnelle du mot de passe, sessions révocables et journalisation existent déjà.
- Le mot de passe initial doit être expliqué avant saisie : au moins 12 caractères, majuscule, minuscule, chiffre et symbole.
- Principe figé : tous les réglages et configurations se trouvent dans le menu Configuration. Les écrans métier affichent seulement les valeurs en vigueur.
- La gestion d'utilisateur doit permettre création, consultation, modification, désactivation, réactivation, réinitialisation et suppression définitive selon les droits.
- La création d'un compte envoie un courriel au format visuel validé.
- Permissions ajoutées en 0.6.0 : `rental.deposits.settle` (régler le dépôt, autres frais, administrateur) et `rental.invoices.issue` (facture, administrateur et agent).
- Permissions ajoutées en 0.4.0 : `rental.reservations.override_rate` (modifier le tarif de la fiche), `rental.payments.credit` (accorder un crédit), `rental.documents.sensitive` (voir permis et reçus). Elles sont accordées au rôle administrateur Car Rental, jamais à l'agent par défaut.
- Les fichiers (photos, reçus, permis) sont privés : volume Docker `clientele-documents`, JPEG, PNG ou PDF seulement (type lu dans le contenu, SVG et HTML refusés), 10 Mo au plus, hash SHA-256, lecture selon la permission et journalisation. Un fichier d'une autre société répond 404.
- QR des reçus : HMAC sur la chaîne décimale du montant avec `QR_SIGNING_SECRET` (32 caractères au moins). L'application refuse de démarrer sans cette clé hors développement et tests ; aucun repli sur `APP_KEY`.
- Formats d'impression : contrat, fiche de sortie et facture Car Rental en PDF A4. Le 80 mm est réservé aux reçus d'encaissement et aux futures caisses (Market, bar, station, pièces). Une imprimante absente ne bloque jamais la recette.

## 5. Périmètre global à préserver

Le pilote est ordonné ainsi :

1. Car Rental.
2. Auto Parts et ventes de motocyclettes.
3. Guest House.
4. Market.
5. Gas Station (Pont Parois, Limonade) : ventes au comptant et à crédit approuvé pour les entreprises.
6. Hotel, Bar et Restaurant.
7. Paie du personnel en HTG pour toutes les sociétés.

Avant le module 2, terminer Car Rental (inspection avec croquis et photos, contrat signé, facture PDF), puis le socle commun : taux HTG/USD avec alerte BRH, reçu numéroté sur 8 chiffres avec QR, impression 80 mm, écran client, exports PDF et Excel.

Les besoins globaux à conserver dans les documents fonctionnels comprennent notamment :

- mode kiosque Chrome ou Edge, PWA et fonctionnement hors ligne contrôlé ;
- reçus de caisse, numéro de transaction à huit chiffres en deux blocs, QR et impression thermique Epson TMIII 80 mm ;
- écran client avec articles achetés et contenu promotionnel configurable ;
- HTG/USD, rapports haïtiens, TCA, TMS lorsque nécessaire, PDF et Excel ;
- importations Excel par modèles validés pour produits, inventaires, utilisateurs, véhicules, chambres, clients, fournisseurs et transactions autorisées ;
- neuf caisses prévues : Car Rental 1, Auto Parts et motocyclettes 3, Market 2, Guest House 1, Hotel 2 ;
- courriels et WhatsApp pour les reçus et notifications, avec branding configurable ;
- configuration globale restreinte : serveur de courriel, branding, taux, utilisateurs, sociétés et isolation des sociétés.

Les spécifications détaillées déjà versionnées sont dans `docs/01_Cahier_des_charges_fonctionnel.md`, `docs/02_Architecture_technique.md`, `docs/03_Impression_et_mode_kiosque.md`, `docs/05_Securite_et_acces.md`, `docs/07_Exploitation_et_deploiement.md` et `docs/10_Profil_KVM1_et_sites.md`.

## 6. Car Rental : règle métier de référence

### Flotte

- La plaque courante est l'identifiant métier du véhicule. Il n'y a pas de code interne distinct.
- Types de plaque autorisés : `Démonstration`, `Location`, `Normale`.
- Une plaque de démonstration peut être remplacée lorsqu'une plaque DGI est délivrée. Garder un historique, sans compliquer l'utilisation quotidienne.
- Tous les véhicules connus sont affectés à `Pont Parois, Route Nationale 6`.
- Les véhicules sont teintés. Gérer les dates d'expiration de permis de teinte, d'immatriculation, d'assurance et d'OAVCT.
- Un véhicule doit avoir, au minimum, catégorie, marque, modèle, plaque actuelle, type de plaque, adresse, kilométrage, statut opérationnel, tarif quotidien USD et dépôt minimum USD.
- Tarifs de référence confirmés pour préremplissage seulement : Suzuki Jimny 120 USD par jour, Suzuki Vitara 130 USD par jour, pick-up 200 USD par jour. Les SUV de luxe sont à confirmer au cas par cas, sans inventer de prix.
- Les documents et photos doivent rester rattachés à la fiche véhicule et être accessibles seulement avec les droits de société et d'adresse appropriés.

### Réservation, paiement et mise en circulation

- Une réservation doit proposer les valeurs par défaut indiquées plus haut, puis montrer les véhicules disponibles immédiatement dans le même espace de travail.
- La recherche, la modification, le paiement, la mise en circulation, la prolongation, le retour et l'annulation sont des tâches distinctes.
- Après la création, afficher un récapitulatif lisible avec les actions `Voir la réservation`, `Nouvelle réservation` et `Retour à la liste`.
- Une réservation ou un véhicule affiché dans une liste, un calendrier, une carte ou un résultat doit être ouvrable. L'écran de détail montre seulement les actions autorisées par les droits du contexte société et adresse.
- Les listes et calendriers ne doivent jamais exposer les coordonnées, l'identité complète ou l'historique d'un client à une personne qui n'en a pas besoin.
- La mise en circulation exige au minimum : un paiement de location approuvé, un dépôt USD retenu au moins égal au dépôt minimum de la réservation, le nom complet du conducteur, le numéro du permis, sa date d'expiration et la confirmation de vérification de l'original.
- Le montant de location et le dépôt requis doivent être visibles automatiquement dans la vue de mise en circulation, à partir de la réservation et de la fiche véhicule. Ils ne doivent pas être ressaisis par le préposé.
- Une prolongation doit contrôler la disponibilité sans divulguer les données du prochain client. Si le véhicule est réservé pour un autre client, le système explique le conflit sans afficher son identité.
- Un retour anticipé conserve le montant de la réservation initiale, conformément à la politique demandée.
- Frais possibles, réglés par société dans Configuration (20 USD au départ) : prise en charge aéroport, retour aéroport, nettoyage si le véhicule n'est pas retourné dans le même état de propreté. Ne pas les appliquer automatiquement sans règle et validation métier.

### Courriels client Car Rental

- Conserver le design validé des courriels.
- Envoyer les notifications de réservation, remise, prolongation, retour et facture lorsqu'elles sont réellement prises en charge par le flux métier.
- Ne jamais envoyer la plaque d'immatriculation au client.
- Le courriel doit utiliser la photo réellement choisie dans la fiche véhicule si elle peut être envoyée sans afficher la plaque. Sinon, supprimer l'image ou utiliser une illustration décrite exactement comme une illustration. Ne jamais présenter une illustration générique comme la photo du véhicule.
- Lorsqu'ils seront générés et validés, joindre la facture PDF et le contrat signé PDF. Ne jamais annoncer ou joindre un PDF fictif.
- Décision du propriétaire (8 octobre 2026) : le contrat signé est joint au courriel de remise, bien qu'il contienne la plaque et le numéro de permis, car c'est le contrat du client. Le corps des courriels ne montre jamais ni plaque ni permis.
- Le pied de page des courriels client affiche l'assistance routière et les coordonnées de la société (Configuration, identité légale). Le design et les textes approuvés ne changent pas.
- La facture finale est envoyée au client après le retour et le règlement du dépôt.
- Le texte des articles du contrat appartient au propriétaire et se saisit dans la configuration de la société. Ne jamais l'inventer ni le versionner.

## 7. Correctifs prioritaires non terminés

L'alpha.14 déployée ne satisfait pas encore les points suivants. Ils doivent être repris avant d'ajouter d'autres modules.

État au 0.5.0-alpha.1 : les P0 sont réalisés. Détail : `docs/23_Reservations_paiements_et_design_0.4.0.md` et `docs/24_Permis_fiche_de_sortie_signatures_et_contrat_0.5.0.md`. Le cycle Car Rental (remise, retour, croquis, dépôt, facture, P1 courriel) est complet en 0.6.0-alpha.1 : `docs/25_Retour_croquis_depot_et_facture_0.6.0.md`. Socle commun : taux HTG/USD avec alerte BRH, reçus 8 chiffres avec QR et impression 80 mm livrés en 0.7.0-alpha.1 (`docs/26_Taux_HTG_USD_et_recus_0.7.0.md`), taux unique du groupe en 0.8.0-alpha.1 (`docs/28_Taux_unique_du_groupe_0.8.0.md`). Restent : écran client, exports PDF et Excel, rapports journaliers.

### P0 : disponibilité et navigation (fait)

1. Dans la création d'une réservation, placer le résultat de recherche et les véhicules disponibles dans le même panneau ou dialogue que les critères de disponibilité.
2. Charger immédiatement les véhicules disponibles pour les valeurs par défaut, sans exiger une première recherche manuelle.
3. Corriger le rendu mobile pour que les résultats ne tombent pas tout en bas de la page.
4. Rendre chaque véhicule et chaque réservation cliquable depuis liste, calendrier, résultat et résumé.
5. Ouvrir une vue de détail dédiée et appliquer les droits d'accès avant d'afficher une action de modification.

### P0 : fiche véhicule (fait)

1. Refaire la fiche en sections courtes et faciles à saisir : identité, tarification, disponibilité, documents, photo et état.
2. Utiliser des champs sur une ligne lorsque cela convient et des libellés explicites.
3. Ajouter un interrupteur tactile `Actif` ou `Inactif` avec confirmation et journalisation.
4. Afficher et permettre de modifier les tarifs et le dépôt minimum uniquement selon les permissions.
5. Utiliser la photo sélectionnée de la fiche dans l'interface et, si elle est sûre, dans le courriel.

### P0 : permis et mise en circulation (fait)

1. Ajouter une zone d'ajout de photo du permis de conduire avec stockage contrôlé, type de fichier, taille maximale, hash, accès restreint et journalisation.
2. Ajouter pays émetteur et province ou État émetteur.
3. Afficher les documents du permis dans le détail de réservation seulement aux rôles autorisés.
4. Dans le dialogue de mise en circulation, afficher le tarif de location, les paiements approuvés, le dépôt requis, le dépôt retenu et le solde requis avant confirmation.
5. Ne permettre l'action finale que lorsque toutes les exigences sont remplies. Donner une erreur précise par exigence manquante.

### P1 : courriel et vérité de l'information affichée (fait)

1. Remplacer l'image générique par une vraie photo de fiche seulement si elle ne révèle pas la plaque.
2. Sinon, corriger le libellé vers `Illustration de catégorie` ou ne pas afficher d'image.
3. Vérifier que tous les modèles client omettent toujours la plaque, le permis, les montants internes, les données de dépôt non nécessaires et les références sensibles.

## 8. Fichiers à examiner en premier

| Zone | Fichiers principaux |
| --- | --- |
| Parcours Car Rental PWA | `apps/web/src/views/rental/`, `apps/web/src/components/rental/`, `apps/web/src/styles/main.css` |
| Navigation et droits côté interface | `apps/web/src/router/index.ts`, `apps/web/src/stores/session.ts` |
| Catalogue de flotte | `apps/web/src/data/clienteleFleetCatalog.ts`, `apps/web/public/fleet/` |
| API Car Rental | `apps/api/app/Http/Controllers/CarRental/` (une action par classe), `apps/api/app/Support/CarRental/` |
| Autorisation société et adresse | `apps/api/app/Support/CompanySiteAuthorizer.php`, `apps/api/app/Http/Controllers/CompanyContextController.php` |
| Modèles Car Rental | `apps/api/app/Models/CarRentalReservation.php`, `apps/api/app/Models/CarRentalVehicle.php`, `apps/api/app/Models/CarRentalVehicleDocument.php` |
| Courriels Car Rental | `apps/api/app/Support/CarRentalCustomerNotificationService.php`, `apps/api/app/Mail/CarRentalCustomerNotificationMail.php`, `apps/api/resources/views/mail/car-rental-customer-notification.blade.php` |
| Courriel d'authentification | `apps/api/app/Mail/AccessCodeMail.php`, `apps/api/resources/views/mail/access-code.blade.php` |
| Migration commerciale et mise en circulation | `apps/api/database/migrations/2026_10_08_001000_add_car_rental_commercial_terms_and_checkout_controls.php` |
| Documentation pilote | `docs/12_Pilote_Car_Rental.md`, `docs/17_Standard_interface_et_textes.md`, `docs/19_Vehicules_documents_et_utilisateurs_alpha10.md`, `docs/20_Reservations_notifications_et_utilisateurs_alpha11.md`, `docs/21_Flotte_car_rental_references_publiques_alpha12.md` |

## 9. Validation minimale exigée pour toute reprise

Avant une nouvelle préproduction :

1. Ajouter ou mettre à jour les tests API des règles métier modifiées.
2. Exécuter `php artisan test` dans `apps/api`.
3. Exécuter `npm run typecheck`, `npm test` et `npm run build` dans `apps/web`.
4. Vérifier qu'aucun caractère U+2014 n'existe dans les fichiers versionnés.
5. Vérifier le mobile et le tactile avec une largeur de téléphone avant de considérer l'interface prête.
6. Vérifier les permissions société et adresse avec au moins deux contextes différents.
7. Vérifier un courriel réel de test sans y exposer de plaque ou de document sensible.
8. Déployer avec un tag GHCR immuable uniquement après CI réussie.

## 10. Règle de reprise

Ne pas réécrire ou supprimer une fonctionnalité déjà validée sans une raison technique démontrée, un test de non-régression et une mise à jour de la documentation. Corriger d'abord les P0 ci-dessus, faire une recette sur mobile, puis seulement poursuivre les modules suivants.
