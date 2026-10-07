# Données, numérotation, audit et synchronisation

## 1. Principes de données

Les données opérationnelles appartiennent toujours à une société. Les sites, caisses, entrepôts, chambres, véhicules et utilisateurs ne sont jamais employés comme substitution à l'identifiant de société.

Les identifiants techniques UUID sont utilisés à l'intérieur du système. Les numéros visibles de vente, contrat, certificat ou reçu suivent leur propre règle métier et ne servent pas de clé primaire.

## 2. Entités du noyau

| Entité | Objet |
| --- | --- |
| Company | Société isolée, devise de base, profil fiscal et identité de reçu |
| Site | Lieu opérationnel lié à une société |
| Cash register | Point de caisse, imprimante, écran client et session de caisse |
| Cash session | Ouverture, fond de caisse, opérations, fermeture et écarts |
| Exchange rate | Taux appliqué, référence BRH, auteur, date et dérogation éventuelle |
| Receipt | Vente ou encaissement confirmé, reçu client et copie Administration |
| Receipt line | Ligne de produit, service, chambre, loyer, dépôt, dépense ou ajustement |
| Payment | Règlement HTG, USD, carte, virement, crédit, dépôt, avoir ou remboursement |
| Audit event | Preuve immuable de l'action utilisateur ou système |
| Device | Poste PWA ou kiosque autorisé |
| Offline operation | Opération locale, UUID idempotent, statut de synchronisation et erreur de reprise |

Les modules métiers ajoutent leurs propres entités, sans contourner les règles ci-dessus.

## 3. Numéro de reçu

### 3.1 Format

Le système conserve la forme brute à huit chiffres et affiche la forme espacé :

| Stockage | Affichage |
| --- | --- |
| 00000001 | 0000 0001 |
| 01234567 | 0123 4567 |
| 99999999 | 9999 9999 |

La séquence appartient à la société et ne repart pas à zéro chaque jour. Cela réduit fortement le risque de collision et simplifie la vérification d'un reçu.

### 3.2 Attribution en ligne

La vente confirmée appelle une fonction transactionnelle PostgreSQL qui réserve le prochain numéro. La séquence ne peut pas produire deux fois le même numéro même si deux caisses valident au même moment.

### 3.3 Attribution hors ligne

Avant une perte de réseau, le serveur peut attribuer à une caisse un bloc strict de numéros. Exemple : 0123 4001 à 0123 4500. Le poste ne peut utiliser que son bloc, dans l'ordre, pendant la durée de validité définie.

Lorsque le poste se reconnecte :

1. il transmet son UUID local, son numéro déjà réservé, le hash de la charge et sa date locale ;
2. le serveur rejette tout doublon de UUID ;
3. il valide ou met l'opération en exception ;
4. il conserve le même numéro sur le reçu ;
5. il écrit l'événement d'audit de synchronisation.

Une opération ne doit jamais être recréée par l'opérateur parce que la connexion a semblé échouer.

## 4. QR de vérification

Le QR ne contient pas une copie de facture ni une information personnelle. Il pointe vers une URL publique de vérification avec :

- identifiant public de reçu ;
- numéro de reçu ;
- signature HMAC ;
- version de format.

La page publique affiche uniquement la validité, la société, la date, le numéro et les totaux autorisés par la politique de reçu. Elle ne donne pas l'accès à la fiche client, au caissier, aux paiements détaillés ou au journal interne.

Une signature invalide, un reçu annulé ou un jeton révoqué affiche un état explicite sans dévoiler de données.

## 5. Audit immuable

### 5.1 Événements à inscrire

| Catégorie | Exemples |
| --- | --- |
| Identité | connexion, 2FA, changement de mot de passe, réinitialisation, déconnexion forcée |
| Autorisation | ajout ou retrait de rôle, changement de société, refus d'accès |
| Caisse | ouverture, vente, retour, remise, annulation, clôture, écart, réimpression |
| Finance | taux, paiement, dépôt, remboursement, dépense, crédit, rapprochement |
| Stock | réception, transfert, ajustement, perte, péremption, retour |
| Documents | contrat, inspection, certificat, envoi de reçu, export PDF ou XLSX |
| Administration | configuration SMTP, modèle de courriel, écran client, appareil, sauvegarde |
| Système | tâche planifiée, synchronisation, erreur, reprise, migration et alerte |

### 5.2 Contenu minimal

Chaque événement contient :

- UUID d'événement et UUID de corrélation ;
- société, site et module ;
- acteur : utilisateur, appareil ou système ;
- appareil, version PWA, adresse IP et agent utilisateur si disponibles ;
- action et type d'objet ;
- identifiant de l'objet ;
- valeurs avant et après lorsqu'elles sont pertinentes et non sensibles ;
- montant, devise et taux quand l'événement porte sur un mouvement financier ;
- motif de l'opération lorsqu'il est exigé ;
- horodatage UTC et fuseau d'affichage ;
- résultat : réussi, refusé, différé ou échoué.

### 5.3 Immutabilité

La table d'audit accepte les insertions mais refuse les mises à jour et suppressions à l'aide d'un déclencheur PostgreSQL. Le rôle applicatif n'obtient aucun droit de suppression.

Le journal ne conserve jamais en clair :

- mot de passe ou token ;
- clé SMTP ou clé API ;
- numéro de carte ;
- contenu complet de documents d'identité ;
- adresse courriel ou téléphone intégral lorsque seul le statut d'envoi est utile.

## 6. Rétention et confidentialité

La durée de conservation sera fixée par société avec le comptable et les conseils juridiques. Par défaut, le produit n'automatise aucune purge d'opérations financières ou d'audit. Les règles de purge à venir doivent être exécutées par une tâche journalisée, sur données expirées, après validation du propriétaire.

Les images de passeport, pièces justificatives, signatures et photos de dommages sont chiffrées dans le stockage et accessibles uniquement aux rôles qui en ont besoin.

## 7. Échanges et corrections

Une donnée financière confirmée ne se modifie pas directement.

| Situation | Traitement |
| --- | --- |
| Erreur de vente | Annulation avec motif et événement d'audit |
| Retour produit | Reçu de retour et mouvement de stock |
| Taux erroné avant confirmation | Correction du brouillon par rôle autorisé |
| Taux erroné après confirmation | Ajustement ou note de crédit documentée |
| Paiement incomplet | Solde ouvert, échéance et suivi |
| Dépôt de garantie utilisé | Écriture de consommation du dépôt, jamais modification du reçu d'origine |
| Réimpression | Nouveau tirage marqué Réimpression et motif |

## 8. Qualité de données

- Montants en décimal fixe, jamais en nombre flottant.
- Toutes les devises sont codées ISO : HTG ou USD pour le périmètre initial.
- Les photos et pièces portent un hash, une taille, un type et un propriétaire de société.
- Les imports affichent un aperçu, détectent les doublons et produisent un rapport d'erreur.
- Les dates métier sont validées avec le fuseau America/Port-au-Prince.
- Les suppressions physiques de données métier sont interdites depuis l'interface ; on archive ou on annule.

## 9. Sauvegarde et restauration

La sauvegarde inclut PostgreSQL, fichiers chiffrés, configuration de déploiement non secrète et journal des travaux. Elle est chiffrée avant sortie du VPS.

Une sauvegarde ne vaut rien sans test de restauration. La recette d'exploitation prévoit une restauration contrôlée en préproduction et la vérification d'un reçu, d'un fichier, d'un audit et d'un rapport.
