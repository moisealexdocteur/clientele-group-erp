# Permis international, fiche de sortie, signatures et contrat (0.5.0-alpha.1)

Livraison 2 des retours de recette du 8 octobre 2026. Elle termine la remise d'un véhicule Car Rental : la mise en circulation produit un contrat PDF signé.

## 1. Prérequis : conditions du contrat

Les articles du contrat papier sont saisis une seule fois par le propriétaire dans Configuration, fiche de la société, section « Conditions du contrat de location ».

- Un article par paragraphe. Une ligne qui commence par « Article » est imprimée en gras dans le PDF.
- 40 000 caractères au plus.
- Tant que ces conditions sont vides, aucune location ne peut être remise : le serveur refuse la mise en circulation et l'interface l'explique.
- Un contrat déjà signé conserve la version des conditions en vigueur à la signature (copie figée et empreinte SHA-256).

Aucun texte contractuel n'est inventé par le logiciel ni versionné dans le dépôt.

## 2. Écran de mise en circulation

Le détail de la réservation montre le résumé (paiement approuvé, dépôt retenu, conditions configurées) et le bouton « Commencer la mise en circulation ». L'écran dédié comporte trois étapes ; le bouton final reste grisé tant qu'un élément manque, et chaque élément manquant est nommé.

### Étape 1 : permis

- Nom complet, numéro, date d'expiration (avertissement si le permis expire avant le retour prévu).
- Pays émetteur : liste ISO 3166 en français, pays fréquents en tête (Haïti, États-Unis, Canada, République dominicaine, France).
- État, province ou territoire seulement pour les pays où le permis est délivré à ce niveau : États-Unis et Canada (listes fermées), Mexique, Australie, Brésil, Inde (saisie libre). Haïti, la France et les autres pays ont un permis national : aucune subdivision n'est demandée.
- Photos recto et verso obligatoires, prises avec l'appareil photo ou choisies dans la galerie.
- Confirmation de vérification de l'original.
- Conducteur additionnel facultatif : nom et numéro de permis.

### Étape 2 : fiche de sortie

- Kilométrage au compteur, prérempli avec le dernier relevé ; une valeur inférieure est refusée. Le relevé met à jour la fiche véhicule.
- Niveau de carburant : Réserve, 1/4, 1/2, 3/4, Plein.
- Accessoires remis : roue de secours, cric, clé de roue, triangle, trousse de secours, extincteur, documents du véhicule, tapis, radio, chargeur.
- Dommages constatés (texte) et jusqu'à 12 photos de l'état du véhicule.

### Étape 3 : signatures

- Affichage des conditions du contrat et case « Le client a lu les conditions et les accepte ».
- Signature tactile du locataire, puis signature « Pour le loueur » avec le nom du signataire (prérempli avec l'utilisateur connecté).

## 3. Contrat PDF

À la confirmation, le serveur enregistre la remise, la fiche de sortie (inspection `pre_rental` finalisée) et une copie figée du contrat : identité du loueur, conditions, caractéristiques du véhicule. L'application construit ensuite le PDF A4 à partir de cette copie :

1. Locataire.
2. Conducteur et permis (pays et subdivision émettrice).
3. Véhicule (plaque, numéro de série, couleur, carburant, transmission, cylindrée, portes).
4. Durée et conditions financières (tarif, montant, frais aéroport, kilométrage, dépôt retenu).
5. Fiche de sortie.
6. Conditions générales.
7. Signatures du locataire et du loueur, avec date et heure de Cap-Haïtien.

Chaque page indique le numéro du contrat et l'empreinte courte des conditions. Le PDF est stocké comme document privé et rattaché à la réservation ; il est définitif et ne peut pas être remplacé. Si sa création échoue (réseau), le bouton « Créer le contrat PDF » du détail le reconstruit à l'identique depuis la copie figée.

Le contrat n'est pas envoyé automatiquement par courriel : il contient la plaque et le numéro de permis, que les courriels client ne doivent jamais transmettre. Il est ouvert ou imprimé depuis le détail de la réservation. L'API garde la possibilité d'un envoi explicite (`send_to_customer`).

## 4. Droits

| Élément | Visible par |
| --- | --- |
| Pays, numéro et expiration du permis, conducteur additionnel | `rental.reservations.manage` |
| Photos recto et verso du permis | `rental.documents.sensitive` ou la personne qui les a ajoutées |
| Signatures et contrat PDF | `rental.reservations.manage` ou `rental.documents.sensitive` |
| Photos de l'état du véhicule | `rental.reservations.read` ou `rental.vehicles.read` |

## 5. Journalisation

- `car_rental.reservation_checked_out` : pays du permis, empreintes des photos du permis et des signatures, kilométrage et carburant.
- `car_rental.contract_issued` : empreinte du PDF et des conditions.
- `file.stored` pour chaque photo, signature et contrat.
