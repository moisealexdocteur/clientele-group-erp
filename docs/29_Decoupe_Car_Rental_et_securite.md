# Découpe Car Rental et correctifs de sécurité

Branche `fix/car-rental-decoupe`, issue d'une revue externe du dépôt. Aucune nouvelle activité, aucune nouvelle version : la version reste `0.8.0-alpha.1` jusqu'à la relecture.

## 1. Découpe du contrôleur Car Rental

- `CarRentalController` (3 193 lignes, 28 actions) est supprimé.
- Chaque action est un contrôleur invocable dans `apps/api/app/Http/Controllers/CarRental` : le plus long fait environ 300 lignes (mise en circulation).
- Les règles partagées sont dans `apps/api/app/Support/CarRental` :

| Service | Rôle |
| --- | --- |
| `CarRentalPresenter` | Réponses JSON : véhicules, réservations, paiements, documents |
| `CarRentalLookup` | Lecture d'une réservation ou d'un véhicule selon société et adresse |
| `CarRentalSchedule` | Périodes de réservation et de planning |
| `CarRentalVehicleRules` | Saisie du véhicule et contrôle du tarif |
| `CarRentalPricing` | Frais, lieux, contrat figé, facture figée |
| `CarRentalInspectionRules` | Croquis des dommages |
| `CarRentalCustomerDirectory` | Clients de la société et recherche masquée |

- Les routes et les adresses de l'API ne changent pas. Les tests existants passent sans modification de règle métier.

## 2. Montants

- `App\Support\Money` calcule en centimes entiers et les taux en dix-millièmes entiers.
- Interdit : `(float)` sur un prix, un dépôt, un taux ou une signature. Les sommes, comparaisons, conversions HTG/USD, factures, dépôts et contrôles de mise en circulation passent par `Money`.
- Saisie : `App\Rules\DecimalAmount` accepte au plus deux décimales, sans exposant (`1e2` refusé).
- Taux : quatre décimales au plus.

## 3. QR des reçus

- Signature HMAC-SHA256 sur `société|numéro|montant décimal canonique|devise`, tronquée à 80 bits pour un QR lisible. La vérification publique reste limitée à 30 essais par minute et par adresse.
- `QR_SIGNING_SECRET` est obligatoire (32 caractères au moins). Hors développement et tests, l'application refuse de démarrer sans elle. Aucun repli sur `APP_KEY`.
- Le nom de variable existant est conservé : le script de préproduction le génère déjà (96 caractères). Le renommer aurait arrêté la préproduction.
- La page publique montre le montant, la date et la société, jamais le client : c'est un choix documenté dans `docs/26_Taux_HTG_USD_et_recus_0.7.0.md`.

## 4. Fichiers

- Types acceptés : JPEG, PNG et PDF selon l'usage. WebP retiré. Le type est lu dans le contenu, pas dans le nom : un SVG ou un HTML renommé en `.png` ou `.pdf` est refusé.
- Un fichier d'une autre société répond 404.

## 5. Frais Car Rental en configuration

- Frais aéroport (par trajet) et frais de nettoyage réglés par société dans Configuration > Sociétés > fiche de la société. Valeur de départ : 20 USD chacun, valeurs confirmées par la direction.
- Une réservation ou un retour déjà enregistré garde ses montants.

## 6. Taux de change

L'écran parle de « référence saisie » : le chiffre est relevé et saisi à la main. L'application ne lit pas la BRH ; l'alerte compare le taux du groupe à cette saisie et journalise la confirmation.

## 7. Formats d'impression

- Contrat, fiche de sortie et facture Car Rental : PDF A4 (déjà le cas).
- 80 mm : reçus d'encaissement seulement, puis les futures caisses (Market, bar, station, pièces).
- Une imprimante absente ne bloque pas la recette : le PDF reste téléchargeable et le reçu s'ouvre dans l'aperçu du navigateur.

## 8. Points de la revue vérifiés et déjà conformes

- Les tables Car Rental ont une politique PostgreSQL `company_isolation` (RLS forcée) depuis `2026_10_07_000300` et `2026_10_08_000500`.
- Le type des fichiers était déjà restreint par usage et lu dans le contenu ; seul WebP est retiré.

## 9. Tests ajoutés

- Signature refusée si le montant du paiement change.
- Démarrage refusé sans clé QR, ou avec une clé trop courte.
- SVG, HTML et WebP refusés, même renommés.
- Fichier d'une autre société introuvable.
- Frais aéroport et nettoyage pris dans la configuration de la société.
- Montants en centimes, sans dérive de flottant.
