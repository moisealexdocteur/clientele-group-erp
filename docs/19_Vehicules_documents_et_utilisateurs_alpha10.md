# Véhicules, papiers et utilisateurs Car Rental — alpha.10

## Véhicule

La plaque d’immatriculation en cours est l’unique identifiant opérationnel du véhicule. Aucun code interne séparé n’est demandé.

- Une plaque est soit `Démonstration`, soit `Officielle`.
- Lorsqu’une plaque officielle remplace une plaque `Démonstration`, la plaque en cours est mise à jour et l’ancienne plaque reste dans l’historique du véhicule.
- La plaque et le VIN restent uniques dans une même société.
- La liste de la flotte affiche la plaque, l’état opérationnel et l’état des documents sans afficher les références documentaires.

Les réservations et le calendrier utilisent le même identifiant de véhicule. Ils ne retournent pas les références des papiers ni les données du client dans la vue de planning.

## Papiers suivis

Le premier lot ne stocke pas de fichiers ni de scans. Il enregistre seulement les références et dates nécessaires à l’exploitation :

| Document | Champs suivis | Date d’expiration |
| --- | --- | --- |
| Immatriculation | Référence, date de délivrance | Non demandée dans ce lot |
| Assurance OAVCT | Référence | Obligatoire dès que le document est enregistré |
| Permis de vitres teintées | Référence | Obligatoire dès que le document est enregistré |

L’état affiché est `À jour`, `Expire bientôt` (30 jours ou moins), `Expiré`, `Sans expiration` ou `Non renseigné`.

La flotte est traitée comme équipée de vitres teintées, conformément au choix opérationnel communiqué par Clientèle Group. Le formulaire ne pose donc pas cette question à chaque véhicule ; il suit directement le permis et son expiration.

L’OAVCT indique que l’assurance de véhicule couvre la responsabilité envers les tiers et qu’elle est requise pour les véhicules circulant en Haïti. Cette version suit donc explicitement le document OAVCT demandé par Clientèle Group, sans prétendre fournir une vérification réglementaire automatisée. Source : [OAVCT](https://oavct.gouv.ht/).

## Utilisateurs Car Rental

Le propriétaire crée un compte réel depuis **Configuration système > Créer un compte utilisateur Car Rental**.

| Profil | Accès |
| --- | --- |
| Administrateur Car Rental | Toutes les fonctions Car Rental actuellement livrées, y compris l’approbation des paiements |
| Agent de location | Disponibilité, réservations, lecture de flotte, calendrier et soumission de paiements |
| Gestionnaire de flotte | Lecture et gestion de flotte, calendrier |

Pour chaque compte :

- le courriel personnel est requis ;
- le mot de passe initial doit compter au moins 12 caractères, avec majuscule, minuscule, chiffre et symbole ;
- le propriétaire sélectionne toutes les adresses actives ou un sous-ensemble précis ;
- la première connexion demande le code envoyé au courriel personnel ;
- aucun mot de passe n’est envoyé par courriel ni enregistré dans le journal d’audit.

## Réservation et lieux

- Le bureau physique sélectionné est le lieu de départ par défaut ; son nom et son adresse sont conservés avec la réservation.
- Le retour peut être enregistré à l’Aéroport International du Cap-Haïtien.
- Lorsqu’il est applicable, le préposé sélectionne séparément le frais de prise en charge à l’aéroport (20 USD) et le frais de retour à l’aéroport (20 USD). Les frais restent en USD : aucune conversion automatique vers HTG n’est effectuée.
- Le frais de nettoyage de 20 USD ne sera appliqué qu’après l’inspection de retour, si le véhicule n’est pas restitué propre. Il n’est pas ajouté automatiquement à la réservation.

## Sécurité et journal

- Les changements de plaque, l’enregistrement des papiers et la création d’utilisateur sont journalisés.
- Les références de documents, les courriels et les mots de passe ne sont pas ajoutés aux métadonnées d’audit.
- Les tables des papiers et de l’historique de plaque sont isolées par société avec Row Level Security PostgreSQL.
- Les documents détaillés nécessitent la permission `rental.vehicles.manage` et une adresse autorisée.

## Limites de ce lot

- Aucun scan de document, rappel courriel, contrat ou vérification externe n’est encore intégré.
- La création d’un utilisateur existant avec le même courriel est volontairement refusée. L’attribution d’un compte existant à une deuxième société sera un lot distinct afin de préserver une procédure d’accès explicite.
