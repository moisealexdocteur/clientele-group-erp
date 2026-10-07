# Architecture technique

## 1. Décision d'architecture

Le produit est un monolithe modulaire : un seul produit déployable, avec des modules métier clairement séparés dans le code et dans les permissions. Ce choix est plus fiable et moins coûteux pour le démarrage sur un VPS KVM1 qu'une architecture de microservices.

Le noyau est commun. Les modules Hotel, Guest House, Car Rental, Gaz Station, Market et Auto Parts partagent les mêmes mécanismes d'identité, d'audit, de caisses, de devises, de documents et de rapports.

## 2. Pile cible

| Couche | Choix | Justification |
| --- | --- | --- |
| Backend | Laravel sur PHP maintenu | Sécurité, migrations, jobs, courriels, autorisations et génération de documents dans un processus maîtrisé |
| Interface | Vue et TypeScript, compilés avec Vite | POS tactile rapide, PWA, composants réutilisables et écrans client |
| Base de données | PostgreSQL | Intégrité transactionnelle, Row Level Security, JSON contrôlé, rapports et audit |
| Cache et files | Redis | Sessions, verrouillage de caisse, travaux de courriel, synchronisation et export |
| Serveur web | Nginx | Sert les fichiers PWA et délègue PHP au backend |
| Proxy HTTPS | Traefik existant | Certificats, routes, redirections HTTPS et séparation des services |
| Système hôte | Ubuntu 26.04 LTS | Base de serveur maintenue pour le VPS dédié |
| Fichiers | Volume chiffré au départ, stockage S3 compatible ensuite | Photos d'inspection, justificatifs, contrats et exports |
| Supervision | Healthcheck, journaux structurés et sauvegardes chiffrées | Détection et reprise sans exposer les données |

Les versions exactes sont consignées dans les fichiers de dépendances au démarrage du code. Elles restent supportées pendant toute la durée de vie de la version de production.

## 3. Vue d'ensemble

~~~mermaid
flowchart TD
  A["Poste kiosque ou PWA"] --> B["Traefik HTTPS"]
  B --> C["Nginx + Interface Vue"]
  C --> D["API Laravel"]
  D --> E["PostgreSQL et Redis"]
  D --> F["SMTP, WhatsApp et stockage"]
~~~

Le navigateur ne se connecte jamais directement à PostgreSQL, Redis, SMTP ou au stockage.

## 4. Découpage applicatif

| Domaine | Responsabilités |
| --- | --- |
| Identité et accès | comptes, mots de passe, 2FA email, sessions, rôles, appareils et permissions |
| Organisation | groupe, sociétés, sites, entrepôts, caisses, heures d'affaires et paramètres locaux |
| Finance commune | devises, taux, paiement, caisse, taxe, dépense, dépôt, crédit, pièces et export |
| Documents | reçus, QR, contrats, inspections, certificats, PDF, XLSX, courriels et WhatsApp |
| Audit | événements immuables, corrélation, traces de sécurité et rétention |
| Synchronisation | PWA, files hors ligne, idempotence, blocs de numéros et résolution d'état |
| Catalogues et stocks | produits, services, prix, variantes, unités, fournisseurs et mouvements |
| Modules métier | Hotel, Guest House, Car Rental, Gaz Station, Market et Auto Parts |
| Rapports | tableaux de bord, exports, rapports opérateurs, comptables et fiscaux |

Un module ne lit pas les tables d'un autre module sans passer par le noyau et sans vérifier la société et les permissions.

## 5. Multi-sociétés et isolation

Chaque table opérationnelle contient au minimum company_id. Les requêtes applicatives s'exécutent dans une transaction qui fixe la société active. PostgreSQL applique une politique Row Level Security, ce qui rend impossible une lecture ou une écriture accidentelle dans une autre société.

L'identité maître Clientèle Group est volontairement séparée des tables opérationnelles. Elle est accessible uniquement par un service de confidentialité dédié, après vérification du consentement et de la finalité. Un profil client de société, une réservation, une location, un solde, un document d'identité ou une inspection n'est jamais rendu lisible par le seul fait que la personne possède une identité maître.

La logique est la suivante :

1. L'utilisateur s'authentifie.
2. Le backend charge les sociétés auxquelles il a droit.
3. L'utilisateur sélectionne explicitement une société ou le poste l'impose.
4. Le backend ouvre une transaction et fixe le contexte company_id.
5. PostgreSQL n'autorise que les lignes de cette société.
6. Toute tentative interdite est auditable.

Le propriétaire global peut accéder à une société après sélection explicite. Il ne travaille jamais dans un contexte global silencieux.

## 6. Modèle de déploiement Docker

Les services de production sont limités à ceux nécessaires au fonctionnement. Ils partagent le réseau interne clientele-internal. Seuls Nginx et, si nécessaire, le point de santé sont connectés au réseau externe Traefik.

| Service | Exposé publiquement | Fonction |
| --- | --- | --- |
| web | Oui, via Traefik seulement | Interface PWA, assets et reverse proxy vers PHP |
| app | Non | API Laravel et logique métier |
| worker | Non | Courriels, WhatsApp, PDF, XLSX, synchronisation et rappels |
| scheduler | Non | Tâches planifiées et contrôles de cohérence |
| postgres | Non | Base de données persistante |
| redis | Non | Cache, verrous et files |
| backup | Non, exécuté à la demande ou planifié | Sauvegarde chiffrée et vérifiée |

