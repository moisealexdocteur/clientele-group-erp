# Cahier des charges fonctionnel

## 1. But du produit

Clientèle Group ERP est un progiciel web sur mesure qui permet à plusieurs sociétés d'exploiter leurs activités avec les mêmes règles de contrôle, sans mélanger les relations clients, les stocks, les caisses, les employés ou les rapports.

Le système doit être utilisable au comptoir sur écran tactile, sur tablette, sur mobile et au bureau. Il doit continuer à enregistrer les opérations critiques lors d'une coupure Internet contrôlée, puis les synchroniser sans doublon.

## 2. Structure métier commune

| Niveau | Description |
| --- | --- |
| Groupe | Clientèle Group, propriétaire de la plateforme et des configurations globales |
| Société | Entité juridique ou opérationnelle isolée : Hotel, Guest House, Car Rental, Gaz Station, Market ou Auto Parts |
| Site | Établissement ou emplacement : hôtel, bar, restaurant, magasin, entrepôt, station, garage ou bureau |
| Point de service | Caisse, réception, terminal de vente, poste de pompiste ou poste de réservation |
| Utilisateur | Personne ou compte technique ayant un rôle, des permissions et une trace d'activité |

Une même société peut posséder plusieurs sites. Un utilisateur n'accède qu'aux sociétés, sites et actions explicitement autorisés.

## 3. Règles transversales

### 3.1 Langue, date et fuseau horaire

- Langue par défaut : français.
- Langues préparées pour l'avenir : créole haïtien et anglais.
- Le serveur enregistre toutes les dates en UTC.
- L'interface affiche par défaut America/Port-au-Prince : jour mois année heure AM ou PM. Exemple : 07 octobre 2026 08:54 AM.
- Une date de caisse, une date comptable et une date système sont distinguées lorsque cela est nécessaire.

### 3.2 Devises et taux de change

- Devises de base : HTG et USD.
- Chaque société définit sa devise de tenue de livres et peut accepter les deux devises.
- Un paiement conserve sa devise, son montant, le taux appliqué, sa contre-valeur et l'auteur de la décision.
- Seuls le propriétaire et l'administrateur autorisé peuvent créer ou modifier un taux.
- Le système compare tout taux manuel HTG par USD avec la référence BRH conservée dans le système. Si le taux saisi est inférieur à la référence officielle, une alerte bloquante apparaît sauf dérogation explicite du propriétaire avec motif.
- Le taux déjà appliqué à une vente confirmée, un dépôt, un remboursement ou une paie est figé. Toute correction produit une opération inverse ou un ajustement.
- La méthode de mise à jour de la référence BRH est paramétrable : saisie vérifiée, import officiel ou intégration approuvée. Elle ne doit jamais dépendre d'un affichage web non vérifié.

### 3.3 Numérotation et QR de reçu

- Toute vente ou encaissement confirmé reçoit un numéro visible de huit chiffres présenté en deux blocs : 1234 5678.
- Le numéro brut est unique par société et ne se réutilise jamais.
- Une référence technique UUID garantit l'unicité interne même si la numérotation atteint sa limite.
- Le QR mène à une page publique de vérification de reçu. Il ne révèle aucune donnée personnelle et contient un jeton signé, non devinable et révocable.
- Les numéros réservés hors ligne sont préalloués au poste de caisse. Ils restent uniques après synchronisation.

### 3.4 Reçus et communications

Chaque paiement ou vente imprimable produit deux modèles :

| Modèle | Destinataire | Contenu minimal |
| --- | --- | --- |
| Reçu client | Client | Société, date, numéro, lignes, quantité totale d'articles, prix, taxes, paiements, taux s'il y a lieu, total, QR, en-tête et pied de page |
| Copie Administration | Caisse et comptabilité | Tous les éléments du reçu client, caissier, poste, détails de règlement, statut de synchronisation, traces de réimpression et mention ADMINISTRATION |

