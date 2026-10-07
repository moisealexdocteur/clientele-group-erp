# Impression et mode kiosque

## 1. Règle essentielle

Un site web ne peut pas imprimer silencieusement sur une imprimante locale par lui-même. Les navigateurs bloquent ce comportement pour protéger l'utilisateur. L'impression automatique est donc une fonction du poste de caisse, configurée avec une politique de navigateur ou un agent local, et non une fonction du VPS.

Le produit déclenche une demande d'impression après confirmation d'une transaction. Le poste kiosque autorise cette demande vers l'imprimante thermique configurée.

## 2. Solution retenue

| Situation | Solution | Statut |
| --- | --- | --- |
| Une imprimante thermique par poste | Edge sur Windows, imprimante par défaut 80 mm et politique SilentPrintingEnabled | Solution recommandée |
| Chrome ou Edge en plein écran | Lancement kiosque avec option kiosk-printing, après test du poste | Solution compatible |
| Deux imprimantes distinctes, tiroir-caisse, choix automatique de périphérique | Agent local signé tel que QZ Tray ou intégration fabricant | Option avancée |
| Poste sans politique kiosque | Aperçu d'impression normal avec validation manuelle | Repli, pas automatique |

Microsoft documente la politique Edge SilentPrintingEnabled : lorsqu'elle est activée, Edge ferme l'aperçu et imprime vers l'imprimante par défaut. Elle sera validée sur la version exacte de chaque poste avant mise en service.

### 2.1 Matériel cible et limite actuelle

La cible est une imprimante thermique Epson de la famille indiquée « TMIII », largeur 80 mm, pour chaque caisse. Les imprimantes ne sont pas encore disponibles physiquement ; aucune compatibilité finale, pilote ni procédure d'ouverture de tiroir-caisse ne sera déclarée validée avant la recette matérielle.

À la réception, la fiche de chaque imprimante doit préciser :

- la référence exacte Epson ;
- le type de connexion : USB, Ethernet ou série ;
- le système du poste de caisse ;
- le pilote installé et sa version ;
- le nom de l'imprimante configurée par défaut ;
- le résultat des reçus client, Administration et rapport journalier.

L'application produit du HTML/CSS 80 mm standard. Elle ne dépend pas d'un pilote Epson sur le VPS : le pilote reste installé sur chaque poste local.

## 3. Profil d'un poste de caisse

Chaque poste possède une fiche de configuration avec :

- société et site ;
- caisse associée ;
- nom de l'appareil et identifiant technique ;
- navigateur et mode kiosque ;
- imprimante client par défaut ;
- imprimante Administration facultative ;
- largeur de papier : 80 mm ;
- copies client et Administration ;
- impression automatique active ou non ;
- écran client appairé ;
- dernier test d'impression, dernier utilisateur et état de synchronisation.

La fiche du poste est réservée aux administrateurs et son changement est audité.

## 4. Reçus thermiques

### 4.1 Mise en page

Le document s'imprime avec une feuille CSS dédiée :

- largeur 80 mm ;
- marge zéro ou marge définie par le pilote ;
- police très lisible ;
- QR de reçu ;
- aucune URL, date du navigateur ou en-tête injecté par le navigateur ;
- coupure naturelle après le pied de page ;
- version courte pour reçu client et version Administration clairement marquée.

L'application conserve aussi une version HTML et PDF du reçu, mais le PDF n'est pas utilisé pour l'impression thermique automatique sauf besoin de réimpression.

### 4.2 Données d'un reçu client

- logo et informations de la société ;
- numéro 1234 5678 ;
- date et heure Haïti ;
- site et caisse ;
- lignes, quantités, prix, rabais, taxes et nombre total d'articles ;
- totaux par devise et taux de change si utilisé ;
- modes de paiement ;
- QR de vérification ;
- pied de page configuré ;
- état particulier : brouillon, annulé, remboursé ou hors ligne en attente de synchronisation.

### 4.3 Copie Administration

La copie Administration ajoute :

- caissier, poste et session de caisse ;
- détail de toutes les lignes de paiement ;
- taux appliqué et source du taux ;
- références de virement ou de dépôt ;
- motif de remise, d'annulation ou de réimpression ;
- identifiant de synchronisation ;
- marque visible ADMINISTRATION.

## 5. Démarrage kiosque

### 5.1 Edge sur Windows

Une fois l'imprimante reçue, le poste est préparé par l'administrateur :

