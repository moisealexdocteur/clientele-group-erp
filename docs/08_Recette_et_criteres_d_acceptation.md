# Recette et critères d'acceptation

## 1. Principe

La recette est réalisée en préproduction avec des données de test représentatives. Une fonction n'est pas acceptée parce qu'elle est belle à l'écran : elle doit produire le bon résultat, respecter les droits, conserver l'audit et se comporter correctement lorsqu'une étape échoue.

## 2. Noyau et sociétés

- [ ] L'interface est en français par défaut.
- [ ] Une date affichée correspond au fuseau America/Port-au-Prince et au format jour mois année heure AM ou PM.
- [ ] Un utilisateur de Société A ne voit aucune donnée de Société B, même avec recherche, URL directe, export ou appel API.
- [ ] Le propriétaire global doit sélectionner explicitement une société avant une opération métier.
- [ ] Une société inactive ne peut plus ouvrir de nouvelle opération.
- [ ] Les sites et caisses ne sont disponibles que dans leur société.

## 3. Connexion et sécurité

- [ ] L'utilisateur peut demander la réinitialisation de son propre mot de passe.
- [ ] La demande ne divulgue pas si l'adresse courriel existe.
- [ ] Le code ou lien de réinitialisation expire et ne peut être utilisé qu'une fois.
- [ ] La 2FA par courriel fonctionne pour un rôle sensible.
- [ ] Les tentatives excessives de connexion sont limitées.
- [ ] Désactiver un compte coupe ses sessions actives.
- [ ] Un changement de rôle est audité avec auteur, date et avant ou après.
- [ ] Aucun secret ni mot de passe ne se retrouve dans les logs ou les exports.

## 4. Devises et taux

- [ ] HTG et USD sont disponibles selon les paramètres de la société.
- [ ] Un administrateur non autorisé ne peut pas modifier un taux.
- [ ] Un taux inférieur à la référence BRH déclenche l'alerte prévue.
- [ ] Seul le propriétaire peut déroger, avec motif obligatoire et audit.
- [ ] Une vente confirmée conserve son taux même si le taux courant change ensuite.
- [ ] Un paiement mixte HTG et USD calcule correctement les totaux et contre-valeurs.

## 5. Ventes, reçus et caisse

- [ ] L'ouverture de caisse enregistre les fonds HTG et USD.
- [ ] Une vente confirmée reçoit un numéro brut unique de huit chiffres.
- [ ] Le numéro s'affiche avec deux blocs de quatre chiffres.
- [ ] Le reçu client contient les lignes, le nombre d'articles, prix, totaux, QR et pied de page.
- [ ] La copie Administration contient les informations supplémentaires prévues.
- [ ] Le QR d'un reçu valide mène à une page de vérification sans données client sensibles.
- [ ] Un QR falsifié ou un reçu annulé est signalé correctement.
- [ ] Une réimpression demande un motif et est visible dans l'audit.
- [ ] Le reçu est envoyé par courriel avec statut de livraison.
- [ ] Le flux WhatsApp respecte la méthode retenue et laisse une trace.
- [ ] La fermeture de caisse calcule l'écart par devise et demande une explication hors tolérance.
- [ ] Le rapport journalier peut être imprimé et exporté en PDF et XLSX.

## 6. Impression kiosque

- [ ] Le poste utilisera l'imprimante thermique 80 mm configurée.
- [ ] L'impression automatique est testée avec la politique ou l'agent local choisi.
- [ ] Le reçu ne contient pas l'en-tête, l'URL ou le pied de page ajouté par le navigateur.
- [ ] Le QR reste scannable après impression.
- [ ] Une impression en erreur ne crée pas une double vente.
- [ ] La réimpression est possible après remise en ligne de l'imprimante.
- [ ] Si deux imprimantes sont requises, le mécanisme local les choisit sans intervention non autorisée.
- [ ] Le modèle d'imprimante, pilote, OS et version de navigateur sont inscrits dans la fiche de recette.