- Les en-têtes, pieds de page, logo, coordonnées, texte légal et message promotionnel sont configurables par société.
- L'envoi par courriel s'effectue via le serveur SMTP configuré.
- L'envoi WhatsApp utilise soit une intégration WhatsApp Business approuvée, soit un lien de partage préparé à partir du téléphone du personnel. Aucun message WhatsApp ne doit être prétendu envoyé sans confirmation du fournisseur.
- Chaque envoi est journalisé avec destinataire masqué, modèle, statut et identifiant de fournisseur.
- Toute réimpression doit demander un motif et laisser une trace.

### 3.5 Audit obligatoire

Les actions suivantes sont obligatoirement journalisées : connexions, déconnexions, tentatives d'accès refusées, changements de rôles, taux de change, ouverture et fermeture de caisse, ventes, annulations, remises, retours, remboursements, ajustements de stock, dépenses, dépôts, paie, exports, envois de reçus, modifications de configuration et opérations système.

Chaque événement comprend au minimum : horodatage UTC, horodatage affiché Haïti, société, site, utilisateur ou système, appareil, adresse IP si disponible, type d'action, objet concerné, avant et après lorsque pertinent, raison et identifiant de corrélation.

Le journal est en ajout seulement. Une correction y ajoute un nouvel événement, elle ne modifie pas l'événement initial.

### 3.6 Comptabilité et fiscalité

- Les règles comptables utilisent un profil fiscal par société, avec plan de comptes, journaux, taxes, méthodes d'arrondi et devises.
- TCA, TMS et toute retenue applicable sont paramétrables, datés et validés avant activation par le comptable local de Clientèle Group.
- Les opérations de dépôt de garantie, avances clients, crédits entreprises, taxes, ventes et dépenses sont séparées comptablement.
- Rapports imprimables et exportables : PDF et XLSX. Aucun tableau ne doit seulement exister à l'écran.

### 3.7 Clientèle Group : client commun, données séparées

Dans la pratique, une même personne ou institution peut utiliser plusieurs services de Clientèle Group. Le système doit donc éviter de recréer inutilement cette personne tout en protégeant chaque relation commerciale.

- Une identité maître de groupe reçoit une référence interne Clientèle Group. Elle sert à rapprocher les doublons sous contrôle ; elle ne donne pas accès aux opérations des sociétés.
- Chaque société possède son propre profil client, relié à cette identité seulement lorsque le rapprochement est confirmé. Réservations, locations, factures, folios, créances, dépôts, inspections, passeports, photos, documents, historique et préférences restent dans le profil de la société.
- Par défaut, aucun employé d'une société ne peut rechercher, consulter, exporter ou déduire les informations détenues par une autre société.
- Les coordonnées ou préférences communes ne peuvent être partagées qu'après un consentement clair, avec finalité, catégories de données, société destinataire, date, échéance éventuelle et révocation. Le consentement ne partage jamais les transactions ni les documents sensibles.
- Le rapprochement, la création d'un lien, toute consultation de données partagées et toute révocation sont journalisés. Aucun rapprochement automatique irréversible n'est permis.
- Les identifiants, passeports, contrats, inspections, solde, crédit et documents de paiement sont considérés sensibles : ils ne sont jamais visibles dans une autre société, même si le client est connu du groupe.

## 4. Comptes, rôles et droits

| Rôle | Portée | Accès principal |
| --- | --- | --- |
| Propriétaire du système | Groupe | Tous les paramètres, accès aux sociétés par sélection explicite, dérogations de sécurité et approbations sensibles |
| Administrateur global | Groupe | Configuration globale, sociétés, utilisateurs, modèles de courriels, taux et supervision, sans vue opérationnelle transversale implicite |
| Responsable confidentialité groupe | Groupe | Rapprochement d'identité, consentements et traitement des demandes, sans accès implicite aux opérations des sociétés |
| Propriétaire de société | Société | Paramètres et rapports de sa société, approbations définies |
| Administrateur de société | Société | Utilisateurs locaux, sites, catalogue, opérations et rapports autorisés |
| Superviseur | Site | Approbations limitées, clôtures, crédits, retours et rapports du site |
| Réception | Hôtel ou Guest House | Réservations, check-in, check-out, folios et encaissements autorisés |
| Serveuse ou serveur | Bar et restaurant | Commandes, tables, service et encaissements accordés |
| Préposé location | Car Rental | Réservations, états de véhicules, contrats et inspections autorisés |
| Pompiste | Station | Ventes comptant, fin de quart et reçu |
| Vendeur | Market ou Auto Parts | Vente, retours autorisés et caisse |
| Comptabilité | Société | Dépenses, écritures, rapprochements et rapports, sans changer les opérations originales |
| Lecture seule | Société ou site | Consultation et export explicitement autorisés |

