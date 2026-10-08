# Standard d’interface et de textes

## Objectif

L’interface Clientèle Group ERP utilise des conventions de produits professionnels : claires, directes et homogènes. Les principes s’alignent sur les usages des applications Microsoft, Android et iOS, sans reprendre de texte de ces produits.

## Règles de rédaction

| Élément | Règle | Exemple attendu |
| --- | --- | --- |
| Titre d’écran | 2 à 5 mots, décrit la tâche | `Créer une caisse` |
| Bouton principal | Verbe d’action précis | `Enregistrer le véhicule` |
| Bouton secondaire | Action courte | `Annuler`, `Retour`, `Actualiser` |
| Champ | Nom explicite, sans jargon interne non expliqué | `Adresse complète` |
| Aide | Une phrase, uniquement si elle évite une erreur | `Utilisez un code interne unique.` |
| Succès | Résultat confirmé et prochaine action utile | `La société a été créée. Ajoutez maintenant son adresse opérationnelle.` |
| Erreur | Cause concrète et action possible | `Sélectionnez une adresse active de cette société.` |
| Fonction non livrée | État réel, sans promesse | `Cette fonction n’est pas activée dans cette version.` |

Les formulations décoratives, ambiguës ou promotionnelles sont interdites dans l’interface métier. Exemples à éviter : titres très longs, slogans, promesses de sécurité non nécessaires, ou explications qui ne changent pas l’action de l’utilisateur.

## Densité des écrans

- Un titre de tâche reste lisible sans dominer l’écran : 32 à 46 px sur grand écran, 30 à 38 px sur téléphone.
- Le premier écran mobile affiche le formulaire ou l’action principale avant tout contenu de contexte.
- Une description ne répète pas le titre ; elle explique uniquement la prochaine décision ou une règle importante.

## Menus

- Un menu ne contient que des fonctions disponibles pour le rôle et réellement actives dans la version livrée.
- Les libellés suivent le vocabulaire métier : `Réservations`, `Calendrier`, `Véhicules`, `Configuration globale`.
- Une fonction inactive est retirée du menu. Elle n’est pas représentée par une page vide ni par des données fictives.
- Une action réservée au propriétaire est identifiée comme `Configuration globale` et protégée par le serveur, pas seulement masquée dans l’interface.

## Tactile et mobile d’abord

- Les boutons, onglets, champs de sélection et cases à cocher utilisés pour une action ont une hauteur minimale de 48 px ; les actions de formulaire principales utilisent 52 px.
- Les formulaires sont affichés sur une colonne à petite largeur. Les champs associés restent proches les uns des autres.
- Le bouton principal est placé après les champs concernés, en pleine largeur lorsque l’écran est étroit.
- Les contrôles de session et les actions importantes restent visibles sans menu masqué obligatoire.
- Les listes horizontales restent défilables au doigt, avec des éléments larges et espacés.

## Dialogues et notifications

- Un dialogue doit indiquer le résultat attendu, les conséquences importantes et l’action principale.
- Aucun dialogue ne doit demander une information déjà connue par le système.
- Les erreurs de validation restent près du champ ou expliquent précisément le champ concerné.
- Les informations sensibles ne sont jamais affichées dans un message, un journal ou une notification.

## Contrôle avant livraison

Avant chaque préproduction, vérifier que :

1. chaque titre décrit une tâche réelle ;
2. chaque bouton contient un verbe et une conséquence compréhensible ;
3. chaque menu ne montre que des fonctions actives ;
4. les actions tactiles importantes font au moins 48 px ;
5. les messages d’erreur donnent une action précise ;
6. aucune donnée fictive n’est présentée comme réelle.
