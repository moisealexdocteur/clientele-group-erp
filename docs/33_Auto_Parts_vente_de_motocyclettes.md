# Auto Parts - vente de motocyclettes

Deuxième lot du module 2. Branche `feat/auto-parts-motos`, empilée sur `feat/auto-parts-pieces`. La version reste `0.9.0-alpha.1`.

## Règles du propriétaire

- Marque Haojin seulement, fournisseur Novamat Motors.
- Deux points de retrait : Pont Parois, Route Nationale 6, et Rue 4 A, Cap-Haïtien. Ces adresses se créent dans Configuration > Sociétés, type `magasin` (ou `entrepôt` pour la réception).
- Paiement intégral d'avance par dépôt bancaire (Sogebank proposé), en HTG ou en USD. Un dépôt dans l'autre devise est converti au taux du groupe ; l'équivalent doit couvrir le prix.
- Un administrateur vérifie le dépôt avant toute attribution.
- Attribution dans l'ordre de paiement : date du dépôt, puis date d'enregistrement. Premier payé, premier servi.
- Vente finale, sans garantie.
- Accessoires remis : 3 casques, 1 pardessus, 1 gilet, 1 maillot, 1 radio.
- Reçu et certificat au format Lettre US (8,5 x 11 po).

## Parcours

1. **Commande** (vendeur) : modèle, couleur souhaitée (facultative), magasin de retrait, client, dépôt bancaire (devise, montant, banque, numéro et date de la fiche, photo facultative de la fiche). Statut `Dépôt à vérifier`.
2. **Vérification du dépôt** (administrateur) : confirmation, statut `Payée`, puis attribution automatique si une moto du modèle est en stock.
3. **Réception d'un lot** (magasinier) : modèle, année, châssis, moteur, couleur, cylindres, cylindrée, document de dédouanement reçu ou non. Châssis et moteur uniques dans la société. Les commandes payées reçoivent les motos dans l'ordre de paiement, la couleur souhaitée étant respectée si elle est disponible, le magasin de retrait étant préféré.
4. **À appeler** : la liste par défaut montre les clients dont la moto est prête, avec le téléphone à toucher pour appeler et le bouton « Client appelé ». Si la moto est dans un autre magasin que celui du retrait, le détail le signale.
5. **Remise** (vendeur), dans l'ordre du comptoir :
   1. fiche de dépôt originale présentée ;
   2. pièce d'identité avec photo acceptée en Haïti (CIN, passeport, permis de conduire, carte NIF avec photo) et son numéro ;
   3. contrôle avec le client, point par point : marque et modèle, châssis, moteur, couleur, éclairage, clignotants, klaxon, freins, huile et lubrifiant, carburant, pneus, rétroviseurs, démarrage ;
   4. accessoires et documents : certificat de vente du fournisseur, document de dédouanement s'il est disponible ;
   5. acceptation de la vente finale sans garantie.

   Le reçu reçoit un numéro à 8 chiffres de la série des reçus de la société.
6. **Dédouanement à remettre** : si le document manque, la commande reste dans l'onglet « Dédouanement ». Au retour du client, « Remettre le document » le note avec l'utilisateur, la date et l'heure.

Changer la moto attribuée ou annuler une commande exige un motif ; la moto retirée revient en stock et la commande retourne en attente.

## Documents imprimés

- **Reçu de vente** : entièrement imprimé par l'ERP sur papier sécurisé Lettre. Société, acheteur et pièce d'identité, motocyclette (marque, modèle, année, couleur, cylindrée, cylindres, châssis, moteur), paiement (taux et équivalent si conversion), accessoires et documents remis, contrôle, conditions de vente finale, signatures, QR de vérification signé. Il sert, avec le certificat du fournisseur, au certificat d'assurance de l'OAVCT.
- **Certificat de vente Novamat** : l'ERP imprime seulement les valeurs aux emplacements du formulaire pré-imprimé (acheteur, pièce d'identité, adresse, téléphone, année, modèle, châssis, moteur, couleur, cylindres).
- **Réglage d'alignement** : Configuration > Sociétés > Certificat de vente des motos, décalage horizontal et vertical en millimètres (de -30 à 30). Le bouton « Essai d'alignement » imprime les valeurs avec leurs libellés et des croix rouges, sur papier ordinaire, à superposer au formulaire.

Les positions par défaut ont été mesurées sur une photo du formulaire. Avant la première vente réelle, faire un essai d'alignement sur l'imprimante du magasin et ajuster le décalage.

Chaque impression et réimpression est journalisée (`receipt.printed`, `receipt.reprinted`, `parts.moto_certificate_printed`, `parts.moto_certificate_reprinted`).

## Permissions

| Permission | Administrateur | Vendeur | Magasinier |
| --- | --- | --- | --- |
| `parts.motos.read` | oui | oui | oui |
| `parts.motos.orders.create` | oui | oui | non |
| `parts.motos.payments.verify` | oui | non | non |
| `parts.motos.receive` | oui | non | oui |
| `parts.motos.assign` | oui | non | non |
| `parts.motos.deliver` | oui | oui | non |
| `parts.motos.models.manage` | oui | non | non |

La pièce d'identité, la photo de la fiche de dépôt et la référence du dépôt ne sont visibles qu'avec la vérification ou la remise. Les données restent limitées à la société et à l'adresse autorisées (RLS et contrôle d'adresse).

## Données

Migration `2026_11_09_000100_create_auto_parts_motorcycle_sales.php` : `moto_models`, `moto_batches`, `moto_units`, `moto_orders`, décalage du certificat sur `companies`. Les paiements par dépôt bancaire ne passent pas par la caisse et ne modifient pas le rapport journalier.

## Tests

`apps/api/tests/Feature/MotoSalesTest.php` : attribution dans l'ordre de paiement avec couleur, contrôles de la remise, dédouanement à remettre, impressions, QR ; couverture du paiement et conversion ; réattribution et annulation ; doublon de châssis ; isolation entre sociétés et adresses. `apps/web/src/lib/motoPdf.test.ts` : reçu et certificat.

## Modèles Haojin et prix par défaut

Motos > Modèles et stock > « Importer les modèles Haojin » ajoute les modèles vendus en Haïti par Novamat Motors qui manquent encore, au prix par défaut de 200 000 HTG (décision du propriétaire), modifiable ensuite modèle par modèle. Le formulaire « Ajouter un modèle » propose aussi 200 000 HTG.

La liste est dans `apps/web/src/data/haojinCatalog.ts`. Elle ne contient que les modèles confirmés par une source :

| Modèle | Source |
| --- | --- |
| HJ-125A-D | Certificat de vente Novamat Motors 2026 (moteur 157FMI, 1 cylindre) |
| HJ-150-28 | Catalogue en ligne Novamat Motors |

La cylindrée reste vide tant qu'une fiche du fournisseur ne la confirme pas ; le chiffre du nom n'est pas une preuve. Un autre modèle s'ajoute à la liste quand Novamat le confirme (liste de prix ou fiche technique).