Traefik, déjà hébergé sur le VPS, reste le seul point d'entrée HTTPS. La base de données ne publie aucun port sur Internet.

## 7. Noms de domaines suggérés

| Sous-domaine | Usage |
| --- | --- |
| erp.domaine-clientele.tld | Application opérationnelle |
| verify.domaine-clientele.tld | Vérification publique de reçu avec jeton signé |
| status.domaine-clientele.tld | État technique sans données métier, facultatif |

Le domaine réel est à fournir avant le premier environnement. Les URLs de vérification de reçus n'incluent ni nom de client, ni téléphone, ni montant en clair.

## 8. Environnements

| Environnement | But | Données |
| --- | --- | --- |
| Local | Développement et tests | Jeux de données fictifs uniquement |
| Préproduction | Formation, recette et validation d'impression | Copie anonymisée ou données de test |
| Production | Opérations réelles | Données chiffrées, sauvegardées et accès limité |

La production ne sert jamais de laboratoire de développement. Toute migration de données est testée en préproduction avant l'exécution en production.

## 9. Configuration et secrets

Les paramètres ordinaires sont gérés dans l'application avec droits, audit et historique. Les secrets techniques restent dans des variables d'environnement ou un gestionnaire de secrets :

- clé de chiffrement de l'application ;
- accès PostgreSQL et Redis ;
- SMTP ;
- fournisseur WhatsApp ;
- stockage de fichiers ;
- clés de signature des QR ;
- clés de sauvegarde ;
- passerelles de paiement.

Les fichiers .env ne sont jamais commités. Toute rotation de secret est documentée et testée.

## 10. Disponibilité et performances

Le KVM1 est retenu pour démarrer selon le budget, avec Ubuntu 26.04 LTS. Son profil de référence est limité : 1 vCPU, 4 Go RAM et 50 Go NVMe. Il convient à un premier pilote léger si le VPS est réservé au progiciel et au Traefik nécessaire. Il ne doit pas aussi héberger des environnements de développement, des builds d'images, des sauvegardes longues conservées localement ni des services sans lien avec l'ERP.

Sur KVM1 :

- une seule file worker est active ;
- la validation d'une vente demeure courte et synchrone ;
- PDF, XLSX, courriels, WhatsApp, imports et rapports lourds passent par la file ;
- les photos, pièces et archives sortent progressivement vers un stockage objet ou des sauvegardes chiffrées hors VPS ;
- la préproduction se fait localement ou sur un environnement temporaire séparé ;
- aucune des neuf caisses prévues ne sera activée sans une recette de charge et d'impression sur matériel réel.

Le passage au KVM2 est déclenché avant l'activation complète des neuf caisses, ou plus tôt si l'une des conditions suivantes apparaît en production ou en recette :

- CPU supérieur à 80 % pendant dix minutes à charge normale ;
- mémoire supérieure à 85 % pendant quinze minutes ou usage continu de swap ;
- moins de 15 Go libres sur le disque ;
- validation en ligne d'une vente au 95e percentile supérieure à 1,5 seconde, hors temps d'impression ;
- file de travaux critique en attente plus de dix minutes ou échecs répétés de synchronisation ;
- sauvegarde ou restauration qui ne tient plus dans la fenêtre d'exploitation.

Les tâches lourdes vont dans le worker : PDF, XLSX, courriels, WhatsApp, import, rapports volumineux, compressions et sauvegardes. Le POS confirme la vente rapidement, puis les tâches secondaires sont traitées de façon visible et reprise en cas d'erreur.

## 11. Interfaces externes

| Intégration | Règle |
| --- | --- |
| SMTP | Paramétrable par le propriétaire global, test d'envoi et journal du statut |
| WhatsApp | Adaptateur spécifique au fournisseur, consentement et journal de livraison |
| Imprimante thermique | Locale au poste, jamais via le VPS |
| Lecteur code-barres | Support clavier USB en priorité |
| Balance | Support clavier USB en priorité, connecteur spécifique si réseau ou série |
| Banque ou carte | Adaptateur séparé, aucune donnée de carte bancaire stockée |
| BRH | Référence contrôlée et datée, import ou connecteur officiel validé |

## 12. Décisions de sécurité applicative

- Hash de mots de passe avec Argon2id.
- 2FA email obligatoire pour les rôles sensibles et disponible pour tous.
- Sessions liées à l'appareil et révocables.
- Limitation des tentatives de connexion et de réinitialisation.
- Chiffrement applicatif des documents sensibles, notamment passeport, photos d'inspection et pièces d'identité.
- Signature HMAC des QR de reçus, des liens d'écran client et des demandes de synchronisation.
- Journaux structurés sans mots de passe, secret ni contenu de document.
- Tests de permission et de société sur chaque endpoint.

## 13. Préparation pour la croissance

Lorsque la charge l'exige, le produit peut évoluer sans changer le modèle métier :

1. déplacer les fichiers vers un stockage objet ;
2. déplacer les workers sur une machine distincte ;
3. utiliser une base PostgreSQL managée ou dédiée ;
4. installer un second VPS applicatif derrière Traefik ;
5. isoler seulement les modules qui justifient réellement un service séparé.

La migration vers des microservices n'est pas un objectif en soi.
