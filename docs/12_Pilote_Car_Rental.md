# Pilote Clientèle Car Rental

## 1. Périmètre alpha.4

L’alpha.4 pose le premier flux PWA/API, sans donnée réelle. Il couvre :

- les véhicules `suv`, `mid_suv` et `pickup` ;
- la disponibilité par adresse et la protection contre une double réservation ;
- la réservation par un préposé, avec numéro de huit chiffres ;
- un formulaire PWA qui oblige à sélectionner une société puis son adresse autorisée avant d’afficher les véhicules disponibles ;
- la prise en charge ou le drop-off au site, à l’Aéroport International du Cap-Haïtien ou à une adresse configurée ;
- le kilométrage limité ou illimité ;
- la soumission d’un paiement en espèces ou par virement Sogebank, suivie d’une approbation séparée ;
- la structure, encore sans workflow actif, des dépôts de garantie en HTG ou USD et de la remise de passeport sans conserver son numéro ;
- la structure, encore sans workflow actif, des inspections avant/après location : odomètre, carburant, croquis, photos hashées et signatures hashées.

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

1. gestion des véhicules et calendrier tactile dans la PWA ;
2. contrat PDF numéroté et QR de vérification ;
3. workflow check-out avec inspection pré-location et signature ;
4. workflow retour avec inspection post-location, carburant et kilométrage ;
5. calcul final, décision sur dépôt, reçu client/administration et impression thermique ;
6. stockage chiffré des preuves et photos, liens temporaires et antivirus ;
7. recette sur une imprimante Epson TMIII 80 mm réelle.

## 6. Hors périmètre alpha.4

Le calcul fiscal haïtien final, la paie, les rapports comptables, l’intégration bancaire, l’API WhatsApp et les cartes de crédit ne sont pas encore actifs. Ils seront ajoutés après validation du flux de location sur données de test.
