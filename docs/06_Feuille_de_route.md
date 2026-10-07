# Feuille de route produit

## 1. Stratégie de livraison

Les six activités ne doivent pas être codées en même temps. Elles partagent un noyau critique ; ce noyau doit être solide avant d'ajouter les règles propres à chaque métier.

Le pilote est fixé dans l'ordre suivant : Clientèle Car Rental, Clientèle Auto Parts et motocyclettes, Clientèle Guest House, Clientèle Market, puis Clientèle Hotel, Bar et Restaurant en dernier. Cet ordre est volontaire : il construit d'abord les contrats, dépôts, inspections, stock et caisses avant le folio hôtelier et la restauration. Gaz Station est une phase suivante ; elle ne retarde pas le pilote.

## 2. Lots de livraison

| Version cible | Contenu | Résultat concret |
| --- | --- | --- |
| 0.1 | Cadrage et fondation de dépôt | Exigences, architecture, données, sécurité et exploitation documentées |
| 0.2 | Noyau plateforme | Connexion, 2FA, sociétés, sites, rôles, audit, devises, paramètres et PWA de base |
| 0.3 | Caisse et impression | Ouverture et fermeture, paiements HTG/USD, reçu QR, écran client, impression 80 mm, hors ligne contrôlé |
| 0.4 | Car Rental | Véhicules, disponibilité, contrat, inspections, dépôt et kilométrage |
| 0.5 | Auto Parts et motocyclettes | Multi-entrepôts, prix gros/détail, pièces compatibles, motocyclettes et certificats |
| 0.6 | Guest House | Appartements, baux, électricité, inspections et dépôts |
| 0.7 | Market | Inventaire, scanner, balance de base, ventes et retours |
| 0.8 | Hotel, Bar et Restaurant | Chambres, réservation, check-in, folio, dépôts, bars, restaurant, dépenses et rapports |
| 0.9 | Gaz Station | Produits, quarts, ventes comptant et crédit entreprise |
| 1.0 | Production stabilisée | Recette complète, sauvegardes testées, formation, rapports validés et documentation d'exploitation |

Les numéros sont fonctionnels, pas des promesses de date. Une version est livrée seulement lorsque ses critères d'acceptation sont réussis.

### État réel au 7 octobre 2026

La préversion `0.2.0-alpha.1` rend le socle exécutable : Laravel 13, une PWA Vue, des images Docker, PostgreSQL, Redis, un endpoint de santé et la première migration de sociétés, sites, postes et audit. Elle ne valide pas encore le lot 0.2 : authentification, 2FA, rôles, taux de change, appareils et configuration métier restent à construire. Elle ne doit donc pas recevoir de données réelles ni être déclarée prête à la production.

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

## 5. Lot 0.4 - Car Rental

### Location

- véhicules, catégories, disponibilité, statuts et calendrier global ;
- réservation de véhicule par le préposé selon la disponibilité réelle ;
- prise en charge et drop-off à l'aéroport du Cap-Haïtien ;
- contrat numéroté, dépôt en HTG ou USD et dépôt de passeport optionnel restreint ;
- inspection avant et après location, croquis, photos, odomètre, kilométrage et signatures ;
- paiement espèces, virement ou preuve de dépôt Sogebank approuvée ;
- libération ou retenue de dépôt avec motif, document et audit.

### Démonstration de fin de lot

Un préposé réserve un véhicule disponible, confirme le dépôt, génère le contrat, consigne l'inspection et les photos, puis réalise le retour, le calcul de kilométrage et la libération ou retenue du dépôt avec audit complet.

## 6. Backlog fonctionnel par priorité

| Priorité | Épique | Dépendance |
| --- | --- | --- |
| P0 | Authentification, 2FA, rôles et sociétés | Aucune |
| P0 | Audit append-only et RLS | PostgreSQL |
| P0 | Taux HTG/USD et règle BRH | Sociétés et rôles |
| P0 | Caisses, reçus QR et impression | Identité, taux et audit |
| P0 | PWA hors ligne contrôlée | Caisse et appareil |
| P1 | Car Rental | Calendrier, dépôts, documents, fichiers et caisse |
| P1 | Auto Parts et motocyclettes | Stock, multi-entrepôts, prix et documents |
| P1 | Rapports financiers et exports | Transactions confirmées |
| P2 | Guest House | Noyau financier et inspections |
| P2 | Market | POS, catalogue et stock |
| P3 | Hotel, Bar et Restaurant | Réservations, folios, catalogue et POS |
| P4 | Gaz Station | POS, crédit client et quarts |

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
| Imprimantes | Epson TMIII 80 mm ciblée ; confirmer modèle exact, USB ou réseau, pilote et une ou deux imprimantes par poste à la réception |
| Paiement par carte | Fournisseur et pays de règlement |
| WhatsApp | Compte Business API ou partage manuel depuis le poste |
| SMTP | Fournisseur d'envoi et domaine expéditeur |
| BRH | Source officielle et personne responsable de sa validation |
| Comptabilité | Plan de comptes, règles TCA/TMS et validation comptable |
| Déploiement | KVM1 dédié sous Ubuntu 26.04 LTS, espace disque, sauvegardes hors VPS et absence de services étrangers au progiciel |
| Formation | Personnes pilotes par module et scénarios de recette |

## 9. Première décision recommandée

Valider le lot 0.2 puis démarrer Car Rental. Les lots pilotes se suivent ensuite sans inversion : Auto Parts et motocyclettes, Guest House, Market, puis Hotel, Bar et Restaurant. Cela permet de commencer immédiatement le noyau sans inventer les choix matériels ou fiscaux qui doivent être confirmés avant la caisse de production.
