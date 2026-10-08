# Importations en lot Excel

## 1. Objectif

L’application fournit un centre d’importation en lot pour charger des données de départ, corriger des référentiels autorisés ou reprendre un historique validé. Les fichiers sont créés à partir de modèles Excel fournis par l’application. Aucun modèle libre, macro Excel ou colonne non reconnue n’est accepté.

Format accepté : `.xlsx`. L’application génère chaque modèle, avec son numéro de version, la description des colonnes et une feuille d’exemples.

## 2. Accès et périmètre

- Seuls le propriétaire, l’administrateur autorisé ou un rôle disposant de la permission explicite `importer_donnees` peuvent lancer un import.
- Avant le téléversement, l’utilisateur choisit la société et, lorsque requis, le site, l’entrepôt, le garage, l’hôtel ou la caisse visée.
- Un import ne peut créer, modifier ou consulter que des données de ce périmètre.
- Un profil client d’une autre société, une transaction, un document sensible, un passeport, une inspection ou un solde ne peut pas être importé par simple rapprochement de nom ou de courriel.
- L’import d’utilisateurs ne permet jamais d’importer un mot de passe. Chaque personne reçoit un lien d’activation ou une réinitialisation de mot de passe avec code envoyé à son courriel personnel.

## 3. Parcours utilisateur

1. L’utilisateur ouvre **Administration > Importations**.
2. Il télécharge le modèle correspondant à son type de données et à sa société.
3. Il complète le fichier sans modifier les en-têtes ni le numéro de version.
4. Il téléverse le fichier.
5. L’application valide toutes les lignes sans écrire de données.
6. Elle affiche un aperçu : nombre de lignes valides, erreurs par ligne, doublons, avertissements et impacts prévus.
7. L’utilisateur confirme l’import après validation complète.
8. L’application crée un rapport téléchargeable contenant le résultat de chaque ligne.

Par défaut, un import est atomique : si une erreur bloquante existe, aucune ligne n’est écrite. Une option de correction par lot peut être ajoutée plus tard pour certains référentiels, mais jamais pour les transactions, les stocks ou les droits d’accès.

## 4. Modèles fournis

| Type | Données principales | Règles de contrôle |
| --- | --- | --- |
| Produits | code interne, code-barres, désignation, catégorie, unité, coûts, prix HTG/USD, taxe, seuil de stock, statut | code produit et code-barres uniques dans la société ; prix et devise obligatoires lorsque vendable |
| Inventaire | produit, site, entrepôt, emplacement, quantité, coût unitaire, lot, date d’expiration, référence | mouvement d’ouverture ou ajustement explicite ; produit et emplacement existants ; quantité et coût contrôlés |
| Utilisateurs | nom, courriel personnel, rôle, société, sites autorisés, statut | courriel unique ; rôles autorisés ; aucune colonne de mot de passe ; activation par courriel |
| Véhicules | numéro interne, catégorie, marque, modèle, année, plaque, VIN, kilométrage, statut, site/garage, tarifs HTG/USD | VIN et plaque contrôlés ; statut limité aux valeurs prévues ; kilométrage non négatif |
| Chambres d’hôtel | code chambre, type, capacité, étage, statut, tarif HTG/USD, petit déjeuner inclus | code unique par hôtel ; type et statut valides ; capacité positive |
| Clients | référence externe, personne ou institution, nom, contacts, adresse, consentement de partage | profil créé dans la société choisie ; identité groupe seulement après consentement explicite et rapprochement contrôlé |
| Fournisseurs | référence, raison sociale, contact, téléphone, courriel, adresse, devise, conditions de paiement | référence unique par société ; contacts normalisés ; aucune information bancaire affichée hors autorisation |
| Transactions | type autorisé, date, référence source, compte/journal, devise, montant, contrepartie, motif | réservé aux soldes d’ouverture et historiques approuvés ; écriture équilibrée ; période verrouillée refusée ; aucune vente ou reçu finalisé existant ne peut être modifié |

## 5. Protection des données et journalisation

Chaque import conserve :

- identifiant du lot ;
- type et version du modèle ;
- empreinte cryptographique du fichier ;
- société, site et périmètre retenus ;
- auteur, date/heure UTC et affichage Cap-Haïtien ;
- nombre de lignes lues, validées, rejetées et écrites ;
- rapport d’erreurs téléchargeable ;
- identifiant de corrélation pour les écritures créées.

Les événements `import.validation_started`, `import.validation_failed`, `import.confirmed`, `import.completed` et `import.rejected` sont ajoutés au journal immuable. Le fichier source et le rapport sont conservés selon une durée de conservation configurée par société.

## 6. Traitement technique

- Lecture Excel en flux afin de rester compatible avec le VPS KVM1 et les fichiers volumineux.
- Validation en arrière-plan, avec état visible : préparé, validation en cours, à corriger, prêt à confirmer, importé, rejeté.
- Contrôles de schéma, types, devises, dates, droits, doublons internes et références étrangères avant écriture.
- Protection contre le double import par empreinte du fichier, type de modèle, société, site et référence d’import.
- Les importations créant des écritures de stock, de dépôt ou comptables exécutent une transaction de base de données et sont annulées entièrement en cas d’échec.

## 7. Critères d’acceptation

- Chaque type ci-dessus offre un bouton **Télécharger le modèle Excel**.
- Un fichier modifié, incomplet ou utilisant une version de modèle non compatible est refusé avec un message précis.
- L’écran d’aperçu ne modifie aucune donnée.
- Une confirmation explicite est nécessaire avant écriture.
- Le rapport de résultat identifie chaque ligne par son numéro Excel et son message.
- Les données restent isolées par société et par site.
- Les imports sont utilisables sur tablette et mobile pour le téléversement et le suivi ; la préparation du fichier Excel se fait normalement sur ordinateur.
