# Interface modulaire et correctifs Car Rental (0.3.0-alpha.1)

## Objet

Cette version remplace l'interface monolithique de l'alpha.14 par une application organisée en pages, composants et services. Elle corrige dans le même mouvement les blocages P0 de Car Rental listés dans `CLAUDE.md` qui relèvent de l'interface.

Le serveur Laravel, les routes API, les règles métier, les permissions et la base de données ne sont pas modifiés. Les mêmes appels API sont utilisés.

## Raison technique de la restructuration

| Constat alpha.14 | Conséquence |
| --- | --- |
| `App.vue` : 4 492 lignes, 50 états réactifs, 30 appels API | Toute correction touchait un fichier partagé par tous les écrans. |
| `styles.css` : 2 764 lignes globales | Les règles mobiles se contredisaient, d'où les résultats affichés en bas de page. |
| Aucune adresse par écran | Une réservation ou un véhicule ne pouvait pas être rouvert directement ; le bouton Retour du téléphone quittait l'application. |
| Permissions vérifiées dans le gabarit | Les écrans non autorisés restaient atteignables par l'état interne. |

Le `README` de `apps/web` exigeait déjà « des composants par domaine et non par page unique ». Cette version applique cette règle.

## Organisation du code

```
apps/web/src
├── api/            client HTTP, types de l'API, appels Car Rental et configuration
├── stores/         état partagé (Pinia) : application, session, configuration, dialogues
├── router/         adresses des écrans et contrôle d'accès
├── lib/            dates de Cap-Haïtien, devises, libellés, textes (avec tests)
├── composables/    useRequest : chargement, erreur générale, erreurs par champ
├── components/
│   ├── ui/         champ, pastille, alerte, panneau de tâche, confirmation, icônes
│   ├── layout/     coquille : barre du haut et onglets au téléphone, rail sur grand écran
│   ├── system/     champs d'accès utilisateur
│   └── rental/     ligne de réservation, ligne et vignette de véhicule, mise en circulation
├── views/          un fichier par écran
└── styles/main.css système visuel unique (jetons, typographie, contrôles)
```

## Adresses des écrans

| Adresse | Écran | Permission |
| --- | --- | --- |
| `/connexion` | Se connecter | Aucune |
| `/connexion/code` | Code de vérification | Demande de code en cours |
| `/connexion/mot-de-passe` | Recevoir un code de réinitialisation | Aucune |
| `/connexion/mot-de-passe/code` | Nouveau mot de passe | Demande de code en cours |
| `/societes` | Choisir une société | Session |
| `/location` | Aujourd'hui : départs, retours, retards, flotte | Société active |
| `/location/reservations` | Liste et recherche | `rental.reservations.read` |
| `/location/reservations/nouvelle` | Nouvelle réservation | `rental.reservations.create` |
| `/location/reservations/:id` | Détail et actions | `rental.reservations.read` |
| `/location/planning` | Planning véhicules par jour | `rental.calendar.read` |
| `/location/vehicules` | Liste de la flotte | `rental.vehicles.read` |
| `/location/vehicules/nouveau` | Ajouter un véhicule | `rental.vehicles.manage` |
| `/location/vehicules/:id` | Fiche véhicule | `rental.vehicles.read` |
| `/configuration` | Sociétés | Propriétaire du système |
| `/configuration/societes/:id` | Adresses et caisses | Propriétaire du système |
| `/configuration/societes/:id/utilisateurs` | Utilisateurs | Propriétaire du système |

Le contrôle dans le routeur sert à l'affichage. Le serveur reste la seule autorité qui applique les droits.

Après un rafraîchissement, la session et la société active sont rétablies depuis `sessionStorage`. Fermer l'onglet ferme la session, comme auparavant.

## Correctifs P0 couverts

### Disponibilité et navigation

