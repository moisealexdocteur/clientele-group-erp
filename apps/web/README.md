# Interface Web et PWA

L'interface est une PWA Vue 3 et TypeScript. Le premier écran est volontairement limité au pilote Clientèle Rent a Car : état de connexion, format Cap-Haïtien, contexte de société explicite et modules à venir. Il ne contient aucune réservation, véhicule, adresse ou donnée client inventée.

La PWA vérifie `/api/health` et `/api/v1/bootstrap`. Son service worker cache le shell applicatif uniquement ; il ne met jamais en cache les réponses `/api`, les données métier ou les opérations de caisse.

Contraintes de développement :

- composants par domaine et non par page unique ;
- design tokens pour le branding des sociétés ;
- PWA avec service worker et cache contrôlé ;
- IndexedDB pour file hors ligne chiffrée quand le navigateur le permet ;
- API typée et idempotence sur les mutations ;
- vue POS optimisée tablette et kiosque ;
- impression isolée dans une route de reçu sans navigation ni éléments inutiles ;
- affichage client dans une session séparée à jeton révocable ;
- tests unitaires des calculs, tests d'intégration des flux et recette matérielle.