1. Installer le pilote de l'imprimante thermique et définir le papier 80 mm.
2. Choisir l'imprimante thermique comme imprimante par défaut du compte kiosque.
3. Vérifier que l'imprimante par défaut n'est pas Enregistrer au format PDF.
4. Activer la politique Edge SilentPrintingEnabled pour le compte ou l'appareil kiosque.
5. Créer le raccourci d'ouverture avec le site de production ou de préproduction.
6. Activer le mode kiosque ou mode application selon le niveau de verrouillage souhaité.
7. Effectuer le test client, la copie Administration, la réimpression et le rapport journalier.

Exemple de lancement en mode application : msedge.exe --app=https://erp.exemple.tld/pos --kiosk-printing

Le mode application est préférable au plein écran absolu pour une caisse tenue par un employé qui doit basculer vers d'autres outils autorisés. Le mode kiosque complet est préférable pour une borne cliente.

### 5.2 Chrome

Chrome peut être lancé avec l'option kiosk-printing sur un profil de caisse distinct. L'administrateur doit effectuer un test sur le matériel réel à chaque mise à jour importante du navigateur, car l'impression silencieuse dépend du pilote, de l'OS et des politiques appliquées.

Exemple : chrome.exe --kiosk https://erp.exemple.tld/pos --kiosk-printing

### 5.3 Deux imprimantes ou tiroir-caisse

Un navigateur imprime normalement sur une seule imprimante par défaut. Si le client et l'administration utilisent deux imprimantes distinctes, ou si le tiroir-caisse doit s'ouvrir de manière fiable, le produit utilise un agent local avec liste blanche d'imprimantes et certificat de signature.

Cette option ne sera activée qu'après identification des modèles d'imprimantes et de tiroir-caisse. Elle évite de construire une fausse promesse de sélection automatique avec une simple page web.

## 6. Séquence de vente

~~~mermaid
sequenceDiagram
  participant P as "POS"
  participant A as "API"
  participant I as "Imprimante locale"
  P->>A: Confirmer vente
  A-->>P: Reçu signé et numéro
  P->>I: Reçu client
  P->>I: Copie Administration
  P-->>A: Statut impression
~~~

L'impression ne bloque pas la confirmation financière. Si l'imprimante échoue, la vente reste confirmée, l'échec est visible et une réimpression contrôlée est proposée.

## 7. Écran client

L'écran client s'ouvre dans un second navigateur, un second onglet plein écran ou un appareil distinct. Il se relie à un seul poste de caisse au moyen :

- d'un URL à jeton secret non prévisible ;
- d'un code de jumelage à usage unique et durée limitée ;
- d'un identifiant de caisse ;
- d'un canal temps réel avec reconnexion.

Il montre les articles en cours et les promotions, puis réinitialise l'affichage après paiement. L'administrateur peut couper l'accès immédiatement. Une URL volée devient inutile après révocation.

## 8. Mode hors ligne

En mode hors ligne, l'application imprime un reçu seulement si le poste possède :

- un bloc de numéros préalloués ;
- un catalogue, un taux de change et des taxes synchronisés ;
- une clé de poste encore valide ;
- un statut local qui indique que la vente reste à synchroniser.

Le reçu affiche une mention de synchronisation en attente. Dès le retour de réseau, le serveur valide la vente, conserve le même numéro et notifie l'opérateur si une exception doit être traitée.

## 9. Procédure de recette matérielle

Avant d'autoriser un poste :

- imprimer un reçu client ;
- imprimer la copie Administration ;
- vérifier 80 mm, marges, QR lisible et absence d'en-tête navigateur ;
- vérifier les caractères français et les montants HTG/USD ;
- couper le réseau et réaliser une vente de test autorisée ;
- reconnecter et confirmer qu'il n'y a ni doublon ni perte ;
- simuler une imprimante hors ligne et vérifier le statut de reprise ;
- imprimer un rapport de fermeture ;
- documenter modèle d'imprimante, pilote, version navigateur et résultat.

## 10. Références techniques

- Politique Microsoft Edge SilentPrintingEnabled : https://learn.microsoft.com/fr-fr/deployedge/microsoft-edge-policies/silentprintingenabled
- Configuration kiosque Edge : https://learn.microsoft.com/en-us/deployedge/microsoft-edge-configure-kiosk-mode

Ces références guident la configuration du poste. Elles ne remplacent pas la recette sur l'imprimante réellement utilisée.
