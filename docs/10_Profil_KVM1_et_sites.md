# Profil KVM1, caisses et lancement du pilote

## 1. Décision de départ

Le démarrage se fait sur un Hostinger KVM1 pour respecter le budget, sous Ubuntu 26.04 LTS, Docker et Traefik. Ce choix est accepté pour un pilote progressif, non pour promettre une capacité illimitée.

Le VPS doit être dédié au progiciel et à ses composants indispensables. Il ne cohabite pas avec une autre application métier, un environnement de développement, des builds d'images, une préproduction permanente ou une conservation longue de sauvegardes.

Le profil KVM1 de référence — 1 vCPU, 4 Go RAM et 50 Go NVMe — doit être vérifié dans l'offre Hostinger au moment de l'achat. Toute différence réelle est inscrite dans le journal de déploiement.

## 2. Inventaire des caisses connues

| Société / activité | Caisses prévues | Remarque |
| --- | ---: | --- |
| Clientèle Car Rental | 1 | Première caisse pilote |
| Clientèle Auto Parts et motocyclettes | 3 | Magasins et vente de motocyclettes selon l'organisation retenue |
| Clientèle Guest House | 1 | Encaissements et réception |
| Clientèle Market | 2 | Vente et inventaire |
| Clientèle Hotel, Bar et Restaurant | 2 | Phase finale du pilote |
| **Total** | **9** | Toute activation additionnelle impose une mise à jour de cette fiche |

Chaque caisse est un poste logique distinct : société, site, adresse complète du site, appareil, utilisateur ou compte de poste, imprimante, écran client éventuel, bloc de numéros hors ligne et règles d'ouverture de session. Une caisse ne partage jamais son contexte avec une autre société. L'adresse réelle de chaque site doit être saisie et validée avant l'activation de son poste.

## 3. Garde-fous KVM1

Le KVM1 fonctionne avec une configuration volontairement sobre :

- un seul worker de file pour les courriels, PDF, XLSX, synchronisations et tâches externes ;
- base PostgreSQL et Redis non exposés sur Internet ;
- génération de documents et rapports lourds en différé ;
- stockage de documents et sauvegardes chiffrées hors VPS dès que les pièces et photos commencent à s'accumuler ;
- images Docker construites dans l'intégration continue ou sur un environnement séparé ;
- limites mémoire et CPU des conteneurs définies après le premier profilage, afin qu'un service secondaire ne bloque pas les ventes ;
- préproduction effectuée localement ou sur un environnement temporaire distinct.

Avant la mise en service de chaque nouveau lot, une charge représentative est mesurée. Le KVM2 devient nécessaire avant l'activation des neuf caisses si les seuils de l'architecture ne sont pas respectés, notamment CPU soutenu, mémoire ou swap, disque libre, délai de validation, file en retard, échec de sauvegarde ou synchronisation instable.

## 4. Ordre de déploiement du pilote

| Ordre | Module | Porte de sortie avant le suivant |
| ---: | --- | --- |
| 1 | Car Rental | Réservation, contrat, dépôt, inspection, kilométrage, reçu et audit validés |
| 2 | Auto Parts et motocyclettes | Stock multi-emplacements, vente, dépôt moto, livraison et documents numérotés validés |
| 3 | Guest House | Bail, électricité, dépôts, inspection entrée et sortie validés |
| 4 | Market | Réception fournisseur, stock, scan, balance si disponible, vente et retour validés |
| 5 | Hotel, Bar et Restaurant | Chambres, folio, bars, restaurant, dépenses et check-out validés |

Gaz Station est traité après ce pilote ; son besoin de ventes à crédit et de quarts pompistes ne modifie pas l'ordre ci-dessus.

## 5. Imprimantes thermiques

La cible est une Epson de la famille nommée « TMIII », papier 80 mm, normalement une par caisse. Les appareils ne sont pas encore disponibles ; l'équipe ne prétend donc pas que l'impression silencieuse est prête.

Lorsque les imprimantes arrivent, chaque poste reçoit une recette indépendante :

1. relever le modèle exact et la connexion USB, Ethernet ou série ;
2. installer le pilote sur le poste Windows ou le système retenu ;
3. définir l'imprimante thermique comme imprimante par défaut du profil kiosque ;
4. tester Edge ou Chrome avec la politique d'impression retenue ;
5. imprimer le reçu client, la copie Administration, une réimpression et le rapport journalier ;
6. vérifier le QR, les accents français, les montants HTG/USD et l'absence d'en-tête de navigateur ;
7. enregistrer le résultat dans la fiche de recette.

L'impression s'effectue localement sur le poste de caisse. Le VPS ne pilote jamais directement une imprimante.

## 6. Données clients dans un groupe multi-sociétés

Le fait qu'un client achète plusieurs services de Clientèle Group ne transforme pas les sociétés en une seule base de données ouverte.

- Une identité maître permet de reconnaître le client sous contrôle.
- Chaque société conserve un profil local et ses opérations isolées par Row Level Security.
- Les employés ne reçoivent que l'information nécessaire à leur tâche dans leur société.
- Le partage de coordonnées ou de préférences requiert un consentement traçable et révocable.
- Les réservations, locations, inspections, passeports, dépôts, soldes, crédits, photos et documents ne sont pas partagés entre sociétés.
- Les recherches, liens, consultations partagées et révocations font partie de l'audit.

Les détails de ce modèle se trouvent dans les documents Données et audit et Sécurité et accès.
