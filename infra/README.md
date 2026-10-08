# Infrastructure

Le fichier `compose.yaml` décrit la cible applicative de production. La préproduction est créée par `deploy-preprod.sh`, avec un routeur Traefik distinct. Aucun de ces fichiers ne construit d'image sur le KVM1 : les images validées sont tirées de GHCR. Le fichier `compose.build.yaml` est réservé au poste de développement ou à une recette temporaire, jamais au VPS de production. Les secrets réels restent hors Git.

Le proxy HTTPS est géré séparément par `traefik/compose.yaml`. Il est installé une fois sur le VPS, dans `/opt/clientele/traefik`, avant le déploiement de l'application.

Prérequis :

- Traefik opérationnel avec un réseau Docker externe nommé `traefik-public` ;
- certificats valides pour `erp.clientelegroup.tech` et `preprod.erp.clientelegroup.tech` ;
- fichier .env de production sécurisé, dérivé de .env.example ;
- branche validée, dépendances verrouillées et images privées publiées depuis celle-ci lorsque GHCR est activé ;
- sauvegarde de PostgreSQL et test de restauration ;
- recette de l'imprimante thermique réalisée.

Le compose ne publie pas PostgreSQL ni Redis. Seul le service web est routé par Traefik. Les services applicatifs utilisent un réseau de sortie séparé pour DNS et SMTP ; ce réseau ne publie aucun port entrant.

Le service `bootstrap` de `infra/traefik/compose.yaml` émet les premiers certificats et maintient la page temporaire. Pour la préproduction, il reste actif afin de conserver la page `erp.clientelegroup.tech` tant que la production n'existe pas. Son routeur `preprod` a une priorité de repli (`1`), tandis que le routeur applicatif utilise le nom statique `clientele-erp-preprod` et une priorité supérieure. Le routeur de production utilise le nom statique `clientele-erp`. Le bootstrap sera retiré lors de la publication de la vraie production, après vérification du routeur de production.

## Correctif de routage préproduction

`fix-preprod-routing.sh` sert uniquement à corriger un environnement déjà créé par une version antérieure du script de déploiement. Les déploiements actuels utilisent directement les labels Traefik statiques. Le correctif recrée le seul conteneur `web` de préproduction, puis exige la réponse JSON de `/api/health`. Il ne touche pas à la production, à la base de données, à Redis, à SSH ni aux accès root.

Le premier lancement de `app` applique les migrations Laravel avec `APP_RUN_MIGRATIONS=true`. Les services `worker` et `scheduler` sont dans le profil Compose `background` et ne sont pas lancés sur le KVM1 tant que le pilote n'en a pas besoin. PostgreSQL et Redis ne publient aucun port.

## Courriels de sécurité

La connexion et la réinitialisation utilisent un code à usage unique envoyé au courriel personnel. Un environnement réel doit définir un transport SMTP transactionnel et un expéditeur `no-reply@clientelegroup.tech` vérifié. Le transport `MAIL_MAILER=log` est conservé pour le bootstrap de préproduction, mais l’application refusera alors d’émettre des codes pour éviter de les écrire dans les journaux. Ne créez donc aucun compte humain avant d’avoir remplacé ce transport par SMTP et fait un test d’envoi.
