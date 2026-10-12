# Caisse, clôture et rapport journalier (0.9.0-alpha.1)

Deuxième volet du socle commun : cycle de caisse décrit au cahier des charges (section 6.1), d'abord utilisé par Car Rental, puis par les caisses des modules suivants.

## 1. Ouverture

- Écran Caisse : chaque caisse active des adresses autorisées, ouverte ou fermée.
- Ouvrir la caisse demande le fond de caisse en USD et en HTG. Valeur proposée : les montants comptés à la dernière clôture.
- Une seule session ouverte par caisse (contrôle en base).
- Tant que la caisse est fermée, un paiement en espèces est refusé avec un message clair ; le formulaire de paiement l'indique avant l'envoi.

## 2. Mouvements d'espèces

- Entrée : paiement de location ou dépôt de garantie en espèces, à son approbation (avec son numéro de reçu).
- Sortie : part non retenue d'un dépôt de garantie versé en espèces, au règlement du dépôt. La caisse qui a reçu le dépôt doit être ouverte.
- HTG et USD restent séparés : aucune conversion à la clôture.
- Virements et crédits ne sont pas des espèces : ils figurent seulement au rapport journalier.

## 3. Clôture

- Le montant attendu est proposé ; la personne compte et confirme ou corrige, par devise.
- Clôture refusée tant qu'un paiement en espèces de la session attend une approbation.
- Tout écart exige une explication. Il est journalisé et attend l'approbation d'un superviseur (`cash.sessions.approve`), qui indique sa décision.
- Clôture : la personne qui a ouvert la caisse, ou un superviseur.
- Rapport de fermeture imprimable en 80 mm ; à partir de la deuxième impression, il porte la mention « RÉIMPRESSION ».

## 4. Rapport journalier

- Chargé tout de suite pour aujourd'hui (heure de Cap-Haïtien) et toutes les adresses autorisées ; jour et adresse modifiables.
- Contenu : espèces par devise (fond, entrées, sorties, attendu, compté, écart), sessions, mouvements par nature, autres règlements approuvés (virements, crédits), réimpressions.
- Aucun nom ni coordonnée de client : réservations et reçus sont identifiés par leur numéro.
- Sorties : impression A4 ou 80 mm, PDF A4 et Excel (.xlsx avec feuilles Synthèse, Sessions et Mouvements). Chaque impression et chaque export est journalisé.

## 5. Droits

| Permission | Rôle par défaut | Usage |
| --- | --- | --- |
| `cash.sessions.operate` | Administrateur et agent Car Rental | Voir les caisses, ouvrir, clôturer sa propre session |
| `cash.sessions.approve` | Administrateur Car Rental | Clôturer toute session, approuver un écart |
| `cash.reports.read` | Administrateur Car Rental | Rapport journalier, impressions et exports |

Une portée limitée à une adresse ne voit que les caisses et sessions de cette adresse.

## 6. Mise à jour automatique des postes

Après un déploiement, une page gardée par le navigateur se recharge seule une fois lorsque le serveur annonce une autre version. Un poste de caisse ou un kiosque ne reste pas sur une ancienne version.

## 7. Architecture

- API : un contrôleur invocable par action dans `apps/api/app/Http/Controllers/Cash`, présentation et lecture dans `apps/api/app/Support/Cash/CashPresenter.php`, règles d'espèces dans `apps/api/app/Support/CashSessionService.php`. Montants en centimes entiers (`Money`).
- Tables `cash_sessions` et `cash_movements` sous politique PostgreSQL d'isolation par société.
- PWA : `src/views/cash/`, exports dans `src/lib/dailyReport.ts` et `src/lib/xlsx.ts`.

## 8. Reprise des données

Les paiements en espèces enregistrés avant cette version n'ont pas de session : ils ne sont pas comptés dans une caisse. Un dépôt en espèces reçu avant cette version ne peut être rendu qu'avec la caisse qui l'a reçu ouverte.

## 9. Suite du socle commun

Écran client appairé à une caisse.
