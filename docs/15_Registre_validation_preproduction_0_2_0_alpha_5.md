# Registre de validation — préproduction 0.2.0-alpha.5

## 1. Objet

Ce document fige la référence technique validée en préproduction avant la poursuite du développement. Il ne constitue pas une autorisation de production ni une validation des modules métier, des caisses ou de l’impression.

## 2. Référence figée

| Élément | Valeur |
| --- | --- |
| Environnement | Préproduction uniquement |
| URL | `https://preprod.erp.clientelegroup.tech` |
| Référence Git | `0607280c0ca2da0de79f1a6172453fe68cdd752f` |
| Référence Git immuable | `baseline/0.2.0-alpha.5-preprod` |
| Images applicatives | `ghcr.io/moisealexdocteur/clientele-group-erp-app:sha-0607280` et `ghcr.io/moisealexdocteur/clientele-group-erp-web:sha-0607280` |
| Date de validation | 07 octobre 2026, heure de Toronto |
| Fuseau métier | Cap-Haïtien, Haïti — `America/Port-au-Prince` |

## 3. Contrôles réussis

| Contrôle | Résultat |
| --- | --- |
| Conteneur API | Sain |
| PostgreSQL | Sain |
| Redis | Sain |
| Routage HTTPS préproduction | Confirmé |
| `/api/health` | Répond avec l’état attendu |
| Sortie SMTP Gmail | Confirmée |
| Rendu et envoi du modèle de code de connexion | Confirmés par `MODELE_OTP: OK` |
| Connexion propriétaire avec code courriel | Confirmée par le propriétaire |

## 4. Périmètre accepté

Le socle actuellement validé comprend :

- connexion par adresse courriel et mot de passe ;
- code de sécurité envoyé par courriel ;
- réinitialisation personnelle du mot de passe ;
- sessions révocables ;
- contexte société et rôles locaux ;
- journalisation du socle ;
- préproduction Docker, Traefik et SMTP sortant.

## 5. Hors périmètre de cette validation

Les fonctions suivantes restent non acceptées et ne doivent pas être présentées comme prêtes :

- caisses, reçus, QR, impression Epson TMIII et écran client ;
- devise HTG/USD, taux BRH et dérogations ;
- fonctionnement hors ligne ;
- contrats, dépôts, inspections, retours et kilométrage Car Rental ;
- stock, ventes, entrepôts et motocyclettes Auto Parts ;
- Guest House, Market, Hotel, Bar, Restaurant et Gaz Station ;
- importations Excel : règles cadrées, développement à réaliser ;
- sauvegarde restaurée, recette de charge et déploiement de production.

## 6. Règle de stabilité

Toute évolution ultérieure doit :

1. partir de la branche `main` sans modifier la référence `baseline/0.2.0-alpha.5-preprod` ;
2. passer les contrôles automatisés ;
3. utiliser une nouvelle image immuable `sha-…` en préproduction ;
4. refaire la vérification de connexion et de courriel avant une recette métier ;
5. être documentée en français avec son statut réel.

Une régression de connexion, de sécurité, de routage ou de courriel bloque la suite de la recette.
