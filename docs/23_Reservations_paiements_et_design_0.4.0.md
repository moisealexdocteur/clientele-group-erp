# Réservations, paiements et design Fluent (0.4.0-alpha.1)

Cette version est la livraison 1 des retours de recette du 8 octobre 2026. La livraison 2 couvrira le permis international, la fiche de sortie, les signatures tactiles et le contrat PDF.

## 1. Design

- Système visuel Microsoft Fluent 2 : police Segoe UI (police système, repli Roboto et San Francisco), échelle typographique 12 à 40 px, rayons 4 et 8 px, ombres légères, couleur d'accent `#0f6cbd`.
- Les chiffres ne sont plus étirés. Les identifiants (plaques, numéros de série) utilisent une police à chasse fixe.
- Cibles tactiles : 48 px sur écran tactile, 40 px avec souris.
- Les champs obligatoires portent un astérisque. Tant qu'un élément manque, le bouton d'enregistrement reste grisé et la liste « À compléter » nomme chaque élément manquant.

## 2. Tarif de réservation

- Le tarif quotidien et le dépôt minimum viennent de la fiche véhicule.
- Seul un rôle ayant `rental.reservations.override_rate` (administrateur Car Rental) peut appliquer un autre tarif ou une autre devise. Le serveur refuse la modification pour un agent.
- Une réservation à tarif particulier affiche le badge « Tarif modifié ». L'administrateur peut reprendre le tarif de la fiche en un geste.
- La durée minimale de deux jours est un avertissement pour l'agent, pas un blocage.
- Le prix du kilomètre supplémentaire est facultatif et reste vide par défaut.

## 3. Modification complète d'une réservation

Écran dédié `Modifier la réservation`, accessible tant que le véhicule n'a pas été remis :

- client : type, nom, courriel, téléphone ;
- période, véhicule de toute catégorie disponible, lieux de départ et de retour ;
- tarif (selon permission), kilométrage, devise.

Un changement de véhicule applique le tarif et le dépôt de la nouvelle fiche, sauf tarif particulier autorisé. Les paiements existants ne sont pas modifiés. Une case permet d'envoyer la confirmation mise à jour au client ; le bouton `Renvoyer la confirmation` du détail la renvoie à tout moment. Chaque modification est journalisée.

## 4. Paiements

| Mode | Exigence | Approbation |
| --- | --- | --- |
| Espèces USD ou HTG | Caisse active du bureau | Étape distincte |
| Virement Sogebank | Photo ou PDF du reçu (obligatoire), référence facultative | Étape distincte |
| Crédit | Permission `rental.payments.credit` (administrateur ou propriétaire) | Immédiate, journalisée au nom de la personne |

Le dépôt de garantie est toujours en USD et ne peut pas être accordé à crédit. Le reçu Sogebank est consultable depuis la liste des paiements par les rôles autorisés.

## 5. Fiche véhicule

- Photo réelle : ajout depuis l'appareil photo ou un fichier, remplacement et retrait. Elle remplace la photo de référence partout dans l'application.
- Interrupteur `Actif` / `Inactif` avec confirmation et journalisation. Un véhicule réservé ou en location ne peut pas être désactivé.
- Caractéristiques reprises dans le contrat : couleur, carburant, transmission, cylindrée, portes, numéro de série.

## 6. Identité légale de la société

Dans la configuration système, chaque société a une raison sociale, un représentant légal, un NIF, une adresse de siège et des téléphones. Ces informations alimenteront l'en-tête du contrat. Aucune donnée réelle n'est versionnée dans le dépôt.

## 7. Fichiers privés

- Stockage sur le volume Docker `clientele-documents`, hors de la racine web.
- Types acceptés : JPEG, PNG, WebP et PDF selon l'usage ; 10 Mo au plus.
- Empreinte SHA-256, isolation par société (RLS PostgreSQL), lecture selon la permission de l'usage (`rental.documents.sensitive` pour les reçus et permis), journalisation du dépôt.

## 8. Déploiement

Le script serveur `$HOME/deploy-preprod.sh` doit être remplacé par la version de `infra/deploy-preprod.sh` avant le déploiement, afin que le volume `clientele-documents` soit monté. Les migrations ajoutent les nouvelles permissions aux accès administrateur Car Rental existants.
