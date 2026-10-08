# Interface Web et PWA

PWA Vue 3, TypeScript, Vue Router et Pinia. L'organisation détaillée, les adresses des écrans et les règles visuelles sont décrites dans `docs/22_Interface_modulaire_0.3.0.md`.

## Commandes

```bash
npm ci              # installer les dépendances
npm run dev         # serveur de développement
npm run typecheck   # vérification TypeScript
npm test            # tests unitaires (Vitest)
npm run build       # compilation de production dans dist/
```

## Règles de développement

- Un écran par fichier dans `src/views`, des composants par domaine dans `src/components`.
- Tout appel serveur passe par `src/api` ; aucune vue n'appelle `fetch` directement.
- Toute date affichée passe par `src/lib/time.ts` (heure de Cap-Haïtien, AM ou PM).
- Tout montant affiché passe par `src/lib/money.ts`.
- Les couleurs, tailles et rayons viennent des jetons de `src/styles/main.css`.
- Le service worker ne met jamais en cache les réponses `/api`.

## Contraintes à venir

- IndexedDB pour la file hors ligne chiffrée de la caisse ;
- idempotence des mutations côté API ;
- vue caisse optimisée tablette et kiosque ;
- impression isolée dans une route de reçu sans navigation ;
- affichage client dans une session séparée à jeton révocable ;
- tests d'intégration des flux et recette matérielle.