Les permissions réelles sont granulaires : voir, créer, modifier avant validation, annuler, rembourser, approuver, exporter, configurer et administrer. Un rôle ne donne jamais automatiquement accès à une autre société.

## 5. Paramètres globaux restreints

L'espace Paramètres globaux est limité au propriétaire et aux administrateurs globaux. Il contient :

- sociétés, sites, devises et profil comptable ;
- utilisateurs, rôles, permissions et état des comptes ;
- SMTP, modèles de courriels, identité visuelle Clientèle Group et campagnes de notification ;
- références et taux de change ;
- politiques de mot de passe, 2FA, délai de session et appareils kiosque ;
- intégrations de paiement, WhatsApp, stockage de fichiers et sauvegardes ;
- écrans clients, promotions, liens secrets et durée de validité ;
- modèles de documents, contrats, certificats numérotés et reçus ;
- paramètres d'isolation par société, identité maître client, consentements, rétention et archivage.

## 6. Noyau de vente et de caisse

### 6.1 Cycle de caisse

1. L'utilisateur ouvre sa caisse avec un fond de caisse et les montants USD et HTG.
2. Il réalise les ventes, retours, encaissements et décaissements autorisés.
3. Chaque opération identifie le point de service, l'utilisateur, le client si connu, les articles ou services, les règlements et le taux appliqué.
4. Le reçu client et la copie Administration peuvent être imprimés automatiquement selon le profil du poste.
5. L'utilisateur clôture le quart. Le système compare le théorique aux montants déclarés par devise et demande une explication pour tout écart.
6. Le superviseur approuve les écarts hors tolérance.
7. Le rapport journalier est imprimable en 80 mm ou A4 selon le poste et toujours exportable.

### 6.2 Modes de paiement

- Espèces HTG
- Espèces USD
- Carte de crédit ou débit
- Virement bancaire approuvé
- Paiement différé ou crédit client autorisé
- Dépôt de garantie
- Avoir, remise ou remboursement autorisé

Un règlement peut combiner plusieurs modes. Les montants saisis dans une devise ne sont jamais convertis silencieusement dans l'autre.

### 6.3 Client individuel et institutionnel

- Fiche client locale avec consentement de communication, coordonnées et historique de la société active.
- Proposition contrôlée de rattachement à une identité maître Clientèle Group ; elle n'affiche pas l'historique, les soldes ni les documents des autres sociétés.
- Consentement distinct pour tout partage de coordonnées ou de préférences entre sociétés ; un refus ou une révocation n'empêche pas la prestation de service locale.
- Compte institutionnel avec limite de crédit, approbateurs, pièces requises, échéance et solde.
- Toute vente à crédit exige un client institutionnel approuvé ou une autorisation tracée.

## 7. Écran client et promotion

Chaque point de vente peut être associé à un écran client séparé. L'écran montre uniquement les articles en cours, quantités, total, devise, message promotionnel et remerciement. Il ne montre jamais les détails d'administration, les mots de passe ou les données d'un autre client.

Le lien d'écran utilise un jeton aléatoire à forte entropie et un code de jumelage de courte durée. L'administrateur peut :

- activer, désactiver ou révoquer l'écran ;
- nommer l'écran et l'associer à un site et une caisse ;
- changer le visuel promotionnel, sa durée et son ordre ;
- voir la dernière connexion ;
- forcer une déconnexion ;
- exiger un nouveau code de jumelage.

