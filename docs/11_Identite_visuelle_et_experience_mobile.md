# Identité visuelle et expérience mobile

## 1. Source de référence

Le logo et les couleurs de cette première référence proviennent des captures du compte TikTok Clientèle Group fournies le 07 octobre 2026. L'actif de travail est apps/web/public/brand/clientele-group-logo.webp.

Avant une impression grand format, une enseigne ou une campagne publique, demander le fichier vectoriel officiel du logo. L'actif actuel est suffisant pour les maquettes, l'interface, les reçus et la préparation du produit.

## 2. Palette commune

| Usage | Couleur | Valeur |
| --- | --- | --- |
| Action principale, confirmation, repère Clientèle | Rouge | #F70707 |
| Navigation, lien, statut d'information | Bleu | #2222E6 |
| Texte et contraste fort | Noir | #0E0E10 |
| Surface et espace négatif | Blanc | #FEFEFE |

Le rouge porte l'action principale. Le bleu distingue la navigation, une information ou une catégorie. Le noir donne la structure et le texte. Les couleurs ne remplacent jamais un libellé, une icône ou un état explicite.

## 3. Hiérarchie de marque

La barre haute affiche toujours le logo Clientèle Group. La société active est nommée en toutes lettres : Clientèle Car Rental, Clientèle Auto Parts, Clientèle Guest House, Clientèle Market ou Clientèle Hotel.

Chaque société peut posséder son identité de reçu et ses coordonnées, mais ne peut pas remplacer le logo groupe dans l'application. Cela permet de reconnaître le produit tout en évitant de mélanger les opérations.

## 4. Contexte obligatoire d'une opération

Un écran de vente, réservation, dépôt, inspection, folio ou clôture doit afficher, sans menu ni dialogue à ouvrir :

1. la société active ;
2. le site actif ;
3. l'adresse complète configurée du site ;
4. le point de vente, de réception ou de service ;
5. l'état de la session, de l'appareil et de la synchronisation ;
6. l'heure affichée sous le libellé Cap-Haïtien, Haïti.

Les informations inconnues ne sont jamais remplacées par une adresse inventée. Lors de la configuration initiale, le produit affiche « Adresse à compléter avant activation » et bloque l'ouverture du poste jusqu'à validation.

## 5. Mobile-first

Les flux de terrain sont conçus à partir d'un téléphone de 360 px et s'étendent naturellement à une tablette ou à un kiosque. Les actions critiques restent dans le bas de l'écran, avec un libellé complet : « Encaisser 2 100 HTG », « Créer la réservation », « Confirmer l'inspection ».

- cible tactile minimale : 48 x 48 px ;
- aucune action critique fondée uniquement sur un balayage ou un survol ;
- une seule action principale visible à la fois ;
- montants, devise, client local et état hors ligne visibles avant confirmation ;
- dialogues de confirmation seulement pour une opération irréversible, avec le motif ou le résultat affiché clairement.

## 6. Français opérationnel

La première version est en français et ne montre pas de sélecteur de langue. Les libellés restent directs : « Vente », « Encaisser », « Reçu client », « Copie Administration », « Hors ligne », « À synchroniser », « Adresse à compléter ».


## 7. Connexion et réinitialisation

La connexion est une fonction de travail, pas un espace promotionnel. Elle utilise une carte unique et une étape à la fois :

1. adresse courriel et mot de passe ;
2. code à six chiffres envoyé par courriel ;
3. réinitialisation du mot de passe si nécessaire.

Les intitulés doivent suivre le vocabulaire standard des plateformes professionnelles : « Se connecter », « Mot de passe », « Afficher », « Envoyer le code », « Valider le code », « Réinitialiser le mot de passe ». Les explications sont limitées aux informations nécessaires pour terminer l’action.

Les erreurs indiquent l’action à effectuer sans divulguer d’information sensible. Exemple : « Le code de sécurité ne peut pas être envoyé pour le moment. Réessayez plus tard ou contactez l’administrateur. »

## 8. Écrans à produire avant développement

1. Sélection société, site et poste ;
2. vente ou encaissement par activité ;
3. réservation Car Rental et inspection ;
4. stock et transfert Auto Parts ;
5. bail, électricité et inspection Guest House ;
6. vente scanner et stock Market ;
7. folio et check-out Hotel ;
8. identité maître client et consentement, réservée au rôle de confidentialité.

## 9. Maquette de validation

La maquette interactive de référence est versionnée dans design/wireframes_mobile_v1.html. Elle montre les six contextes requis à l'écran, les actions formulées explicitement et l'absence d'adresse inventée. Elle sert de base de validation avant la réalisation des écrans Vue.