## 7. Écran client

- [ ] L'écran affiche seulement les articles de la caisse associée.
- [ ] Il affiche la promotion configurée en dehors d'une vente.
- [ ] Un code de jumelage expiré ne fonctionne pas.
- [ ] Une révocation coupe l'écran actif.
- [ ] L'écran d'un site ne peut pas être associé à la caisse d'une autre société.

## 8. Hors ligne et synchronisation

- [ ] Le PWA se lance sans réseau après synchronisation initiale.
- [ ] Le poste n'utilise qu'un numéro provenant de son bloc préalloué.
- [ ] La même opération envoyée deux fois ne crée qu'un reçu.
- [ ] Une vente hors ligne affiche son état en attente de synchronisation.
- [ ] Le retour en ligne confirme la vente ou présente clairement une exception.
- [ ] Les courriels et actions externes restent en attente sans perdre leur trace.
- [ ] La caisse ne peut pas être clôturée définitivement avec une erreur de synchronisation non traitée.

## 9. Hotel, Bar et Restaurant

- [ ] Les 14 chambres sont visibles au calendrier avec leur statut.
- [ ] Une réservation peut être avancée, partiellement payée ou payée sur place.
- [ ] Le check-in ouvre un folio et le check-out le clôture.
- [ ] Petit déjeuner inclus et shuttle sont correctement ajoutés selon le tarif ou la sélection.
- [ ] Une consommation de bar ou restaurant peut être réglée immédiatement ou envoyée au folio autorisé.
- [ ] Un dépôt est séparé du revenu et sa libération ou retenue est auditée.
- [ ] Les dépenses sont classées et n'apparaissent pas comme ventes.
- [ ] Les rapports respectent les droits de réception, service, supervision et propriétaire.

## 10. Guest House, Car Rental, Market, Gaz Station et Auto Parts

Chaque module reçoit sa fiche de recette propre avant activation. Les cas minimaux sont :

| Module | Cas à valider |
| --- | --- |
| Guest House | Bail court et long, électricité HTG, dépôt, inspection entrée et sortie |
| Car Rental | Réservation, état véhicule, contrat signé, inspection avec photo, kilométrage, dépôt et libération |
| Market | Réception fournisseur, scan code-barres, balance, vente, retour, stock et expiration |
| Gaz Station | Vente comptant, vente entreprise à crédit approuvée, quart pompiste et rapport |
| Auto Parts | Magasin et entrepôt distincts, transfert, gros/détail, pièce compatible, moto, dépôt et certificat |

## 11. Audit et rapports

- [ ] Chaque action critique crée un événement d'audit.
- [ ] Un événement existant ne peut être modifié ni supprimé par l'application.
- [ ] L'audit d'une vente relie caisse, utilisateur, taux, paiement, impression et synchronisation.
- [ ] Les rapports n'affichent que les données autorisées.
- [ ] Les exports PDF et XLSX correspondent aux totaux affichés.
- [ ] Une dépense, un dépôt et une vente sont séparés dans les rapports.

## 12. Sauvegarde, disponibilité et reprise

- [ ] La sauvegarde PostgreSQL est réalisée et chiffrée.
- [ ] Les documents joints sont inclus dans la sauvegarde.
- [ ] Une restauration sur environnement isolé réussit.
- [ ] Après restauration, une connexion, un reçu, un QR, un audit et un fichier sont accessibles.
- [ ] PostgreSQL et Redis ne sont pas exposés sur Internet.
- [ ] Le certificat HTTPS est valide.
- [ ] Les alertes de santé et sauvegarde sont testées.

## 13. Approbation

Pour chaque version, inscrire :

| Élément | Valeur |
| --- | --- |
| Version | |
| Environnement | |
| Date de recette | |
| Société et site pilote | |
| Testeur métier | |
| Testeur technique | |
| Imprimante et navigateur | |
| Résultat | Acceptée, acceptée avec réserve ou refusée |
| Réserves et correctifs | |
| Approbation propriétaire | |
