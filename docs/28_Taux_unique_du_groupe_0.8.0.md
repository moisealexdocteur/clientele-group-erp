# Taux unique du groupe (0.8.0-alpha.1)

Décision du propriétaire : un seul taux HTG/USD pour tout Clientèle Group, réglé dans Configuration. Remplace le taux par société de la version 0.7.0 (`docs/26_Taux_HTG_USD_et_recus_0.7.0.md`, section 1).

## 1. Emplacement

- Configuration > Taux de change. Aucun écran métier ne permet de saisir le taux.
- Le taux en vigueur reste affiché à tous les utilisateurs connectés (rail de navigation, formulaires de paiement).
- Chaque saisie ajoute une ligne d'historique. Un taux n'est jamais modifié après coup.
- La référence BRH, l'alerte, la confirmation et le motif sous la référence sont inchangés.

## 2. Qui peut saisir le taux

| Personne | Saisie |
| --- | --- |
| Propriétaire | Toujours |
| Utilisateur autorisé dans Configuration | Oui |
| Tout autre utilisateur | Non, consultation seulement |

- Le droit se règle dans Configuration > Utilisateurs > fiche de l'utilisateur > « Taux de change du groupe ».
- Il est porté par le compte, pas par le rôle d'une société : il vaut pour tout le groupe.
- Chaque changement demande une confirmation et est journalisé (`configuration.exchange_rate_access_granted`, `configuration.exchange_rate_access_revoked`).
- Une tentative de saisie sans droit est refusée et journalisée (`authorization.exchange_rate_refused`).
- La permission par société `finance.rates.manage` est retirée des accès existants par la migration.

## 3. Navigation

- Le propriétaire voit dans Configuration : Sociétés, Taux de change, Utilisateurs, Activités.
- Un utilisateur autorisé non propriétaire voit seulement Taux de change et Activités ; le chiffre du taux dans le rail ouvre directement l'écran.

## 4. Reprise des données

La migration `2026_10_14_000100_move_exchange_rates_to_group_configuration` copie les taux déjà saisis par société dans l'historique du groupe, dans l'ordre chronologique, puis supprime l'ancienne table. Les paiements déjà convertis conservent leur taux et leur équivalent.
