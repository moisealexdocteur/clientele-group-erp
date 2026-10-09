# Taux HTG/USD et reçus numérotés (0.7.0-alpha.1)

Premier volet du socle commun, utilisé d'abord par Car Rental puis par les modules suivants.

## 1. Taux HTG/USD

Remplacé en 0.8.0 par un taux unique du groupe réglé dans Configuration : `docs/28_Taux_unique_du_groupe_0.8.0.md`.

- Taux manuel par société : nombre de gourdes pour 1 USD, jusqu'à quatre décimales.
- Saisi par un administrateur (`finance.rates.manage`) ou le propriétaire, depuis l'écran « Taux de change » (rail de navigation ou menu du compte sur téléphone).
- Chaque saisie crée une ligne d'historique : un taux n'est jamais modifié après coup.
- Référence BRH facultative (taux et date). Un taux inférieur à la référence exige une case de confirmation et un motif ; il est signalé dans l'historique, dans le rail de navigation et dans le journal (`finance.exchange_rate_set_below_brh`).
- Le taux en vigueur est visible par tous les utilisateurs de la société.

La référence BRH est saisie manuellement : l'application ne lit pas le site de la BRH.

## 2. Paiements dans une autre devise

- Un paiement en HTG sur une réservation en USD (ou l'inverse) est converti au taux en vigueur.
- Le taux et le montant converti sont enregistrés avec le paiement et ne changent plus.
- Sans taux défini, le paiement dans l'autre devise est refusé avec un message clair. Le formulaire affiche l'équivalent avant l'enregistrement.
- La facture compte ces paiements dans sa devise et indique le montant d'origine et le taux.
- Le dépôt de garantie reste en USD.

## 3. Reçus de caisse

- Un reçu est créé à l'approbation d'un paiement encaissé (espèces ou virement). Un crédit accordé n'est pas un encaissement : il n'a pas de reçu.
- Numéro sur huit chiffres par société, affiché en deux blocs (`1234 5678`), sans trou ni doublon.
- Contenu : société, bureau, caisse, réservation, client, véhicule (sans plaque), nature, montant, mode, taux et équivalent si conversion, date et heure de Cap-Haïtien, QR de vérification.
- Copie Administration : bandeau « COPIE ADMINISTRATION », personne qui a approuvé, référence de virement (rôles autorisés), numéro d'impression.
- Chaque impression est journalisée ; à partir de la deuxième, le reçu porte la mention « RÉIMPRESSION » (`receipt.reprinted`).

## 4. Impression 80 mm

- L'écran du reçu a une mise en page de 80 mm (`@page size 80mm`), sans en-tête ni pied de navigateur.
- Sur un poste préparé selon `docs/03_Impression_et_mode_kiosque.md` (Edge `SilentPrintingEnabled` ou Chrome `--kiosk-printing`), l'impression part directement sur l'imprimante thermique par défaut.
- Sans configuration kiosque, l'aperçu d'impression du navigateur s'ouvre : choisir l'imprimante 80 mm.
- La recette sur l'imprimante Epson réelle reste à faire à la livraison du matériel.

## 5. Vérification du QR

- Le QR ouvre `https://<domaine>/verification/recu/<code société>/<numéro>?s=<signature>`.
- La signature HMAC utilise `QR_SIGNING_SECRET` (déjà présent dans l'environnement de préproduction).
- La page publique confirme la société, le numéro, la date, le montant et l'état. Elle ne montre jamais le client, le véhicule ni la réservation.
- Un code modifié ou inconnu est refusé. La vérification est limitée à 30 essais par minute et par adresse.

## 6. Droits

| Permission | Rôle par défaut | Usage |
| --- | --- | --- |
| `finance.rates.manage` | Administrateur Car Rental | Saisir le taux HTG/USD |
| `rental.reservations.read` | Tous les rôles Car Rental | Voir et imprimer un reçu |

## 7. Suite du socle commun

Écran client appairé à une caisse, exports PDF et Excel, rapports journaliers.
