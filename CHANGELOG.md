# Journal des versions

Toutes les modifications notables de ce projet sont documentées ici.

## 0.2.0-alpha.6 - 2026-10-08

### Ajouté

- Gestion des véhicules Car Rental par société et adresse autorisée : code interne, catégorie, kilométrage et état opérationnel.
- Planning par période qui affiche les réservations actives et l’état de la flotte, sans données clients.
- Permissions distinctes pour consulter les véhicules, gérer les véhicules et consulter le planning.
- Contraintes par société pour le code interne, l’immatriculation et le VIN, avec normalisation des identifiants.
- Journalisation de la création d’un véhicule et de chaque changement d’état.
- Tests d’accès par adresse, de non-divulgation dans le planning et d’unicité des identifiants.

### Modifié

- L’écran de connexion mobile présente le formulaire avant le contenu descriptif et utilise des commandes tactiles d’au moins 48 px.
- Les textes de connexion et de vérification utilisent un vocabulaire direct et explicite en français.

### Corrigé

- Les fichiers de déploiement utilisent des noms Traefik statiques et distincts pour la production et la préproduction.
- Les services applicatifs disposent d’un réseau de sortie séparé pour le DNS et le SMTP, sans port entrant publié.

### Limites connues

- Le planning est une vue opérationnelle par période. Le contrat, le check-out, les inspections, le dépôt, le retour, les reçus et l’impression thermique restent à réaliser.

## 0.2.0-alpha.5 - 2026-10-08

### Corrigé

- Rendu du courriel de code de connexion : le modèle d’authentification par courriel est envoyé correctement.

### Vérifié

- Préproduction : routage HTTPS, santé API, PostgreSQL, Redis, SMTP et connexion propriétaire avec code envoyé par courriel.
- La référence technique validée est conservée dans `baseline/0.2.0-alpha.5-preprod` et documentée dans `docs/15_Registre_validation_preproduction_0_2_0_alpha_5.md`.

## 0.2.0-alpha.4 - 2026-10-07

### Ajouté

- Premier périmètre API Car Rental : véhicules SUV, Mid SUV et Pick-up, disponibilité par adresse, réservation concurrente protégée et numéro à huit chiffres affiché au format `0000 0001`.
- Premier écran PWA de réservation : choix obligatoire de la société et de l’adresse autorisée, période, catégorie, véhicule disponible, client, trajet, devise et kilométrage.
- Calendrier métier basé sur les réservations actives : un véhicule réservé ou en circulation ne peut pas être alloué sur une période qui se chevauche.
- Prise en charge et drop-off au site, à l’Aéroport International du Cap-Haïtien ou à une adresse personnalisée.
- Profils clients locaux chiffrés par société ; l’identité maître ne conserve aucune coordonnée et ne donne aucun accès inter-sociétés.
- API de soumission de paiement Car Rental en espèces ou virement Sogebank, avec preuve hashée obligatoire pour un virement et approbation distincte.
- Schéma versionné des dépôts de garantie, inspections pré/post-location, croquis, photos hashées, signatures hashées et kilométrage.
- Tests de réservation, chevauchement, périmètre de site, virement Sogebank et absence de coordonnées bancaires dans l’audit.

### Sécurité

- Les détails de lieu personnalisés, coordonnées client, référence bancaire, notes et légendes d’inspection sont chiffrés au repos par Laravel.
- Toutes les nouvelles données de société Car Rental sont protégées par Row Level Security PostgreSQL et par permission d’API explicite.

### Limites connues

- Le contrat PDF, la capture réelle des signatures et photos, le check-out, l’inspection post-location, le calcul final de kilométrage, le workflow de retenue/libération du dépôt et l’impression sont les prochaines itérations du pilote.
- Aucune donnée client, passeport ou preuve de paiement réelle ne doit être importée avant la recette préproduction et l’activation du stockage chiffré des fichiers.

## 0.2.0-alpha.3 - 2026-10-07

### Ajouté

- Connexion API par mot de passe puis code à six chiffres envoyé au courriel personnel, valable dix minutes, à usage unique et stocké sous hash.
- Réinitialisation personnelle du mot de passe par code courriel, avec révocation de toutes les sessions existantes après succès.
- Jetons de session opaques, conservés uniquement sous empreinte SHA-256, avec durée absolue et délai d’inactivité configurables.
- Modèle d’accès par société avec rôle local, permissions explicites et périmètre de sites ; aucun rôle système ne contourne l’autorisation de société.
- Middleware de société active, corrélation de requête, refus de permission et route de contexte qui ne renvoie que les sites autorisés.
- PWA : écran de connexion, vérification en deux étapes, récupération de mot de passe, choix explicite de société et fermeture de session.
- Commande `php artisan identity:provision-owner` pour créer le premier propriétaire sans jamais mettre son mot de passe dans Git ou une commande.
- Tests de flux 2FA, révocation après réinitialisation, nettoyage des données sensibles dans l’audit et isolement entre sociétés.

### Sécurité

- Argon2id est le hachage par défaut des mots de passe.
- Le serveur refuse de délivrer un code de sécurité lorsque le transport de courriel est `log`, `array` ou un repli non sûr hors tests, afin que le code ne soit jamais écrit dans les journaux.
- Les métadonnées d’audit éliminent récursivement mots de passe, codes, jetons, courriels, téléphones, cookies et données de carte.

### Limites connues

- La préproduction reste sur `MAIL_MAILER=log` : aucun compte humain ne doit donc y être créé avant la configuration d’un SMTP transactionnel réel.
- Taux BRH, caisses, reçus, impression, appareil kiosque, réservations et données Car Rental ne sont pas encore construits.

## 0.2.0-alpha.2 - 2026-10-07

