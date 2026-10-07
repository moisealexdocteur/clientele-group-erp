# Expérience utilisateur et design système

## 1. Direction

L'interface doit être nette, rapide et directe. Une personne en caisse doit pouvoir comprendre son prochain geste sans lire un manuel. Le design évite les écrans chargés, les petits boutons et les menus qui cachent les actions importantes.

Le produit privilégie :

- design épuré et minimaliste ;
- typographie grande et expressive ;
- expérience tactile mobile-first et tablet-first ;
- informations critiques visibles sans défilement inutile ;
- état hors ligne et synchronisation toujours apparents ;
- retours immédiats après chaque action ;
- même logique visuelle dans tous les modules.

## 2. Écrans de référence

| Écran | Largeur cible | Priorité |
| --- | --- | --- |
| Caisse kiosque | 1024 x 768 et plus | Gros boutons, panier et paiement en un regard |
| Tablette réception | 768 px et plus | Calendrier, folio et check-in |
| Téléphone superviseur | 360 px et plus | Alertes, approbations et rapports synthèses |
| Écran client | 16:9 ou portrait selon matériel | Articles, total et promotion |
| Bureau comptabilité | 1280 px et plus | Rapports, filtres, exports et pièces |

## 3. Règles visuelles

- Taille tactile minimum : 48 x 48 px.
- Police des montants et du total : très visible, sans abréviation ambiguë.
- Une action principale par écran est identifiée par une couleur de marque.
- Les actions irréversibles sont rouges et demandent un motif ou confirmation.
- Les messages utilisent du français clair : Vente confirmée, À synchroniser, Taux à approuver, Imprimante non disponible.
- Les états ne reposent jamais seulement sur une couleur ; ils comportent une icône et un texte.
- Les nombres gardent séparateurs et devise : 1 250,00 HTG ou 20,00 USD.
- Les photos, QR et pièces doivent rester lisibles sur matériel peu performant.

## 4. Barre d'état permanente

Les écrans opérationnels affichent en permanence :

- société et site actifs ;
- utilisateur connecté ;
- caisse ou poste ;
- état Internet : En ligne, Hors ligne, Synchronisation en cours ou Attention ;
- heure Port-au-Prince ;
- accès rapide au verrouillage de session.

Une erreur d'impression, une vente à synchroniser ou un taux bloqué ne doit jamais être caché dans une notification qui disparaît.

## 5. POS

Le POS est organisé en quatre zones sur tablette ou kiosque :

1. catégories et recherche ;
2. catalogue avec image facultative, prix et disponibilité ;
3. panier avec quantités, rabais autorisés et total ;
4. paiement avec HTG, USD, carte, virement ou paiement différé autorisé.

Après confirmation, l'écran affiche clairement : reçu 1234 5678, impression en cours ou en erreur, option courriel ou WhatsApp, et bouton de nouvelle vente.

## 6. Off-line first

Le mode hors ligne doit être honnête. Il ne simule pas une connexion :

- bannière visible ;
- nombre de transactions non synchronisées ;
- date de la dernière synchronisation ;
- taux et catalogue locaux datés ;
- bloc de numéros restant ;
- action désactivée si elle requiert Internet ;
- bouton de reprise et rapport d'exception.

## 7. Écran client

L'écran client reprend les couleurs de la société, mais reste volontairement simple :

- nom ou logo ;
- panier en cours avec texte suffisamment grand ;
- total et devise ;
- promotion, image ou vidéo courte ;
- remerciement après paiement ;
- aucune donnée administrative, client ou employé.

## 8. Accessibilité et robustesse

- Navigation clavier possible pour les scanners et les postes non tactiles.
- Contraste élevé et taille de police ajustable.
- Pas de perte de données lorsqu'un navigateur est rafraîchi pendant un brouillon.
- Confirmation avant sortir d'une saisie non enregistrée.
- Gestion des erreurs réseaux explicite et récupérable.
- Chargement rapide sur Starlink, réseau mobile ou liaison instable.

## 9. Branding

Clientèle Group possède le design système commun. Chaque société peut configurer sans casser l'interface :

- logo ;
- couleur principale et secondaire ;
- coordonnées ;
- en-tête et pied de page ;
- message promotionnel et modèles de reçus ;
- nom affiché dans les courriels et documents.

Les changements de branding sont prévisualisés, versionnés et audités.
