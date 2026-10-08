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
| Aide | Une phrase, uniquement si elle évite une erreur | `Les espaces et accents sont convertis automatiquement.` |
| Succès | Résultat confirmé et prochaine action utile | `Société créée. Vous pouvez maintenant ajouter une adresse.` |
| Erreur | Cause concrète et action possible | `Sélectionnez une adresse active de cette société.` |
| Fonction non livrée | État réel, sans promesse | `Cette fonction n’est pas activée dans cette version.` |

Les formulations décoratives, ambiguës ou promotionnelles sont interdites dans l’interface métier. Exemples à éviter : titres très longs, slogans, promesses de sécurité non nécessaires, ou explications qui ne changent pas l’action de l’utilisateur.

## Valeurs par défaut

- Chaque nouvelle tâche propose immédiatement une valeur métier valable. L’utilisateur confirme ou la modifie.
- Une réservation propose la date et l’heure actuelles à Cap-Haïtien pour la prise en charge, puis le même horaire le lendemain pour le retour.
- Le bureau de prise en charge proposé est l’adresse autorisée active de la session. Le navigateur conserve ce choix pour la société active et utilise la première adresse autorisée si aucun choix antérieur n’existe.
- Les listes et calendriers chargent une période utile par défaut. Une page ne doit pas attendre une action de l’utilisateur pour afficher ses données principales.
- Une valeur proposée doit être visible et modifiable. Elle ne doit jamais être appliquée de manière cachée.

## Densité des écrans

- Un titre de tâche reste lisible sans dominer l’écran : 32 à 46 px sur grand écran, 30 à 38 px sur téléphone.
- Le premier écran mobile affiche le formulaire ou l’action principale avant tout contenu de contexte.
- Une description ne répète pas le titre ; elle explique uniquement la prochaine décision ou une règle importante.
- Les formulaires de configuration utilisent des titres courts : `Ajouter une société`, `Ajouter une adresse`, `Ajouter une caisse`.
- Une liste ou un planning affiche une période utile par défaut. L’utilisateur affine seulement si nécessaire.
- Une tâche principale est présentée dans une vue ou un dialogue dédié. Ne pas empiler la création, la recherche, la modification, le paiement et la mise en circulation dans le même formulaire.
- Après une création, afficher un récapitulatif clair avec les actions `Voir`, `Nouvelle` et `Retour à la liste`.

## Menus

- Un menu ne contient que des fonctions disponibles pour le rôle et réellement actives dans la version livrée.
- Les libellés suivent le vocabulaire métier : `Réservations`, `Calendrier`, `Véhicules`, `Configuration système`.
- Une fonction inactive est retirée du menu. Elle n’est pas représentée par une page vide ni par des données fictives.
- Une action réservée au propriétaire est identifiée comme `Configuration système` et protégée par le serveur, pas seulement masquée dans l’interface.

## Tactile et mobile d’abord

- Les boutons, onglets, champs de sélection et cases à cocher utilisés pour une action ont une hauteur minimale de 48 px ; les actions de formulaire principales utilisent 52 px.
- Les formulaires sont affichés sur une colonne à petite largeur. Les champs associés restent proches les uns des autres.
- Le bouton principal est placé après les champs concernés, en pleine largeur lorsque l’écran est étroit.
- Les contrôles de session et les actions importantes restent visibles sans menu masqué obligatoire.
- Les listes horizontales restent défilables au doigt, avec des éléments larges et espacés.

## Dialogues et notifications

- Un dialogue doit indiquer le résultat attendu, les conséquences importantes et l’action principale.
- Toute action destructive utilise un bouton distinct, une confirmation explicite et, pour une suppression définitive, une vérification complémentaire de la cible.
- Aucun dialogue ne doit demander une information déjà connue par le système.
- Les erreurs de validation restent près du champ ou expliquent précisément le champ concerné.
- Une erreur technique ou une clé interne telle que `validation.regex` ne doit jamais être affichée à l’utilisateur.
- Les informations sensibles ne sont jamais affichées dans un message, un journal ou une notification.
- Les notifications clients décrivent le véhicule par marque et modèle. Elles ne contiennent jamais sa plaque d’immatriculation. Une image générique de catégorie peut être utilisée à titre indicatif.

## Formulaires de configuration

- Un code interne accepte une saisie lisible : l’application convertit les espaces et accents en un code normalisé avant l’enregistrement.
- Chaque champ a un libellé associé, un identifiant et un message d’erreur accessible.
- Les paramètres de matériel ne sont affichés que lorsque leur configuration est disponible. Une caisse ne propose pas une option d’impression ou d’écran client avant l’intégration réelle du dispositif.
- Un véhicule utilise un seul identifiant visible : sa plaque en cours. L’interface ne demande pas un code interne distinct.
- Lorsqu’une plaque `Démonstration` est remplacée, le message confirme que l’ancienne plaque reste dans l’historique du véhicule.
- Le formulaire d’utilisateur affiche la règle complète du mot de passe avant l’enregistrement : au moins 12 caractères, majuscule, minuscule, chiffre et symbole.
- Le détail d’un utilisateur permet d’enregistrer les modifications, désactiver, réactiver, réinitialiser le mot de passe ou supprimer définitivement, avec des libellés d’action explicites.

## Typographie et ponctuation

- Le caractère typographique U+2014 est interdit dans les titres, menus, dialogues, courriels, documents, code source et scripts du dépôt.
- Lorsqu’une séparation est nécessaire, utiliser uniquement le tiret ASCII entouré d’espaces : `texte - texte`.
- Cette règle est vérifiée avant livraison avec la recherche de ce caractère dans les fichiers versionnés.

## Contrôle avant livraison

Avant chaque préproduction, vérifier que :

1. chaque titre décrit une tâche réelle ;
2. chaque bouton contient un verbe et une conséquence compréhensible ;
3. chaque menu ne montre que des fonctions actives ;
4. les actions tactiles importantes font au moins 48 px ;
5. les messages d’erreur donnent une action précise ;
6. aucune donnée fictive n’est présentée comme réelle.
7. les nouvelles tâches proposent des valeurs par défaut adaptées au contexte ;
8. aucun caractère U+2014 n’est présent dans les fichiers versionnés.