### Corrigé

- Les labels Traefik dynamiques utilisent maintenant la syntaxe liste `clé=valeur` : les noms de routeur et de service sont donc bien interpolés par Docker Compose.
- Le routeur temporaire de préproduction est une vraie solution de repli à priorité faible ; le routeur ERP de préproduction prend explicitement le dessus.
- La validation de déploiement exige désormais la réponse JSON de l'API ERP et non un simple code HTTP 200 provenant d'un service temporaire.

## 0.2.0-alpha.1 - 2026-10-07

### Ajouté

- Socle Laravel 13 versionné dans `apps/api`, avec API de santé, vérification PostgreSQL/Redis et premier contrat de démarrage sans données de production.
- Première migration Laravel pour les sociétés, sites, postes de vente, accès locaux et événements d'audit UUID.
- Isolation RLS PostgreSQL pour les sites, postes et événements d'audit, plus déclencheur empêchant la modification ou suppression du journal d'audit.
- PWA Vue 3 et TypeScript dans `apps/web`, en français, avec l’écran initial Clientèle Rent a Car, le fuseau Cap-Haïtien et une détection de connexion.
- Images Docker applicatives, Nginx/PHP-FPM, compose de build local séparé et workflow GitHub de publication GHCR.

### Limites connues

- Cette préversion ne contient ni authentification, ni 2FA, ni rôles métier, ni taux BRH, ni caisse, ni reçu, ni donnée réelle.
- Elle est réservée à la recette technique de préproduction.

### Corrigé

- L'image PHP conserve désormais les dépendances de compilation jusqu'à l'installation de l'extension Redis, puis retire les outils de build inutiles de l'image finale.
- L'image crée explicitement les répertoires Laravel requis au démarrage ; aucun dossier vide de stockage ne dépend désormais de Git.

## 0.1.6 - 2026-10-07

### Ajouté

- Déploiement Traefik versionné dans `infra/traefik`, avec HTTPS Let's Encrypt, redirection HTTP vers HTTPS, journal d'accès et tableau de bord non exposé.
- Service de vérification temporaire pour obtenir et contrôler les certificats de `erp.clientelegroup.tech` et `preprod.erp.clientelegroup.tech` avant l'arrivée de l'application.
- Script de démarrage reproductible de Traefik pour le VPS dédié.
- La caméra reste disponible sur autorisation du navigateur pour les inspections, photos et futures lectures mobiles ; elle n'est pas bloquée par la politique HTTP globale.

### Modifié

- Le routeur applicatif de production requiert désormais explicitement le résolveur de certificats Let's Encrypt.
- La documentation de déploiement référence les deux sous-domaines réels et les seules règles pare-feu nécessaires : 22, 80 et 443.

## 0.1.5 - 2026-10-07

### Modifié

- La maquette mobile affiche le format complet de date et heure : « 07 octobre 2026 · 10:38 AM », sous le libellé Cap-Haïtien, Haïti.

## 0.1.4 - 2026-10-07

### Ajouté

- Maquette interactive mobile-first versionnée dans design/wireframes_mobile_v1.html, avec écrans explicites pour Car Rental, Auto Parts, Guest House, Market, Hotel et confidentialité client.

## 0.1.3 - 2026-10-07

### Ajouté

- Référence d'identité visuelle Clientèle Group fondée sur le logo et les couleurs fournis : rouge #F70707, bleu #2222E6, noir #0E0E10 et blanc #FEFEFE.
- Actif de logo initial dans apps/web/public/brand/clientele-group-logo.webp et guide d'expérience mobile dans docs/11_Identite_visuelle_et_experience_mobile.md.

### Modifié

- Tous les écrans doivent afficher explicitement la société, le site, l'adresse configurée du site et le point de vente ou de service ; le libellé générique « caisse » seul est interdit.
- L'interface de la première version travaille uniquement en français et n'affiche aucun sélecteur de langue.
- La ville affichée dans l'interface est désormais Cap-Haïtien, Haïti. L'identifiant technique IANA reste America/Port-au-Prince, qui couvre Haïti.

## 0.1.2 - 2026-10-07

### Modifié

- Le KVM1 est retenu pour le démarrage budgétaire, sous un profil de production léger et isolé : Ubuntu 26.04 LTS, Docker, Traefik, sauvegardes hors VPS et seuils explicites de passage au KVM2.
- L'ordre du pilote devient : Car Rental, Auto Parts et motocyclettes, Guest House, Market, puis Hotel, Bar et Restaurant.
- Les neuf caisses connues sont consignées : Car Rental 1, Auto Parts et motocyclettes 3, Market 2, Guest House 1 et Hotel 2.
- La cible d'impression est une imprimante thermique Epson TMIII de 80 mm par caisse ; le modèle exact, l'interface et le pilote restent à valider sur le matériel reçu.
- Le modèle client distingue désormais une identité maître Clientèle Group, des profils strictement locaux par société et un consentement explicite, audité et révocable pour tout partage de données.

## 0.1.1 - 2026-10-07

### Modifié

- Recommandation d'hébergement : KVM2 est le minimum retenu pour la préproduction active et le pilote. KVM1 est limité à une démonstration ou une préproduction très légère.

## 0.1.0 - 2026-10-07

### Ajouté

- Cadrage complet du progiciel Clientèle Group.
- Architecture VPS Hostinger, Docker, Traefik et PostgreSQL.
- Règles de reçus, de kiosque, de PWA hors ligne, de devises et d'audit.
- Modèle initial de données et politiques d'isolation des sociétés.
- Feuille de route, critères de recette et procédures d'exploitation.

### À faire

- Sélection du module pilote.
- Mise en œuvre du noyau applicatif.
- Création de l'environnement de préproduction.
