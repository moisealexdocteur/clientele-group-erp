# Interface Web et PWA

L'interface sera réalisée avec Vue et TypeScript.

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
