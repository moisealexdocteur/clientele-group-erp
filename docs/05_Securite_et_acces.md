# Sécurité, accès et communication

## 1. Objectif

Le produit manipule de l'argent, des reçus, des informations de clients, des documents d'identité, des photos d'inspection, des contrats et des données de paie. La sécurité fait partie du produit, pas d'une option à ajouter après les ventes.

## État de la préversion 0.2.0-alpha.3

Le socle implémente déjà le mot de passe Argon2id, le code courriel à six chiffres, la réinitialisation personnelle, les sessions opaques révocables, le contexte de société et le journal d’audit. Les codes et jetons sont stockés sous hash ; aucun code ne peut être inscrit dans les journaux de l’application.

La préproduction utilise encore volontairement le transport de courriel `log`. L’application refuse donc d’y émettre un code de sécurité tant qu’un SMTP transactionnel réel n’est pas configuré. Aucun compte humain ne doit être provisionné avant ce réglage.

## 2. Authentification

### 2.1 Comptes

- Chaque personne possède son propre compte. Les comptes partagés sont interdits.
- L'adresse courriel personnelle sert à la réinitialisation et à la 2FA.
- Un compte appartient à un ou plusieurs rôles, mais seulement aux sociétés autorisées.
- Un rôle système, y compris propriétaire, ne contourne jamais les accès locaux : la personne choisit une société autorisée avant de voir des données opérationnelles.
- Le propriétaire peut désactiver un compte, révoquer toutes ses sessions et forcer une nouvelle 2FA.
- Les comptes techniques sont identifiés comme Système, ont un secret séparé et ne peuvent pas se connecter à l'interface humaine.

### 2.2 Mots de passe

- Stockage par hash Argon2id, jamais réversible.
- Mot de passe minimum de 12 caractères, vérifié contre les mots de passe trop communs.
- Limitation et ralentissement des tentatives de connexion.
- Aucun mot de passe ne figure dans un courriel, un journal, un export ou un support.
- L'utilisateur peut réinitialiser son propre mot de passe par un lien ou code temporaire envoyé à son courriel vérifié.

### 2.3 Double authentification par courriel

- La 2FA par courriel est obligatoire pour les propriétaires, administrateurs, comptabilité, dérogations de taux, remboursements et modifications globales.
- Un code ou lien est à usage unique, de courte durée et stocké sous hash.
- Les tentatives sont limitées et journalisées.
- L'envoi échoué ne révèle pas si une adresse existe.
- Le transport `log` est interdit pour les codes de sécurité, car il exposerait leur contenu dans les journaux techniques.
- Une 2FA de secours, comme une clé d'authentification ou TOTP, peut être ajoutée plus tard sans remplacer les règles existantes.

## 3. Autorisation

L'autorisation se vérifie à quatre niveaux :

1. Le compte est authentifié et actif.
2. La société active est autorisée pour ce compte.
3. Le rôle accorde l'action demandée.
4. Les données appartiennent au site et à la portée autorisés.

Le backend applique cette vérification et PostgreSQL applique de nouveau l'isolation de société. Une interface cachée ne constitue jamais une permission.

### 3.1 Clientèle Group : accès minimal entre sociétés

Une identité client peut être commune au groupe, mais les données ne le sont pas.

- L'accès entre sociétés est refusé par défaut, y compris pour la recherche, une URL directe, un export ou une API.
- Le personnel opérationnel ne voit que le profil client de sa société active et les données nécessaires à sa tâche.
- Un service de confidentialité séparé peut rechercher une identité maître uniquement pour dédoublonner ou appliquer un consentement. Il ne fournit pas automatiquement l'historique d'autres sociétés.
- Les coordonnées et préférences d'une autre société exigent un consentement valide, une finalité autorisée et une société destinataire précise.
- Les données sensibles — passeport, inspection, contrat, photos, solde, limite de crédit, dépôt, paiement et document joint — ne sont jamais partagées par ce mécanisme.
- Le propriétaire global ou l'administrateur global ne reçoit pas une vue opérationnelle transversale automatique : il choisit une société et exerce une permission précise, ou passe par le processus de confidentialité.
- Chaque recherche de rapprochement, affichage partagé, création de lien, export, refus et révocation est journalisé.

