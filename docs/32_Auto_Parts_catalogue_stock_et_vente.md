# Auto Parts : catalogue, stock et vente au comptoir

Premier lot du module 2 (Auto Parts et motocyclettes), branche `feat/auto-parts-pieces`. La version reste `0.9.0-alpha.1` jusqu'à la relecture.

## 1. Activité de la société et types d'adresse

- Chaque société a une activité : `car_rental` ou `auto_parts`, choisie à la création dans Configuration > Sociétés et modifiable par le propriétaire (journalisé). Les sociétés existantes restent Car Rental.
- Le changement d'activité est refusé tant qu'un utilisateur actif garde un profil de l'ancienne activité.
- Chaque adresse a un type : Bureau, Magasin (vend au comptoir) ou Entrepôt (stock sans caisse). Une nouvelle adresse Auto Parts est un magasin par défaut. Le type se modifie dans la fiche société.
- L'application affiche les écrans de l'activité : Vente, Ventes, Articles, Stock et Caisse pour Auto Parts. Une société Car Rental n'a pas accès aux routes `/api/v1/parts` (404, même pour le propriétaire).

## 2. Profils

| Profil | Permissions |
| --- | --- |
| Administrateur Auto Parts | Catalogue, stock, vente, prix de gros, caisse, approbation des écarts, rapport journalier |
| Vendeur | Consulter le catalogue et le stock, vendre au détail, ventes du jour, caisse |
| Magasinier | Catalogue, entrées, transferts et ajustements de stock |

Permissions : `parts.catalog.read`, `parts.catalog.manage`, `parts.stock.read`, `parts.stock.manage`, `parts.sales.create`, `parts.sales.read`, `parts.sales.wholesale`. Les profils Car Rental et Auto Parts sont dans `App\Support\RoleProfiles`.

## 3. Catalogue

- Article : référence (unique, en majuscules), code-barres facultatif, désignation, catégorie, marque, unité de vente, prix de détail HTG, prix de gros HTG facultatif (jamais supérieur au détail), seuil de réapprovisionnement, note, photo réelle.
- Compatibilités : marque, modèle et années de motocyclette lorsque connus. La recherche porte aussi sur la marque et le modèle.
- Le prix de gros n'est montré qu'aux rôles qui peuvent l'appliquer ou gérer le catalogue.
- Un changement de prix est journalisé avec l'ancien et le nouveau prix. Une vente garde le prix du moment.
- Un article retiré de la vente garde son stock et son historique.

## 4. Stock par emplacement

- Le stock est tenu par magasin et par entrepôt. Un bureau ne reçoit pas de stock.
- Mouvements : entrée (arrivage avec référence du bon ou de la facture et coût unitaire facultatif), transfert entre deux emplacements, ajustement d'inventaire (quantité comptée et motif obligatoire), vente.
- Chaque mouvement verrouille la ligne de stock, refuse un stock négatif et enregistre le solde obtenu. Le journal ne se modifie pas : déclencheur PostgreSQL et correction par ajustement.
- Un stock initial peut être saisi à la création de l'article.
- Écran Stock : journal des sept derniers jours par défaut, filtres par emplacement, nature et période. Filtre « Stock bas » dans Articles.

## 5. Vente au comptoir

- Le vendeur cherche un article, le touche pour l'ajouter, ajuste la quantité et l'emplacement de sortie (magasin ou entrepôt), puis encaisse.
- Règlement en espèces HTG seulement, comme prévu au cahier des charges. La caisse du magasin doit être ouverte ; l'encaissement devient un mouvement « Vente de pièces » de la session.
- Les prix viennent du catalogue côté serveur ; un prix envoyé par le poste est ignoré.
- Montant reçu proposé égal au total, monnaie à rendre calculée.
- L'identifiant de vente est créé par le poste : un double envoi ne crée pas deux ventes.
- Reçu 80 mm numéroté sur huit chiffres (même séquence que les autres reçus de la société), QR signé vérifiable publiquement, copie Administration, impressions et réimpressions journalisées.
- Le nom du client est facultatif, imprimé sur le reçu, jamais affiché dans les listes.
- Rapport journalier : section « Ventes de pièces » par prix (détail ou gros) au lieu des encaissements Car Rental, en PDF Lettre et Excel.

## 6. Isolation et tests

- Tables `parts_products`, `parts_product_fitments`, `parts_stock_levels`, `parts_stock_movements`, `parts_sales`, `parts_sale_lines` sous politique PostgreSQL `company_isolation`. Chaque requête filtre aussi la société et la portée d'adresse.
- Tests Feature (`apps/api/tests/Feature/AutoPartsTest.php`) : cycle complet (article, compatibilité, entrée, transfert, caisse fermée, vente, idempotence, session, rapport, reçu, QR), prix du catalogue, gros refusé au vendeur, stock insuffisant, montant insuffisant, bureau refusé, article retiré, ajustement, isolation entre deux sociétés et entre adresses, société Car Rental sans catalogue, configuration de l'activité et des profils.
- Tests PWA : panier (`partsCart.test.ts`), centimes (`money.test.ts`), rapport journalier Auto Parts.

## 7. Suite du module

1. Retours clients autorisés et remboursements.
2. Fournisseurs, factures fournisseurs, commandes et coûts de revient.
3. Conditions client et vente en gros encadrée.
4. Motocyclettes Haojin : modèles, options, dépôt, solde, livraison différée et remise.
5. Certificats d'immatriculation et de vente numérotés.
6. Importation Excel du catalogue.

## Base clientèle

- Les clients d'Auto Parts sont des fiches de la société (`customer_profiles`, coordonnées chiffrées, isolées par société). Le téléphone identifie le client : le même numéro retrouve toujours la même fiche.
- Une fiche est créée ou complétée à la commande d'une moto (nom et téléphone obligatoires) et à une vente au comptoir quand le nom et le téléphone sont saisis.
- À la commande d'une moto, la saisie du téléphone propose la fiche du client connu.
- Clients : recherche par nom, téléphone ou courriel ; fiche avec motos commandées et achats au comptoir ; correction des coordonnées (téléphone unique), journalisée sans coordonnées.
- Permissions `parts.customers.read` et `parts.customers.manage` : administrateur et vendeur.
- Aucune vente à crédit : comptoir en espèces HTG, motos payées intégralement d'avance.

## Lubrifiants de départ

Articles > « Lubrifiants de départ » crée les articles d'après les marques citées et les photos du stock du 9 novembre 2026, avec la catégorie (moteur essence, diesel, universelle, moto 2 temps, hydraulique, transmission, frein, refroidissement), l'usage (voiture, moto, camion) dans les notes, et le stock initial à l'emplacement choisi. Prix de test 25 000 HTG par caisse ou seau, au détail et en gros, à corriger. Les quantités vues sur photo sont des comptes partiels, à confirmer par inventaire. Liste : `apps/web/src/data/starterLubricants.ts`.
