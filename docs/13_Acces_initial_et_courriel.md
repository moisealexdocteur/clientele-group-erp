# Accès initial et vérification des courriels

## 1. Périmètre

Cette procédure concerne uniquement la préproduction :

- URL : `https://preprod.erp.clientelegroup.tech`
- serveur : VPS Clientèle Group
- compte initial : propriétaire du système

Ne pas utiliser cette procédure sur la production avant la recette complète.

## 2. Créer le premier compte propriétaire

Depuis le VPS, avec le compte `clientele-deploy` :

```bash
cd /opt/clientele/erp-preprod

docker compose --env-file .env -f compose.yaml exec app \
  php artisan identity:provision-owner \
  "adresse.personnelle@example.com" \
  --name="Nom complet"
```

Le terminal demande le mot de passe deux fois. Les caractères saisis ne sont pas affichés.

### Exigences du mot de passe

Le mot de passe doit contenir :

- au moins 12 caractères ;
- une majuscule ;
- une minuscule ;
- un chiffre ;
- un symbole.

Les deux saisies doivent être identiques. Exemple de forme acceptable : `Exemple!2026Test`. Ne pas utiliser cet exemple comme mot de passe réel.

En cas d’échec, le terminal affiche l’exigence complète. Aucun compte n’est créé tant que le mot de passe n’est pas conforme.

## 3. Vérifier l’envoi SMTP avant la première connexion

Le code de sécurité de connexion est envoyé par courriel. Tester la configuration avant d’ouvrir une session :

```bash
cd /opt/clientele/erp-preprod

docker compose --env-file .env -f compose.yaml exec -T app \
  php artisan system:test-smtp "adresse.personnelle@example.com"
```

Résultat attendu :

```
Configuration SMTP
Mailer : smtp
Hôte : smtp.gmail.com
Port : 587
Identifiant configuré : oui
Mot de passe configuré : oui
Message de test envoyé à adresse.personnelle@example.com.
```

La commande n’affiche jamais le mot de passe SMTP. En cas d’échec, elle indique le type de problème à corriger : service, hôte, port, chiffrement ou mot de passe d’application.

## 4. Première connexion

1. Ouvrir l’URL de préproduction.
2. Saisir l’adresse personnelle du propriétaire et son mot de passe.
3. Saisir le code à six chiffres reçu par courriel.
4. Sélectionner une société uniquement lorsque celle-ci a été configurée et attribuée au compte.

L’adresse personnelle, le mot de passe et le code à six chiffres ne doivent jamais être envoyés par message, courriel ou capture d’écran.

## 5. Écran de connexion

L’écran de connexion doit respecter ces règles :

- français uniquement ;
- aucun texte promotionnel ;
- un seul formulaire clair par étape ;
- champs et actions tactiles d’au moins 48 px ;
- action principale visible sans déplacement latéral ;
- message d’erreur concis et exploitable ;
- lien de réinitialisation du mot de passe ;
- affichage mobile prioritaire, puis tablette et kiosque.

## 6. Dépannage

| Message observé | Cause probable | Action |
| --- | --- | --- |
| « Adresse courriel ou mot de passe incorrect. » | Identifiant ou mot de passe erroné. | Vérifier la saisie ou utiliser la réinitialisation. |
| « Le code de sécurité ne peut pas être envoyé pour le moment. » | Configuration SMTP absente ou envoi refusé. | Exécuter `system:test-smtp`, puis corriger la configuration mail. |
| « Le code est invalide, expiré ou déjà utilisé. » | Code incorrect, expiré ou déjà consommé. | Recommencer la connexion pour demander un nouveau code. |
| « Trop de tentatives. » | Protection anti-brute-force active. | Attendre le délai indiqué avant de recommencer. |