## 4. Actions sensibles

Les actions suivantes exigent une permission explicite, et selon la politique une nouvelle 2FA ou une approbation :

| Action | Contrôle minimal |
| --- | --- |
| Créer ou désactiver une société | Propriétaire du système |
| Modifier SMTP ou intégration externe | Administrateur global, 2FA |
| Définir un taux de change | Propriétaire ou administrateur autorisé |
| Ignorer l'alerte de taux BRH | Propriétaire, motif obligatoire, audit |
| Modifier un rôle | Administrateur autorisé, audit |
| Annuler ou rembourser | Permission dédiée, motif, limite de montant |
| Accorder crédit entreprise | Superviseur ou propriétaire, limite et preuve |
| Libérer ou retenir dépôt | Rôle autorisé, inspection ou motif |
| Voir un passeport ou document sensible | Rôle restreint, accès journalisé |
| Rapprocher une identité Clientèle Group | Responsable confidentialité, finalité et audit |
| Partager une coordonnée entre sociétés | Consentement valide, portée précise et audit |
| Exporter données ou rapports | Permission export distincte |
| Réimprimer un reçu | Permission caisse, motif et audit |

## 5. Sessions et appareils

- Les sessions expirent après une période d'inactivité paramétrable.
- Les caisses kiosque utilisent un compte de poste limité ou une connexion personnelle selon le flux choisi.
- Le système enregistre l'appareil, version PWA, navigateur et dernière activité.
- Les appareils perdus ou compromis sont révoqués depuis l'administration.
- Les écrans clients possèdent des jetons distincts : ils n'utilisent jamais un compte employé.
- L'ouverture d'une même caisse par deux sessions simultanées est contrôlée par verrou applicatif et Redis.

## 6. Protection des données et fichiers

- HTTPS obligatoire par Traefik.
- Chiffrement au repos pour les fichiers sensibles et les sauvegardes.
- Signature ou hash de tous les fichiers importés.
- Types de fichiers et taille limités ; analyse antivirus ou service de scan à activer avant d'accepter des documents depuis des utilisateurs externes.
- URLs de téléchargement temporaires, signées et vérifiées contre la société de l'utilisateur.
- Les numéros de passeport, cartes, téléphones et courriels sont masqués dans les écrans et journaux lorsque la valeur complète n'est pas nécessaire.

## 7. Courriels et branding Clientèle Group

L'administration globale maintient les modèles de courriel en français avec :

- logo, couleurs, nom de société et coordonnées ;
- objet, pré-en-tête, corps, pied de page légal et lien d'aide ;
- variables sûres comme client, numéro de reçu, montant et lien de document ;
- modèles pour réinitialisation de mot de passe, 2FA, reçu, réservation, rappel, synchronisation et notification générale ;
- aperçu avant activation, envoi de test et historique des versions.

Les annonces à grande échelle passent par une file de travaux avec limites de débit, statut de livraison, désinscription lorsque nécessaire et segmentation par société ou rôle. Elles ne doivent pas bloquer le POS.

## 8. Gestion des incidents

En cas de suspicion d'accès non autorisé :

1. désactiver le compte ou l'appareil ;
2. révoquer les sessions et les liens secrets touchés ;
3. conserver les journaux d'audit ;
4. changer les secrets techniques concernés ;
5. vérifier les opérations, exports et documents consultés ;
6. documenter l'incident et la reprise ;
7. prévenir les personnes concernées selon les obligations applicables.

## 9. Vérifications obligatoires avant production

- revue des rôles et de la matrice de permissions ;
- test d'accès croisé entre deux sociétés ;
- test de réinitialisation et 2FA ;
- test de limitation de connexion ;
- vérification que PostgreSQL refuse une requête sans société active ;
- vérification de l'absence de secrets dans Git et les journaux ;
- test de restauration de sauvegarde ;
- test de révocation d'un écran client et d'un appareil kiosque ;
- test des exports avec masquage et permissions.
- test de profil client commun sans fuite de données entre sociétés ;
- test de consentement, expiration et révocation du partage de coordonnées.
