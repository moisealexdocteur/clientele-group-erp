# ADR 0001 - Monolithe modulaire

## Statut

Acceptée le 07 octobre 2026.

## Contexte

Clientèle Group ERP doit gérer plusieurs activités, mais démarre sur un VPS Hostinger KVM1 et doit être administrable par une petite équipe.

## Décision

Le produit est construit comme un monolithe modulaire Laravel avec une interface Vue PWA, PostgreSQL et Redis.

## Conséquences

- Déploiement plus simple.
- Transactions financières et audit dans une même base transactionnelle.
- Modules isolés par code, permissions et société.
- Moins de consommation de mémoire, de surveillance et de coûts.
- Une discipline stricte de frontières de modules est nécessaire pour éviter un code désordonné.
