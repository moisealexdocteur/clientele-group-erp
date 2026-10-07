# API Clientèle Group ERP

L'API est une application Laravel 13. Elle démarre le noyau exécutable du produit sans créer de société, d'adresse, de caisse, de client ou de compte de production fictif.

Les routes publiques présentes à ce stade sont limitées à :

- `GET /api/health` : disponibilité de l'API, sans donnée métier ;
- `GET /api/v1/bootstrap` : compatibilité de la PWA, fuseau, devises et état du pilote, sans donnée de société.

Le fichier `database/001_noyau.sql` reste le contrat complet de données. La migration Laravel `2026_10_07_000100_create_organisation_core_tables.php` traduit maintenant le premier sous-ensemble : sociétés, sites, postes de vente, accès locaux et audit.

Le premier démarrage n'insère aucun compte ni donnée opérationnelle. Les migrations activent aussi la politique RLS PostgreSQL pour les sites, postes et événements d'audit ; les futures opérations de société devront passer par `App\Support\CompanyContext`.

L'API ne doit jamais exposer une route qui contourne :

1. l'authentification ;
2. la société active ;
3. la vérification de permission ;
4. l'écriture d'audit ;
5. l'idempotence des opérations de caisse.

`php artisan health:check` vérifie PostgreSQL et Redis. Il sert au conteneur applicatif et n'expose aucun mot de passe ni détail de configuration.