## 8. Module Clientèle Hotel, Bar et Restaurant

### 8.1 Réservation et hébergement

- Gestion de 14 chambres, types de chambres, tarifs, disponibilité, calendrier et statuts.
- Réservation payée à l'avance, partiellement payée ou payée sur place.
- Petit déjeuner inclus configurable par tarif ou séjour.
- Services de shuttle aéroport avec date, heure, passager, destination, prix, chauffeur et statut.
- Check-in, séjour actif, check-out et envoi du reçu par courriel ou WhatsApp.
- Un folio regroupe chambre, nuitées, bar, restaurant, minibar, room service, blanchisserie, transport, dommages et frais additionnels.
- Dépôt de garantie distinct du revenu : requis, reçu ou autorisé, partiellement utilisé, libéré ou remboursé, retenu.
- Rapport d'occupation, revenus par chambre, durée moyenne, no-show, annulations et services auxiliaires.

### 8.2 Bar et restaurant

- Deux bars et un restaurant avec cartes de produits, tables, commandes, transfert de table, service et paiement.
- Prix et encaissement en HTG, USD, carte ou paiement différé autorisé.
- Liaison optionnelle d'une consommation au folio hôtel.
- Reçu 80 mm immédiat pour la caisse et copie Administration.
- Rapport de ventes journalier par site, bar, restaurant, caissier, serveur, devise, moyen de paiement et catégorie.
- Dépenses catégorisées : cuisine, bar, maintenance, fiscalité et autres dépenses approuvées.

### 8.3 Paie

- Paie du personnel en HTG.
- Périodes de paie, salaire, heures ou forfait, déductions, pénalités, avances, approbation et reçu de paie.
- Les règles TMS, retenues et charges sont paramétrables et ne sont mises en production qu'après validation comptable.

## 9. Module Clientèle Guest House

- Appartements loués à court ou long terme, en USD ou HTG.
- Calendrier, contrats, durée, renouvellement, renouvellement de bail et encaissements.
- Charge d'électricité à percevoir en HTG avec fournisseur ou règle configurable.
- Dépôt de garantie en banque ou espèces, en USD ou HTG, séparé du revenu.
- Inspection d'entrée et de sortie : pièces, compteurs, équipement, photos, anomalies, signatures et rapport imprimable.
- Envoi du rapport d'inspection par courriel ou WhatsApp.
- Paie du personnel et rapports comptables de la société.

## 10. Module Clientèle Car Rental

- Catalogue de SUV, mid SUV et pick-up, avec plaque, état, kilométrage, photos, assurance, documents et tarifs.
- Réservation par disponibilité réelle, avec calendrier global et par véhicule.
- États obligatoires : réservé, en circulation, en préparation, lavage, au garage, réparation, disponible.
- Drop-off et prise en charge à l'aéroport du Cap-Haïtien, avec frais, horaire et responsable.
- Dépôt de garantie en USD ou HTG, ou dépôt de passeport US lorsqu'autorisé. La garde, la remise et la libération du passeport sont tracées de manière restreinte.
- Paiement espèces ou virement ou dépôt Sogebank avec photo de la pièce et approbation par personne autorisée.
- Contrat de location numéroté et signé par le client et Clientèle Car Rental.
- Inspection pré-location : croquis des dommages, photos, odomètre, carburant, kilométrage, accessoires et signatures.
- Inspection post-location : état final, nouveaux dommages, kilomètres, frais, libération ou retenue du dépôt.
- Kilométrage limité ou illimité selon contrat.
- Paie et rapports comptables adaptés à la société.

## 11. Module Clientèle Gaz Station

- Produits : gasoline, diesel et kérosène.
- Vente au grand public ou aux entreprises.
- Vente comptant par pompiste avec session de quart et reçu.
- Vente à crédit seulement pour une entreprise approuvée par superviseur ou propriétaire.
- Le modèle ne dépend pas d'une intégration automatique avec les pompes. Les quantités et preuves de vente sont saisies ou importées selon l'équipement réellement disponible.
- Rapport par produit, pompiste, client entreprise, quart, devise, paiement et créance.
- Paie du personnel.

