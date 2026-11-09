# Recette de bout en bout

Le dossier `e2e` contient une recette automatique dans un vrai navigateur (Playwright, téléphone de 390 px), sur l'API Laravel et PostgreSQL, et la PWA compilée. Elle tourne à chaque pull request (`.github/workflows/e2e.yml`). Les captures d'écran et les PDF produits sont publiés dans l'artefact `recette-e2e` de l'exécution, pendant 14 jours.

## Parcours vérifiés

1. Configuration de la société de recette : activité Auto Parts, magasin Rue 4 A, entrepôt Pont Parois, caisse, taux.
2. Import des modèles Haojin et photo générique d'un modèle.
3. Réception d'un lot de 3 motos avec couleurs, châssis, moteur et dédouanement.
4. Commande payée par dépôt Sogebank de 200 000 HTG, vérification, attribution automatique, client appelé.
5. Remise de la moto : fiche de dépôt, pièce d'identité, contrôle, vente finale ; reçu de vente, certificat Novamat et essai d'alignement en PDF.
6. Lubrifiants de test (10 caisses chacun) et entrée de stock de 5 caisses.
7. Ouverture de caisse, vente au comptoir au prix de gros, reçu de caisse.

## Règles

- Base de recette seulement : propriétaire et données créés par `e2e/seed.php`, aucune donnée réelle, aucun secret réel (clés de recette dans le workflow).
- Courriel en mode journal : aucun courriel n'est envoyé.
- Lancer en local : API sur le port 8001, PostgreSQL, `npm run build` dans `apps/web`, puis `npx playwright test` dans `e2e`.
