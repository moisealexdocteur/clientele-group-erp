# Feuille de route produit

## 1. Stratégie de livraison

Les six activités ne doivent pas être codées en même temps. Elles partagent un noyau critique ; ce noyau doit être solide avant d'ajouter les règles propres à chaque métier.

Le module pilote recommandé est Clientèle Hotel, Bar et Restaurant. Il force la réalisation des éléments les plus structurants : réservation, calendrier, folio, dépôt, vente POS, deux devises, reçu thermique, dépense, rôles et rapports. Le POS et la comptabilité produits seront ensuite réutilisés par Market, Gaz Station et Auto Parts.

## 2. Lots de livraison

| Version cible | Contenu | Résultat concret |
| --- | --- | --- |
| 0.1 | Cadrage et fondation de dépôt | Exigences, architecture, données, sécurité et exploitation documentées |
| 0.2 | Noyau plateforme | Connexion, 2FA, sociétés, sites, rôles, audit, devises, paramètres et PWA de base |
| 0.3 | Caisse et impression | Ouverture et fermeture, paiements HTG/USD, reçu QR, écran client, impression 80 mm, hors ligne contrôlé |
| 0.4 | Hotel, Bar et Restaurant | Chambres, réservation, check-in, folio, dépôts, bars, restaurant, dépenses et rapports |
| 0.5 | Guest House | Appartements, baux, électricité, inspections et dépôts |
| 0.6 | Car Rental | Véhicules, disponibilité, contrat, inspections, dépôt et kilométrage |
| 0.7 | Market et Gaz Station | Inventaire, scanner, balance de base, ventes, crédit entreprise et quarts |
| 0.8 | Auto Parts | Multi-entrepôts, prix gros/détail, pièces compatibles, motocyclettes et certificats |
| 1.0 | Production stabilisée | Recette complète, sauvegardes testées, formation, rapports validés et documentation d'exploitation |

Les numéros sont fonctionnels, pas des promesses de date. Une version est livrée seulement lorsque ses critères d'acceptation sont réussis.

## 3. Lot 0.2 - Noyau plateforme

### Obligatoire

- comptes, mots de passe, réinitialisation personnelle et 2FA email ;
- propriétaires, administrateurs globaux, sociétés, sites et rôles locaux ;
- Row Level Security PostgreSQL et preuve d'isolation ;
- paramètres de langue, fuseau, devises et identité visuelle ;
- taux HTG/USD, référence BRH, alerte et dérogation auditée ;
- journal d'audit append-only ;
- paramètres SMTP et modèles de courriel versionnés ;
- gestion des appareils, caisses et écrans clients ;
- PWA installable, cache et état de synchronisation.

### Non négociable

Une démonstration doit prouver qu'un utilisateur de Société A ne peut pas consulter, rechercher, exporter ni manipuler une donnée de Société B.

## 4. Lot 0.3 - Caisse et impression

### Obligatoire

- ouverture et fermeture de session de caisse ;
- vente multi-articles, rabais et taxe configurable ;
- paiement cash HTG/USD, carte, virement et paiement différé autorisé ;
- taux de change figé à la confirmation ;
- reçu client et copie Administration ;
- numéro huit chiffres et QR vérifiable ;
- envoi courriel, préparation WhatsApp et journal de livraison ;
- impression thermique 80 mm ;
- réimpression avec motif ;
- écran client à lien secret, code de jumelage et révocation ;
- bloc de numéros hors ligne, file idempotente et synchronisation ;
- rapport journalier de caisse et export XLSX/PDF.

### Démonstration de fin de lot

Un caissier ouvre sa caisse, réalise une vente mixte USD et HTG, imprime les deux reçus, affiche le panier au client, perd Internet, réalise une vente autorisée, se reconnecte sans doublon, puis clôture avec rapport et audit complet.

## 5. Lot 0.4 - Hotel, Bar et Restaurant

### Hébergement

- chambres, types, tarifs, disponibilité et calendrier ;
- réservation avec avance ou paiement sur place ;
- check-in, folio et check-out ;
- petit déjeuner inclus configurable ;
- shuttle aéroport ;
- dépôt séparé du revenu et libération ou retenue documentée.

### Restauration

- catalogue et prix par point de vente ;
- deux bars, un restaurant, tables et serveur ;
- consommation vers folio ;
- dépenses de cuisine, bar, maintenance et fiscalité ;
- rapports de performance et ventes avec permissions.

### Démonstration de fin de lot

Une réservation devient un séjour, reçoit des consommations au bar, un shuttle, une dépense et un dépôt. Au check-out, le système calcule le solde, libère ou retient le dépôt, produit le reçu et met à jour les rapports.

## 6. Backlog fonctionnel par priorité

| Priorité | Épique | Dépendance |
| --- | --- | --- |
| P0 | Authentification, 2FA, rôles et sociétés | Aucune |
| P0 | Audit append-only et RLS | PostgreSQL |
| P0 | Taux HTG/USD et règle BRH | Sociétés et rôles |
| P0 | Caisses, reçus QR et impression | Identité, taux et audit |
| P0 | PWA hors ligne contrôlée | Caisse et appareil |
| P1 | Réservations, chambres, folios et dépôts | Noyau financier |
| P1 | Bar, restaurant et dépenses | POS et catalogue |
| P1 | Rapports financiers et exports | Transactions confirmées |
| P2 | Guest House | Noyau financier et inspections |
| P2 | Car Rental | Calendrier, dépôts, documents et fichiers |
| P2 | Market | POS, catalogue et stock |
| P3 | Gaz Station | POS, crédit client et quarts |
| P3 | Auto Parts | Stock, multi-entrepôts, prix et documents |

## 7. Définition de terminé

Une fonction est terminée seulement si :

- ses permissions sont définies et testées ;
- son audit est présent ;
- son comportement hors ligne est défini ;
- ses documents et reçus sont validés ;
- ses erreurs sont compréhensibles ;
- ses données appartiennent à une société ;
- ses tests automatisés sont présents ;
- sa documentation française est mise à jour ;
- elle fonctionne en préproduction avec données de test ;
- elle a passé la recette métier sur le matériel réel si impression ou périphérique.

## 8. Points qui exigent une décision avant leur lot

| Sujet | Décision attendue |
| --- | --- |
| Domaine de production | Nom de domaine et sous-domaines à utiliser |
| Imprimantes | Marques, modèles, USB ou réseau, une ou deux imprimantes par poste |
| Paiement par carte | Fournisseur et pays de règlement |
| WhatsApp | Compte Business API ou partage manuel depuis le poste |
| SMTP | Fournisseur d'envoi et domaine expéditeur |
| BRH | Source officielle et personne responsable de sa validation |
| Comptabilité | Plan de comptes, règles TCA/TMS et validation comptable |
| Déploiement | Taille réelle du VPS, espace disque et services déjà présents |
| Formation | Personnes pilotes par module et scénarios de recette |

## 9. Première décision recommandée

Valider le lot 0.2 et le pilote Hotel, Bar et Restaurant. Cela permet de commencer immédiatement le noyau sans inventer les choix matériels ou fiscaux qui doivent être confirmés avant la caisse de production.
