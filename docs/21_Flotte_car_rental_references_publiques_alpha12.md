# Flotte Car Rental — références publiques alpha.12

## Objectif

Les publications publiques transmises par le propriétaire servent à préremplir la flotte Car Rental. Elles ne remplacent pas les contrôles opérationnels. Aucun véhicule n’est créé automatiquement par ce catalogue.

Avant l’enregistrement, le préposé doit utiliser l’adresse **Pont Parois, Route Nationale 6** et saisir le kilométrage réel relevé. Lorsque cette adresse existe dans la société active, le catalogue la sélectionne automatiquement. Si elle n’est pas encore configurée, le formulaire exige sa création ou sa sélection avant l’enregistrement. Il renseigne ensuite les papiers, l’assurance OAVCT et le permis de vitres teintées dans la fiche du véhicule.

## Sources fournies

1. [Vidéo Clientèle Group](https://www.tiktok.com/@clientele_group/video/7679127374331481352?lang=fr)
2. [Vidéo fournie par le propriétaire](https://www.tiktok.com/@sully.simon68/video/7686455534647659797?lang=fr)
3. [Publication photo Clientèle Group](https://www.tiktok.com/@clientele_group/photo/7618667507980782866?lang=fr)

## Données proposées dans l’interface

| Plaque | Type | Marque et modèle | Catégorie | Photo de référence | Contrôle avant enregistrement |
| --- | --- | --- | --- | --- | --- |
| `AA-85177` | Normale | Nissan Frontier | Pick-up | Oui | Non supplémentaire |
| `LO-01727` | Location | Suzuki Jimny | SUV | Oui | Non supplémentaire |
| `DM-00849` | Démonstration | Great Wall Poer | Pick-up | Oui | Non supplémentaire |
| `LO-01724` | Location | Suzuki Jimny | SUV | Non | Vérifier la plaque et la carte grise |
| `DM-00437` | Démonstration | BAIC BJ40 | SUV | Non | Vérifier le modèle, la plaque et la carte grise |
| `DM-00835` | Démonstration | Great Wall Poer | Pick-up | Non | Vérifier la plaque, le modèle et la carte grise |

Les années, VIN, kilométrages, adresse d’affectation, documents et état opérationnel ne sont pas déduits des publications. Ils restent vides jusqu’à leur saisie depuis des sources opérationnelles réelles.

## Règles de sécurité et de présentation

- La plaque courante est le seul identifiant visible du véhicule ; le système ne crée pas de code interne distinct.
- Les types utilisables sont uniquement `Démonstration`, `Location` et `Normale`.
- Les trois images enregistrées dans `apps/web/public/fleet` sont étiquetées « Photo de référence — publication Clientèle Group » dans l’interface.
- Une photo de référence publique ne démontre ni la propriété actuelle du véhicule, ni l’état mécanique, ni la validité d’une assurance ou d’un permis.
- Les photos d’inspection, documents et données client restent dans les espaces privés et isolés par société.
- La clé de photo acceptée par l’API est limitée à la liste versionnée. Une URL ou un fichier arbitraire ne peut pas être injecté dans une fiche véhicule.

## Recette alpha.12

1. Ouvrir **Véhicules** avec la permission `rental.vehicles.manage`.
2. Appuyer sur **Préremplir la fiche** pour une référence publiée.
3. Vérifier l’avertissement lorsqu’il est affiché.
4. Sélectionner l’adresse autorisée et saisir le kilométrage réellement relevé.
5. Enregistrer le véhicule, puis ouvrir **Gérer les papiers** pour compléter l’immatriculation, l’OAVCT et le permis de vitres teintées.
6. Vérifier que l’image de référence est affichée sur la fiche enregistrée et que les données ne sont visibles que dans la société et les adresses autorisées.
