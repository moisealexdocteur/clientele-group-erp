# Retour, croquis des dommages, dépôt et facture (0.6.0-alpha.1)

Cette version termine le cycle Car Rental : remise, retour, règlement du dépôt et facture.

## 1. Croquis des dommages

- Silhouette du véhicule vue de dessus, avant en haut.
- L'agent choisit le type (rayure, bosse, éclat, bris, autre) puis touche l'endroit du dommage. Chaque marque est numérotée et peut recevoir une précision.
- 30 marques au plus, enregistrées en coordonnées relatives.
- Disponible à la remise (fiche de sortie) et au retour. Au retour, les marques du départ sont affichées en gris pour comparaison.
- Le croquis du départ est imprimé dans le contrat signé.

## 2. Retour du véhicule

Écran dédié « Enregistrer le retour » depuis le détail d'une location en circulation :

- kilométrage au retour, jamais inférieur à celui du départ ; distance parcourue affichée ;
- carburant, comparé au départ (alerte si plus bas, sans frais automatique) ;
- accessoires rendus, comparés à ceux remis (alerte si manquants) ;
- croquis, dommages constatés et 12 photos au plus ;
- signature du client s'il est présent ;
- frais supplémentaires, appliqués seulement si la case est cochée :
  - nettoyage 20 USD (location en USD) ;
  - kilométrage supplémentaire, calculé à partir du forfait et du prix au kilomètre du contrat ;
  - autres frais (motif et montant), réservés à `rental.deposits.settle`.

Un retour anticipé conserve le montant de la réservation. Aucun frais de retard ou de carburant n'est calculé automatiquement. Le véhicule passe en préparation et son kilométrage est mis à jour.

## 3. Règlement du dépôt de garantie

Après le retour, un administrateur (`rental.deposits.settle`) règle le dépôt :

- 0 pour tout libérer, ou un montant retenu avec un motif obligatoire ;
- la retenue ne dépasse jamais le dépôt retenu ;
- le montant proposé par défaut est le total des frais du retour, modifiable ;
- chaque dépôt passe à « libéré », « partiellement retenu » ou « retenu » ; l'opération est journalisée.

## 4. Facture

- Émise après le retour et le règlement du dépôt, par `rental.invoices.issue` (administrateur et agent).
- Numéro sur huit chiffres par société, affiché en deux blocs (`0000 0001`).
- Contenu figé à l'émission : lignes (location, frais aéroport, frais du retour), paiements approuvés, dépôt retenu imputé, crédit accordé (restant dû), solde dû ou trop-perçu, relevés de kilométrage et de carburant.
- Les paiements dans une autre devise sont listés sans conversion, en attendant le module des taux HTG/USD.
- La facture ne mentionne ni la plaque ni le permis : son PDF est envoyé automatiquement au client par courriel.

## 5. Courriels

- L'image des courriels est présentée comme une « Illustration de catégorie, pas une photo du véhicule ». Les photos réelles ne sont pas envoyées : elles peuvent montrer la plaque.
- Le courriel de retour annonce que la facture sera envoyée séparément.

## 6. Droits ajoutés

| Permission | Rôle par défaut | Usage |
| --- | --- | --- |
| `rental.deposits.settle` | Administrateur Car Rental | Régler le dépôt, ajouter d'autres frais au retour |
| `rental.invoices.issue` | Administrateur et agent Car Rental | Émettre la facture et son PDF |

La migration ajoute ces permissions aux accès existants.
