# Courriels, facture au retour et clients connus (0.7.1-alpha.1)

Retours de recette du 8 octobre 2026 au soir.

## 1. Contrat signé joint au courriel de remise

- À la mise en circulation, le courriel « Votre location est en circulation » attend que le contrat PDF soit créé, puis part **avec le contrat signé en pièce jointe**. Le client reçoit un seul courriel.
- Le texte du courriel indique que le contrat signé est joint. Le design et la mise en page approuvés ne changent pas.
- Si la création du PDF échoue (réseau), le bouton « Créer le contrat PDF » du détail le reconstruit et envoie alors le courriel.
- Décision du propriétaire : le contrat contient la plaque et le numéro de permis ; il est envoyé au client parce que c'est son propre contrat. Le corps des courriels ne montre toujours ni plaque ni permis.

## 2. Assistance routière dans le pied de page

- Nouveau champ « Assistance routière » dans Configuration, fiche de la société, identité légale.
- Le pied de page de tous les courriels client Car Rental affiche en premier « Assistance routière : numéro », puis le nom de la société, son adresse et ses téléphones. Sans numéro dédié, les téléphones de la société sont utilisés.
- La phrase approuvée du pied de page est conservée.

## 3. Facture finale au retour

- Un administrateur (`rental.deposits.settle`) règle le dépôt directement sur l'écran de retour : la retenue proposée reprend les frais du retour, dans la limite du dépôt, et reste modifiable.
- À l'enregistrement : retour, règlement du dépôt, facture numérotée, PDF et **envoi de la facture finale au client**, en une seule action.
- Un agent sans droit de règlement enregistre le retour ; la facture part automatiquement dès qu'un administrateur règle le dépôt depuis le détail de la réservation.
- Après l'enregistrement du retour, l'application revient à l'écran d'accueil (« Aujourd'hui »).

## 4. Clients connus

- À la création d'une réservation, la saisie du nom, du courriel ou du téléphone propose les clients connus de la société active.
- Recherche exacte par courriel ou téléphone (empreinte), par partie du nom sinon. Huit résultats au plus.
- Les coordonnées sont masquées dans les suggestions (`je***@domaine`, `••• 1234`) : elles servent à reconnaître le client, pas à les recopier.
- Le client choisi est réutilisé tel quel (même fiche, historique commun). « Changer » revient à la saisie d'un nouveau client.
- Limite connue : les noms étant chiffrés en base, la recherche par nom lit les fiches par lots. Elle convient au volume du pilote ; un index de recherche dédié sera ajouté si le nombre de clients le demande.

## 5. Taux de change

La question de l'emplacement du taux (Configuration du groupe ou société) est soumise au propriétaire avant toute modification. Le fonctionnement 0.7.0 reste inchangé dans cette version.
