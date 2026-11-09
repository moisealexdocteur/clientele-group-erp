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

Motos > Modèles et stock > « Importer les modèles Haojin » ajoute les modèles vendus qui manquent encore, au prix par défaut de 200 000 HTG (décision du propriétaire), modifiable ensuite modèle par modèle. Le formulaire « Ajouter un modèle » propose aussi 200 000 HTG.

Liste confirmée par le propriétaire le 9 novembre 2026, dans `apps/web/src/data/haojinCatalog.ts` :

| Modèle | Type |
| --- | --- |
| HJ-110-5, HJ-125A, HJ-125A-D, HJ-150-28, HJ-200GY-3 | Motocyclette |
| HJ-150ZH-6A, HJ-150ZH-85, HJ-150ZH-175, HJ-150ZH-200A, HJ-250ZH-10 | Tricycle |

Les codes sont comparés sans tiret ni espace : « HJ125-A » et « HJ-125A » sont le même modèle. « HJ-200-CY-3 » est reconnu comme l'écriture de « HJ-200GY-3 » (catalogue Haojin) et n'est pas recréé. La cylindrée reste vide tant qu'une fiche du fournisseur ne la confirme pas.

## Photo, prix et emplacement

- Chaque modèle peut recevoir une photo générique (photo du fabricant ou du magasin, sans plaque), téléversée par l'administrateur dans la fiche du modèle (`moto_model_photo`, JPEG ou PNG, fichier privé de la société, lisible avec `parts.motos.read`, journalisé).
- La photo s'affiche dans la liste des modèles, la liste des motos disponibles et la nouvelle commande.
- La liste des motos disponibles montre le prix de vente du modèle et l'emplacement du stock (« Stock : Pont Parois, Route Nationale 6 »).

## Saisie rapide à la réception

- Couleur : liste de couleurs connues (Rouge, Rouge Sang, Noir, Blanc, Bleu…) et couleurs déjà utilisées, avec saisie libre.
- Châssis et moteur : bouton de scan à côté de chaque champ (appareil photo, code-barres ou QR). Il utilise l'API BarcodeDetector de Chrome et Edge ; sinon la saisie au clavier reste possible. Le même bouton sert au code-barres des articles et à la recherche de la vente au comptoir.

## Stock de motos

Motos > Stock (ou le résumé en tête de la liste des commandes) montre, mis à jour à chaque réception et à chaque remise :

- les motos disponibles à la vente (en stock, non attribuées), par modèle, par emplacement et par couleur ;
- les motos attribuées à un client payé, en attente de remise ;
- les clients payés qui attendent encore une moto.

Un modèle saisi par erreur se supprime depuis sa fiche s'il n'a ni moto ni commande ; sinon il se désactive (« Retiré ») et reste dans l'historique. Les modèles retirés sont rangés à part.

## Réception par lot et bon Novamat

Motos > Réception d'un lot :

1. emplacement et référence du bon ;
2. « Importer le PDF » : le bon ou la facture Novamat Motors est lu dans le navigateur (aucun envoi du fichier). Chaque ligne donne le modèle (code produit sans le suffixe de couleur, par exemple `[HJ-125A-D-BL]` donne HJ-125A-D), la couleur (« (bleu) » ou suffixe BL), le châssis (17 caractères) et le moteur. Les doublons sont ignorés ;
3. « Coller des lignes » fait la même lecture sur du texte copié (PDF, Excel, courriel) ;
4. un modèle lu mais absent de la liste s'ajoute en un geste à 200 000 HTG ;
5. le résumé par modèle et couleur, et le dédouanement pour tout le lot, se règlent avant d'enregistrer ; chaque ligne reste modifiable et la saisie manuelle ligne par ligne reste possible.

Un PDF scanné (image) n'a pas de texte lisible : coller les lignes ou les saisir.

## Reçu de vente infalsifiable

- Numéro unique sur 8 chiffres dans la série des reçus de la société, attribué une seule fois à la remise.
- Code de vérification imprimé (« 9F3A-07C2 ») : 8 caractères de la signature HMAC-SHA256 du reçu (`QR_SIGNING_SECRET`), impossible à calculer sans la clé du serveur.
- QR signé (signature complète) en haut à droite.
- Mention ORIGINAL à la première impression, DUPLICATA n ensuite ; filigrane diagonal avec la société, le numéro et l'exemplaire.
- Motos > Vérifier un reçu : scanner le QR, ou saisir le numéro et le code. Le résultat affiche la vente enregistrée (client, moto, châssis, moteur, prix, dépôt, date de remise) à comparer au papier et à la moto. Un reçu inconnu, un code faux ou un QR modifié est signalé « Reçu non authentique ». Une moto déjà remise est signalée : un reçu ne permet jamais une seconde remise.
- La vérification publique du QR montre la moto, le châssis, le moteur et les initiales du client, jamais son identité complète.
- Chaque contrôle est journalisé (`parts.moto_receipt_checked`, `parts.moto_receipt_rejected`).

Rappel : la remise exige toujours la commande payée et vérifiée par un administrateur, la fiche de dépôt originale et une pièce d'identité. Aucune vente à crédit.
