# Configuration système et validation - alpha.9

## Correction livrée

Le formulaire de création de société accepte maintenant une saisie lisible dans le champ **Code interne**. Par exemple, `Clientèle Rent a Car` est enregistré sous la forme `CLIENTELE-RENT-A-CAR`.

La même normalisation est appliquée aux codes d’adresse et de caisse :

- les accents sont supprimés ;
- les espaces et séparateurs sont remplacés par des tirets ;
- le résultat est converti en majuscules ;
- un code vide, trop court, trop long ou déjà utilisé est refusé avec un message en français près du champ concerné.

## Parcours d’administration

1. Créer la société avec sa dénomination légale, son nom affiché et sa devise de base.
2. Sélectionner la société puis ajouter son adresse d’exploitation.
3. Sélectionner la société et l’adresse puis créer la caisse.

Les options d’impression de reçu et d’écran client ne sont pas affichées dans ce formulaire. Elles seront ajoutées lorsqu’une intégration réelle de l’imprimante Epson TM-III et de l’écran client sera disponible. La création d’une caisse ne prétend donc pas configurer un matériel non connecté.

## Contrôles

- L’API normalise également les codes afin de protéger les imports et les appels hors interface.
- Les erreurs de validation HTTP 422 sont converties en messages utilisables dans l’interface ; la clé interne Laravel n’est pas présentée à l’utilisateur.
- Les formulaires utilisent des libellés reliés aux champs, un état d’erreur accessible et des contrôles tactiles d’au moins 48 px.
- Les droits restent réservés au propriétaire du système et les créations restent journalisées.
