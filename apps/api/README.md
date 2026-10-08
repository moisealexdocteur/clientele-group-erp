# API Clientèle Group ERP

L'API est une application Laravel 13. Elle démarre le noyau exécutable du produit sans créer de société, d'adresse, de caisse, de client ou de compte de production fictif.

Les routes publiques présentes à ce stade sont limitées à :

- `GET /api/health` : disponibilité de l'API, sans donnée métier ;
- `GET /api/v1/bootstrap` : compatibilité de la PWA, fuseau, devises et état du pilote, sans donnée de société.

Les routes d’identité ne révèlent aucune donnée de société avant une connexion valide :

- `POST /api/v1/auth/login` puis `POST /api/v1/auth/login/verify` : mot de passe et code courriel à usage unique ;
- `POST /api/v1/auth/password/forgot` puis `POST /api/v1/auth/password/reset` : récupération personnelle ;
- `GET /api/v1/auth/me` et `POST /api/v1/auth/logout` : session Bearer opaque ;
- `GET /api/v1/context` : exige le jeton et `X-Clientele-Company-Id`, puis ne renvoie que la société et les sites autorisés.

Le pilote Car Rental exige aussi le jeton, la société active, la permission métier et le périmètre de site :

- `GET /api/v1/car-rental/availability` : véhicules réellement disponibles sur une période ; permission `rental.availability.read` ;
- `POST /api/v1/car-rental/reservations` et `GET /api/v1/car-rental/reservations/{id}` : création et lecture de réservation ; permissions `rental.reservations.create` / `rental.reservations.read` ;
- `POST /api/v1/car-rental/reservations/{id}/payments` : soumission espèces ou virement Sogebank ; permission `rental.payments.submit` ;
- `POST /api/v1/car-rental/reservations/{id}/payments/{payment}/approve` : approbation séparée ; permission `rental.payments.approve`.

Les références de réservation sont stockées sur huit chiffres et présentées sous la forme `0000 0001`. Les coordonnées client et les références de virement ne sont pas copiées dans l’audit.

Le transport de courriel `log` est interdit aux codes de sécurité hors tests. Avant de créer le premier compte humain en préproduction, configurer un SMTP transactionnel puis exécuter `php artisan identity:provision-owner adresse@personnelle.ht` dans le conteneur `app`. Le mot de passe est demandé de façon interactive et ne doit jamais être passé dans la ligne de commande.

Le fichier `database/001_noyau.sql` reste le contrat complet de données. La migration Laravel `2026_10_07_000100_create_organisation_core_tables.php` traduit maintenant le premier sous-ensemble : sociétés, sites, postes de vente, accès locaux et audit.

Le premier démarrage n'insère aucun compte ni donnée opérationnelle. Les migrations activent aussi la politique RLS PostgreSQL pour les sites, postes et événements d'audit ; les futures opérations de société devront passer par `App\Support\CompanyContext`.

L'API ne doit jamais exposer une route qui contourne :

1. l'authentification ;
2. la société active ;
3. la vérification de permission ;
4. l'écriture d'audit ;
5. l'idempotence des opérations de caisse.

`php artisan health:check` vérifie PostgreSQL et Redis. Il sert au conteneur applicatif et n'expose aucun mot de passe ni détail de configuration.