| Exigence | Réalisation |
| --- | --- |
| Résultats dans le même panneau que les critères | Le panneau « Période et véhicule » contient les dates, la catégorie et la liste des véhicules disponibles. |
| Chargement immédiat avec les valeurs par défaut | À l'ouverture : maintenant, retour demain même heure, bureau actif, toutes catégories. La liste se recharge seule à chaque changement. |
| Rendu mobile | Une colonne, résumé et bouton d'enregistrement dans une barre fixe au-dessus des onglets. |
| Tout est cliquable | Lignes de réservation (Aujourd'hui, liste, fiche véhicule), barres du planning, véhicules (liste, planning, détail de réservation). |
| Détail dédié avec droits | Une adresse par réservation et par véhicule. Les boutons d'action dépendent de l'état et des permissions. |

### Fiche véhicule

Sections courtes : état opérationnel (choix tactile avec confirmation), réservations actives, documents, tarification, identité. Tarif, plaque et documents se modifient dans des panneaux séparés, uniquement avec `rental.vehicles.manage`. La photo choisie dans la fiche est utilisée partout dans l'interface. Sans photo, une illustration de catégorie est affichée et décrite comme telle.

### Mise en circulation

Le panneau affiche, sans ressaisie :

- le calcul de la location (jours × tarif, plus frais aéroport) et le montant approuvé ;
- le dépôt minimum requis et le dépôt retenu ;
- l'état du permis du conducteur.

Les boutons « Encaisser la location » et « Encaisser le dépôt » préremplissent le montant manquant. Le bouton « Mettre en circulation » reste désactivé tant qu'une exigence manque, et chaque exigence manquante est nommée.

## Points P0 qui demandent une évolution de l'API

Ces points ne sont pas couverts par cette version, car l'API ne les prend pas encore en charge. Ils sont prévus dans la prochaine itération :

1. Interrupteur `Actif` ou `Inactif` sur la fiche véhicule : aucune route de modification de `is_active`.
2. Photo du permis de conduire : aucune route de dépôt de fichier, stockage contrôlé ou hash.
3. Pays et province ou État émetteur du permis : champs absents du modèle.
4. Virement Sogebank avec pièce justificative : l'API exige une clé de stockage et un hash, sans route de dépôt. L'interface propose donc uniquement l'espèce, comme l'alpha.14.
5. Lecture unitaire d'un véhicule : la fiche filtre la liste autorisée. Une route `GET /vehicles/{id}` est souhaitable.

## Système visuel

- Une seule famille, Archivo, embarquée dans l'application : aucune dépendance à Google Fonts, rendu identique hors ligne.
- Signature : numéros de réservation, plaques, heures et montants en Archivo très large et très gras.
- Couleurs : fond `#F3F4F7`, encre `#17181D`, rouge Clientèle `#D7141A` réservé aux actions principales, bleu `#2430D9` pour la sélection.
- Cibles tactiles de 48 px minimum, 54 px pour l'action principale.
- Téléphone : onglets en bas. À partir de 960 px : rail de navigation à gauche.
- Panneaux de tâche natifs (`<dialog>`) : ils montent du bas sur téléphone et s'ouvrent au centre sur grand écran.

## Hors ligne

Le service worker sert désormais la coquille de l'application pour toutes les adresses, y compris hors ligne, et garde en cache les fichiers compilés. Les réponses `/api` ne sont jamais mises en cache. La saisie hors ligne avec file d'attente (IndexedDB) reste à réaliser avec le module de caisse.

## Vérifications

| Vérification | Commande ou méthode |
| --- | --- |
| Types | `npm run typecheck` |
| Tests unitaires (dates, devises, textes) | `npm test`, réussis avec les fuseaux UTC, Toronto, Tokyo et Port-au-Prince |
| Compilation | `npm run build` |
| Rendu | Captures à 390 px (téléphone) et 1280 px (ordinateur) sur une API de démonstration |
| API | `php artisan test` dans la CI GitHub (aucune modification serveur dans cette version) |