## 12. Module Clientèle Market

- Catalogue article avec code-barres, prix, taxe, fournisseur, catégorie, unité, coût, seuil de réapprovisionnement et date d'expiration.
- Bon de commande, bon de livraison fournisseur, facture fournisseur, réception, ajustement et justification.
- Inventaire par site et emplacement.
- Point de vente avec scanner de code-barres et balance. La première intégration vise les périphériques qui se présentent comme clavier USB ; les balances réseau ou série font l'objet d'un connecteur identifié.
- Étiquettes et pricer configurables.
- Retour client, retour en stock, perte, péremption et effet comptable journalisé.
- Rapports journaliers, hebdomadaires, mensuels, annuels et personnalisés, avec graphiques.
- Rôles : propriétaire, administrateur, superviseur, vendeur et serveur, selon le site.
- Paie en HTG et export comptable.

## 13. Module Clientèle Auto Parts

- Deux magasins et deux entrepôts avec inventaire séparé et transferts tracés.
- Vente en gros ou détail, listes de prix distinctes et conditions client.
- Vente en espèces HTG, avec préparation pour d'autres règlements sans les activer sans décision.
- Factures fournisseurs, arrivages, commandes, réceptions et fournisseurs.
- Un produit peut être stocké dans un entrepôt différent du magasin vendeur.
- Dépenses générales, transport, manutention, coûts de revient et justificatifs.
- Vente de motocyclettes Haojin par modèle, options, dépôt, solde, livraison différée et suivi de remise.
- Certificats d'immatriculation et de vente standardisés, numérotés et rattachés au stock de formulaires fourni par le fournisseur en Haïti.
- Pièces de motocyclettes avec photos, compatibilité par marque, modèle et année lorsque connue.
- Affichage client des articles et promotions sur écran associé au comptoir.

## 14. Rapports communs

Tous les rapports respectent les permissions. Un utilisateur voit seulement les sociétés, sites, devises, opérations et périodes autorisés.

- Tableau de bord exécutif : ventes, encaissements, dépenses, marge, créances, dépôts, écarts de caisse et alertes.
- Ventes par société, site, caisse, employé, client, article, service, devise, mode de paiement et période.
- Journal de caisse, rapport de fermeture, écarts et réimpressions.
- Stock, ruptures, valorisation, expirations, mouvements et écarts.
- Dépenses, comptes fournisseurs et justificatifs.
- Clients institutionnels, crédits, limites, échéances et recouvrements.
- Paie, déductions, pénalités, avances et décaissements.
- Rapports comptables et fiscaux paramétrés, disponibles en affichage, impression, PDF et XLSX.

## 15. Fonctions hors ligne et PWA

- L'application s'installe comme PWA sur les appareils autorisés.
- Le shell de l'application, les catalogues autorisés, prix, taux, promotions et paramètres nécessaires au poste sont chiffrés dans le stockage local du navigateur.
- Les transactions hors ligne entrent dans une file locale avec UUID, numéro préalloué, horodatage d'appareil et signature de poste.
- La synchronisation est idempotente : envoyer deux fois la même opération ne crée jamais deux ventes.
- Les courriels, WhatsApp, exports et appels de carte qui nécessitent Internet restent en attente avec statut visible.
- Une caisse ne peut pas être clôturée définitivement tant que les opérations critiques ne sont pas synchronisées ou explicitement remises à un superviseur.
- Le stock hors ligne est contrôlé par une quantité autorisée et une réserve de sécurité afin de limiter les surventes.

## 16. Hors périmètre initial

Les éléments suivants ne seront ajoutés qu'après étude spécifique : intégration bancaire automatique, passerelle de carte choisie, facturation électronique gouvernementale, intégration de pompe à carburant, paie avec calcul légal automatique non validé, intégration de balance réseau, synchronisation avec ERP tiers et application mobile native.
