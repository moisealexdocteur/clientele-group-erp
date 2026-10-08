# Pilote Clientèle Car Rental

## 1. Périmètre alpha.14

L’alpha.5 reste la référence validée en préproduction pour l’authentification, le courriel de vérification et le socle de sécurité. Le périmètre alpha.14, sans donnée réelle, couvre :

- les véhicules `suv`, `mid_suv` et `pickup` ;
- la création d’un véhicule pour une adresse autorisée, avec plaque en cours, kilométrage, catégorie et état opérationnel ;
- le tarif quotidien en USD et le dépôt minimum en USD, obligatoires pour toute fiche véhicule créée ou toute nouvelle réservation ;
- l’affectation par défaut de toute la flotte actuelle à l’adresse `Pont Parois, Route Nationale 6` ;
- le préremplissage de cette adresse lors de la création d’une adresse de société ;
- le préremplissage contrôlé de la flotte depuis les références publiques validées, sans création automatique et avec vérification explicite des fiches incomplètes ;
- l’affichage d’une photo de référence publique sur une fiche véhicule lorsque cette photo a été validée et versionnée ;
- l’utilisation de la plaque en cours comme identifiant du véhicule, sans code interne distinct ;
- les types de plaque `Démonstration`, `Location` et `Normale` ;
- le remplacement contrôlé d’une plaque, avec conservation de l’ancienne plaque dans l’historique du véhicule ;
- le contrôle de l’unicité de la plaque et du VIN à l’échelle de la société ;
- le suivi minimal des papiers : référence d’immatriculation, assurance OAVCT et permis de vitres teintées, avec dates d’expiration pour les deux derniers ;
- la liste de flotte et le changement d’état `disponible`, `préparation`, `lavage`, `garage` ou `en circulation` ;
- un planning tactile par période : réservations actives, dates de départ et de retour, adresse et état de la flotte ; le mois en cours est affiché par défaut et les sept premiers jours du mois suivant sont ajoutés pendant les sept derniers jours du mois ;
- la disponibilité par adresse et la protection contre une double réservation ;
- la réservation par un préposé, avec numéro de huit chiffres ;
- la date et l’heure actuelles de Cap-Haïtien par défaut pour une nouvelle réservation, un retour proposé le lendemain et le bureau actif du préposé comme adresse de prise en charge ;
- la confirmation ou la modification explicite de chaque valeur proposée par défaut ;
- un récapitulatif après création, avec les actions pour consulter la réservation, en créer une autre ou revenir à la liste ;
- la recherche d’une réservation par référence ou par plaque, limitée aux adresses autorisées ;
- des vues séparées pour modifier, encaisser, mettre en circulation, prolonger, retourner ou annuler une réservation ;
- la modification d’une réservation avant la remise du véhicule ;
- la mise en circulation seulement après vérification du permis original, saisie du nom du conducteur, numéro et date d’expiration du permis, paiement de location approuvé et dépôt USD retenu au minimum requis ;
- l’approbation d’un paiement de dépôt qui crée une retenue de dépôt journalisée ;
- la prolongation contrôlée, le retour et l’annulation d’une réservation ;
- un formulaire PWA qui oblige à sélectionner une société puis son adresse autorisée avant d’afficher les véhicules disponibles ;
- la prise en charge ou le drop-off au site, à l’Aéroport International du Cap-Haïtien ou à une adresse configurée ;
- le bureau physique comme lieu de départ par défaut, avec conservation de son libellé et de son adresse dans la réservation ;
- la sélection explicite, lorsque nécessaire, des frais de 20 USD pour une prise en charge à l’aéroport et/ou un retour à l’aéroport ;
- le kilométrage limité ou illimité ;
- la soumission d’un paiement en espèces ou par virement Sogebank, suivie d’une approbation séparée ;
- la structure, encore sans workflow actif, des dépôts de garantie en HTG ou USD et de la remise de passeport sans conserver son numéro ;
- la structure, encore sans workflow actif, des inspections avant/après location : odomètre, carburant, croquis, photos hashées et signatures hashées ;
- la création, par le propriétaire du système, d’utilisateurs Car Rental avec courriel personnel, mot de passe initial conforme, profil métier et portée d’adresses ;
- la modification, désactivation, réactivation, réinitialisation du mot de passe et suppression définitive d’un utilisateur conforme aux limites de société ;
- l’envoi d’un courriel de création de compte au nouvel utilisateur ;
- l’envoi d’un courriel client lorsque la réservation est créée, le véhicule remis, la location prolongée ou le retour enregistré, si un courriel client valide est disponible.
- la présentation de la marque et du modèle dans les courriels client, avec une image générique de catégorie. La plaque du véhicule n’est jamais envoyée au client.

