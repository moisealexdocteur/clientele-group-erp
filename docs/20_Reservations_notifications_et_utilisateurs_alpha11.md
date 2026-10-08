# Réservations, notifications et utilisateurs Car Rental — alpha.11

## Réservations

La liste des réservations charge automatiquement le mois en cours. Pendant les sept derniers jours du mois, elle inclut les sept premiers jours du mois suivant. Le planning utilise la même période par défaut. L’utilisateur peut ensuite filtrer par date, état, référence ou plaque.

| Situation | Action disponible | Règle appliquée |
| --- | --- | --- |
| Réservation | Modifier | Le véhicule, le départ et le retour peuvent être modifiés avant la remise. Le tarif et les paiements existants ne sont pas recalculés. |
| Réservation | Mettre en circulation | Le véhicule passe à `En circulation`. |
| Réservation | Annuler | Un motif est requis. Aucun remboursement n’est créé automatiquement. |
| Location en circulation | Prolonger | La nouvelle date doit être postérieure au retour prévu. Un conflit avec une réservation future est refusé sans afficher les données du client concerné. |
| Location en circulation | Enregistrer le retour | Le véhicule passe à `Préparation`. Un retour anticipé ne modifie pas la date prévue, le tarif ni les paiements du contrat initial. |

Chaque action est limitée à la société et aux adresses autorisées, protégée par une version de verrouillage et journalisée.

## Courriels client

Les courriels Car Rental utilisent le même gabarit que le code de sécurité : en-tête Clientèle Group, carte blanche, bloc de référence bleu et pied de page de sécurité.

| Événement | Courriel envoyé si une adresse client valide est disponible |
| --- | --- |
| Réservation créée | Confirmation de réservation |
| Véhicule remis | Location mise en circulation |
| Prolongation | Nouvelle date de retour |
| Retour enregistré | Retour du véhicule enregistré |
| Contrat signé disponible | Contrat de location signé, lorsque le module contrat fournit un PDF réel et validé |
| Facture émise | Facture disponible, lorsque le module facture fournit un PDF réel et validé |

Le journal conserve uniquement le résultat de l’envoi et le nombre de pièces jointes. Il ne contient ni adresse courriel, ni numéro de téléphone, ni contenu de facture, ni document PDF.

## Facture et contrat PDF

Le contrat signé et la facture PDF ne sont pas encore générés dans l’alpha.11. Le service de courriel refuse une notification de contrat signé ou de facture sans PDF valide. Lorsqu’un futur module aura généré et validé ces documents, il pourra joindre au maximum deux PDF — par exemple la facture et le contrat signé — au courriel concerné.

Aucun PDF fictif, contrat non signé ou facture non validée n’est joint ou annoncé au client.

## Gestion des utilisateurs

Le propriétaire du système gère les utilisateurs par société :

- modifier le profil Car Rental et les adresses autorisées ;
- modifier le nom et le courriel seulement si le compte n’est pas actif dans une autre société ;
- désactiver ou réactiver l’accès à la société courante ;
- réinitialiser un mot de passe conforme, ce qui ferme les sessions existantes ;
- supprimer définitivement un compte non partagé après confirmation du courriel exact.

Le propriétaire système ne peut pas être supprimé depuis cette page. La suppression d’un compte ne supprime pas les transactions ni les événements d’audit.

Un courriel de création est envoyé au nouvel utilisateur avec le même gabarit. Il indique la société et le profil attribué, mais ne contient jamais le mot de passe.
