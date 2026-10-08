# Pilote Clientèle Car Rental

## 1. Périmètre alpha.10

L’alpha.5 reste la référence validée en préproduction pour l’authentification, le courriel de vérification et le socle de sécurité. Le périmètre alpha.10, sans donnée réelle, couvre :

- les véhicules `suv`, `mid_suv` et `pickup` ;
- la création d’un véhicule pour une adresse autorisée, avec plaque en cours, kilométrage, catégorie et état opérationnel ;
- l’utilisation de la plaque en cours comme identifiant du véhicule, sans code interne distinct ;
- le remplacement contrôlé d’une plaque `Démonstration` par une plaque `Officielle`, avec conservation de l’ancienne plaque dans l’historique du véhicule ;
- le contrôle de l’unicité de la plaque et du VIN à l’échelle de la société ;
- le suivi minimal des papiers : référence d’immatriculation, assurance OAVCT et permis de vitres teintées, avec dates d’expiration pour les deux derniers ;
- la liste de flotte et le changement d’état `disponible`, `préparation`, `lavage`, `garage` ou `en circulation` ;
- un planning tactile par période : réservations actives, dates de départ et de retour, adresse et état de la flotte ;
- la disponibilité par adresse et la protection contre une double réservation ;
- la réservation par un préposé, avec numéro de huit chiffres ;
- un formulaire PWA qui oblige à sélectionner une société puis son adresse autorisée avant d’afficher les véhicules disponibles ;
- la prise en charge ou le drop-off au site, à l’Aéroport International du Cap-Haïtien ou à une adresse configurée ;
- le bureau physique comme lieu de départ par défaut, avec conservation de son libellé et de son adresse dans la réservation ;
- la sélection explicite, lorsque nécessaire, des frais de 20 USD pour une prise en charge à l’aéroport et/ou un retour à l’aéroport ;
- le kilométrage limité ou illimité ;
- la soumission d’un paiement en espèces ou par virement Sogebank, suivie d’une approbation séparée ;
- la structure, encore sans workflow actif, des dépôts de garantie en HTG ou USD et de la remise de passeport sans conserver son numéro ;
- la structure, encore sans workflow actif, des inspections avant/après location : odomètre, carburant, croquis, photos hashées et signatures hashées ;
- la création, par le propriétaire du système, d’utilisateurs Car Rental avec courriel personnel, mot de passe initial conforme, profil métier et portée d’adresses.

Les véhicules, les réservations et le planning sont limités par société puis par adresse. Le planning ne retourne ni le nom, ni les coordonnées, ni l’identifiant du client.

La disponibilité ne retourne que les véhicules actifs dont l’état opérationnel est `Disponible`. Les états `Préparation`, `Lavage`, `Garage` et `En circulation` les excluent de la location.

## 2. États métier

| Objet | États actuels |
| --- | --- |
| Véhicule | Disponible, préparation, lavage, garage, en circulation |
| Réservation | Brouillon, réservée, sortie, terminée, annulée |
| Paiement | Soumis, approuvé, refusé, annulé |
| Dépôt | Requis, retenu, partiellement appliqué, libéré, confisqué |
| Inspection | Brouillon, finalisée ; avant ou après location |

Le calendrier ne repose pas sur une simple étiquette. Pour une même société, un véhicule portant une réservation active qui chevauche la période demandée est refusé dans une transaction verrouillée.

## 3. Permissions minimales

| Permission | Utilisation |
| --- | --- |
| `rental.availability.read` | Voir les véhicules disponibles à son adresse autorisée |
| `rental.vehicles.read` | Consulter les véhicules de ses adresses autorisées |
| `rental.vehicles.manage` | Ajouter un véhicule, modifier son état, sa plaque ou ses papiers |
| `rental.calendar.read` | Consulter le planning des véhicules de ses adresses autorisées |
| `rental.reservations.create` | Créer une réservation |
| `rental.reservations.read` | Consulter une réservation de son adresse autorisée |
| `rental.payments.submit` | Déclarer un paiement espèces ou soumettre une preuve Sogebank |
| `rental.payments.approve` | Approuver un paiement soumis |

Une permission de société sans accès à l’adresse concernée est insuffisante. Les rôles sont configurés par société et non à l’échelle de tout le groupe.

## 4. Règles de paiement et dépôt

- Un virement ne peut être créé que pour `Sogebank` et exige une référence, une clé de preuve et son hash SHA-256.
- La saisie et l’approbation sont deux actions séparées, journalisées sans référence bancaire brute.
- Un dépôt de passeport est déjà modélisé comme une garde documentaire, sans numéro de passeport dans la base ni dans l’audit ; l’écran et la route métier de retenue seront ajoutés avec le check-out.
- La libération, l’application partielle ou la confiscation d’un dépôt sera ajoutée avec motif, inspection post-location et document de décision.

## 5. Étapes suivantes du pilote

1. contrat PDF numéroté et QR de vérification ;
2. workflow check-out avec inspection pré-location et signature ;
3. workflow retour avec inspection post-location, carburant et kilométrage ;
4. calcul final, frais de nettoyage de 20 USD si la propreté au retour ne correspond pas à l’inspection de départ, décision sur dépôt, reçu client/administration et impression thermique ;
5. stockage chiffré des preuves et photos, liens temporaires et antivirus ;
6. recette sur une imprimante Epson TMIII 80 mm réelle.

## 6. Hors périmètre alpha.10

Le contrat, les inspections actives, les dépôts de garantie actifs, les reçus, l’impression thermique, le calcul fiscal haïtien final, la paie, les rapports comptables, l’intégration bancaire, l’API WhatsApp et les cartes de crédit ne sont pas encore actifs. Ils seront ajoutés dans des lots séparés après validation du flux de location sur données de test.
