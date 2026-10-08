# Configuration globale — alpha.7

## Objet

L’alpha.7 ajoute l’espace propriétaire nécessaire pour préparer les sociétés, leurs adresses et leurs caisses sans introduire de données fictives. Il ne crée aucune entreprise, adresse, caisse, utilisateur métier, client, produit ou transaction lors du déploiement.

## Accès

Seul un compte dont le rôle système vaut `owner` peut ouvrir l’espace **Configuration globale** et appeler les routes associées. Un rôle local de société, même avec toutes les permissions métier, ne suffit pas.

| Route | Action | Contrôle |
| --- | --- | --- |
| `GET /api/v1/system/configuration/companies` | Lire la configuration | Session valide + rôle système `owner` |
| `POST /api/v1/system/configuration/companies` | Créer une société | Session valide + rôle système `owner` |
| `POST /api/v1/system/configuration/companies/{company}/sites` | Créer une adresse | Session valide + rôle système `owner` |
| `POST /api/v1/system/configuration/companies/{company}/cash-registers` | Créer une caisse | Session valide + rôle système `owner` |

## Règles appliquées

- Le code d’une société, d’une adresse ou d’une caisse est normalisé en majuscules et limité à `A-Z`, `0-9`, `-` et `_`.
- Le code de société est unique pour le groupe ; le code d’adresse est unique dans sa société ; le code de caisse est unique dans sa société.
- Une caisse ne peut être rattachée qu’à une adresse active de la société sélectionnée. Une adresse d’une autre société est refusée.
- Une société reçoit le fuseau `America/Port-au-Prince`, le libellé `Cap-Haïtien, Haïti` et la locale française `fr-HT`.
- Le propriétaire qui crée une société reçoit l’accès local `owner`, sur toutes les adresses, avec les permissions `*`. Cette attribution est explicite et journalisée.
- Les lectures des adresses et caisses se font séparément dans le contexte RLS de la société concernée. La page propriétaire ne désactive pas les politiques PostgreSQL.
- L’adresse complète est visible uniquement dans l’espace propriétaire ; elle n’est pas écrite dans les métadonnées du journal d’audit.

## Caisses connues à créer après validation des informations réelles

| Activité | Quantité connue | Statut alpha.7 |
| --- | ---: | --- |
| Car Rental | 1 | À configurer par le propriétaire |
| Auto Parts et motocyclettes | 3 | À configurer par le propriétaire |
| Market | 2 | À configurer par le propriétaire |
| Guest House | 1 | À configurer par le propriétaire |
| Hotel, bar et restaurant | 2 | À configurer par le propriétaire |

Ces quantités sont des objectifs de configuration, pas des caisses créées automatiquement.

## Hors périmètre

- Création et gestion des utilisateurs métier par société.
- Connexion à une Epson TMIII, impression automatique de reçu ou impression de rapport.
- Association d’un appareil Chrome/Edge en mode kiosque.
- Écran client, lien sécurisé d’affichage promotionnel ou activation à distance.
- Taux de change BRH, reçu, QR, transaction de caisse ou import Excel.
