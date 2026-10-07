# Journal des versions

Toutes les modifications notables de ce projet sont documentées ici.

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