Les véhicules, les réservations et le planning sont limités par société puis par adresse. Le planning ne retourne ni le nom, ni les coordonnées, ni l’identifiant du client.

La disponibilité ne retourne que les véhicules actifs dont l’état opérationnel est `Disponible`. Les états `Préparation`, `Lavage`, `Garage` et `En circulation` les excluent de la location.

## 2. États métier

| Objet | États actuels |
| --- | --- |
| Véhicule | Disponible, préparation, lavage, garage, en circulation |
| Réservation | Brouillon, réservée, en circulation, terminée, annulée |
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
| `rental.reservations.manage` | Modifier, mettre en circulation, prolonger, retourner ou annuler une réservation autorisée |
| `rental.payments.submit` | Déclarer un paiement espèces ou soumettre une preuve Sogebank |
| `rental.payments.approve` | Approuver un paiement soumis |

Une permission de société sans accès à l’adresse concernée est insuffisante. Les rôles sont configurés par société et non à l’échelle de tout le groupe.

## 4. Règles de paiement et dépôt

- Un virement ne peut être créé que pour `Sogebank` et exige une référence, une clé de preuve et son hash SHA-256.
- La saisie et l’approbation sont deux actions séparées, journalisées sans référence bancaire brute.
- Une saisie espèces est disponible dans la vue de mise en circulation lorsque l’adresse dispose d’une caisse active. La preuve de virement Sogebank reste à intégrer dans une vue dédiée avant utilisation opérationnelle.
- Un dépôt de garantie est retenu uniquement après approbation du paiement associé. La mise en circulation vérifie le montant USD retenu contre le dépôt minimum enregistré dans la réservation.
- Un dépôt de passeport est déjà modélisé comme une garde documentaire, sans numéro de passeport dans la base ni dans l’audit. Son écran et sa route métier de retenue restent à ajouter.
- La libération, l’application partielle ou la confiscation d’un dépôt sera ajoutée avec motif, inspection post-location et document de décision.

## 5. Étapes suivantes du pilote

1. contrat PDF numéroté, QR de vérification et signature client ;
2. inspection pré-location avec carburant, odomètre, dommages et signature ;
3. workflow retour avec inspection post-location, kilométrage et décision sur dépôt ;
4. calcul final, frais de nettoyage de 20 USD si la propreté au retour ne correspond pas à l’inspection de départ, facture, reçu client/administration et impression thermique ;
5. joindre le contrat signé et la facture PDF aux courriels seulement après leur génération et leur validation ;
6. stockage chiffré des preuves et photos, liens temporaires et antivirus ;
7. recette sur une imprimante Epson TMIII 80 mm réelle.

## 6. Hors périmètre alpha.14

Le contrat signé, les inspections actives, la libération ou l’application d’un dépôt, les factures et reçus PDF, l’impression thermique, le calcul fiscal haïtien final, la paie, les rapports comptables, l’intégration bancaire, l’API WhatsApp et les cartes de crédit ne sont pas encore actifs. Les courriels de facture et les pièces jointes PDF sont donc préparés par le code mais ne sont pas déclenchés avant ces modules.
