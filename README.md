# Clientèle Group ERP

Progiciel web sur mesure, multi-sociétés conçu pour Clientèle Group en Haïti.

Le produit regroupe un noyau commun sécurisé, puis des modules métiers indépendants pour chaque société :

- Clientèle Hotel, bar et restaurant
- Guest house et appartements
- Clientèle Rent a car pour Location de véhicules
- Station-service
- Market
- Auto parts et motocyclettes

La première version de ce dépôt est une fondation de produit. Elle fixe les règles comme : isolation des sociétés, audit, devises HTG/USD, impression de reçus, mode hors ligne, contrôle d'accès et déploiement Docker.

## Principes non négociables

- Données séparées par société, site et caisse.
- Une même personne peut avoir une identité maître Clientèle Group, mais chaque société conserve son profil, son historique et ses documents isolés. Tout partage de coordonnées est consenti, limité, révocable et audité.
- Toutes les opérations importantes sont journalisées avec date, heure, utilisateur ou système et appareil.
- Les montants, taux de change et écritures déjà confirmés ne sont jamais modifiés silencieusement : une correction crée une annulation ou un ajustement traçable.
- Le serveur conserve toutes les dates en UTC. L'interface affiche Cap-Haïtien, Haïti, au format 07 octobre 2026 08:54 AM ; la configuration technique utilise l'identifiant IANA America/Port-au-Prince.
- Toute vente confirmée porte un numéro de huit chiffres au format 1234 5678 et un QR de vérification.
- Le reçu client et la copie Administration sont issus de la même transaction, mais ont une mise en page et un niveau d'information distincts.
- Aucun secret, mot de passe, clé SMTP, clé WhatsApp ou donnée de production ne va dans Git.

## Architecture retenue

- Backend : Laravel et PHP, API REST, travaux asynchrones et génération de documents.
- Interface : Vue et TypeScript, PWA tactile, utilisable sur téléphone, tablette et poste de caisse.
- Données : PostgreSQL avec politiques d'isolation par société.
- File de travaux et cache : Redis.
- Déploiement : Docker Compose derrière le Traefik déjà présent sur un Hostinger KVM1 dédié, sous Ubuntu 26.04 LTS, avec un passage planifié au KVM2 selon la charge.
- Impression : navigateur Edge ou Chrome en kiosque et imprimante thermique 80 mm locale.

Les versions exactes des dépendances sont gelées au démarrage de l'implémentation et mises à jour par correctifs contrôlés, jamais à l'aveugle en production.

## Documents de référence

| Document | Rôle |
| --- | --- |
| docs/01_Cahier_des_charges_fonctionnel.md | Fonctions, règles métiers et modules |
| docs/02_Architecture_technique.md | Architecture applicative et infrastructure |
| docs/03_Impression_et_mode_kiosque.md | Reçus thermiques, écrans clients et postes de caisse |
| docs/04_Donnees_et_audit.md | Données, numérotation, journalisation et hors ligne |
| docs/05_Securite_et_acces.md | Comptes, 2FA, permissions et isolement |
| docs/06_Feuille_de_route.md | Lots de livraison et critères de priorisation |
| docs/07_Exploitation_et_deploiement.md | VPS, Docker, Traefik, sauvegardes et supervision |
| docs/08_Recette_et_criteres_d_acceptation.md | Tests de réception avant production |
| docs/10_Profil_KVM1_et_sites.md | Profil KVM1, inventaire des caisses et seuils d'évolution |
| docs/11_Identite_visuelle_et_experience_mobile.md | Logo, couleurs et règles mobile-first |

## État du projet

Version : 0.2.0-alpha.1

Cette préversion contient le cadrage produit, les décisions d'architecture, le contrat de données du noyau, l'amorce Traefik HTTPS et le premier socle exécutable Laravel 13 + Vue PWA. Le pilote est ordonné : Car Rental, Auto Parts et motocyclettes, Guest House, Market, puis Hotel, Bar et Restaurant. Aucun module métier n'est encore déclaré prêt pour la production et aucune donnée réelle ne doit être chargée.

## Règles de version

Le dépôt suit SemVer :

- Version majeure : changement incompatible de données, sécurité ou API.
- Version mineure : nouveau module ou fonction complète.
- Version corrective : anomalie corrigée sans rupture.

Chaque changement fonctionnel ou technique met à jour CHANGELOG.md et, lorsque pertinent, un document dans docs.

## Démarrage de l'implémentation

1. Terminer l'authentification, les sociétés, les rôles, l'audit et la configuration globale du lot 0.2.
2. Ajouter les migrations financières : devises, taux BRH, sessions de caisse et reçus.
3. Construire le POS et l'impression 80 mm avant d'ouvrir les modules métiers.
4. Déployer d'abord le socle sur `preprod.erp.clientelegroup.tech` avec des données de test.
5. Construire Car Rental en premier, puis suivre l'ordre validé des modules.

## Licence

Code propriétaire de Clientèle Group par Moise Alex Docteur. Toute reproduction, diffusion ou utilisation sans autorisation écrite est interdite.
