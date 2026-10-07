# Infrastructure

Le fichier `compose.yaml` décrit la cible applicative de production et de préproduction. Il ne construit pas d'image sur le KVM1 : les images validées sont tirées de GHCR. Le fichier `compose.build.yaml` est réservé au poste de développement ou à une recette temporaire, jamais au VPS de production. Les secrets réels restent hors Git.

Le proxy HTTPS est géré séparément par `traefik/compose.yaml`. Il est installé une fois sur le VPS, dans `/opt/clientele/traefik`, avant le déploiement de l'application.

Prérequis :

- Traefik opérationnel avec un réseau Docker externe nommé `traefik-public` ;
- certificats valides pour `erp.clientelegroup.tech` et `preprod.erp.clientelegroup.tech` ;
- fichier .env de production sécurisé, dérivé de .env.example ;
- branche validée, dépendances verrouillées et images privées publiées depuis celle-ci lorsque GHCR est activé ;
- sauvegarde de PostgreSQL et test de restauration ;
- recette de l'imprimante thermique réalisée.

Le compose ne publie pas PostgreSQL ni Redis. Seul le service web est routé par Traefik.

Le service `bootstrap` de `infra/traefik/compose.yaml` émet les premiers certificats et maintient la page temporaire. Pour la préproduction, il reste actif afin de conserver la page `erp.clientelegroup.tech` tant que la production n'existe pas. Le routeur applicatif de préproduction utilise un nom distinct et une priorité explicite ; il prend donc le relais uniquement sur `preprod.erp.clientelegroup.tech`. Le bootstrap sera retiré lors de la publication de la vraie production, après vérification du routeur de production.

Le premier lancement de `app` applique les migrations Laravel avec `APP_RUN_MIGRATIONS=true`. Les services `worker` et `scheduler` sont dans le profil Compose `background` et ne sont pas lancés sur le KVM1 tant que le pilote n'en a pas besoin. PostgreSQL et Redis ne publient aucun port.
