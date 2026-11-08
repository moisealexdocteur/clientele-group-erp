<?php

namespace App\Support;

/**
 * Profils de rôle proposés à la création d'un utilisateur, selon l'activité
 * de la société. Un profil donne une liste fermée de permissions ; aucun
 * profil ne donne accès à une autre société.
 */
final class RoleProfiles
{
    /** @var array<string, array<string, array{label: string, permissions: array<int, string>}>> */
    private const PROFILES = [
        'car_rental' => [
            'car_rental_administrator' => [
                'label' => 'Administrateur Car Rental',
                'permissions' => [
                    'rental.availability.read',
                    'rental.reservations.create',
                    'rental.reservations.read',
                    'rental.reservations.manage',
                    'rental.vehicles.read',
                    'rental.vehicles.manage',
                    'rental.calendar.read',
                    'rental.payments.submit',
                    'rental.payments.approve',
                    'rental.reservations.override_rate',
                    'rental.payments.credit',
                    'rental.documents.sensitive',
                    'rental.deposits.settle',
                    'rental.invoices.issue',
                    'cash.sessions.operate',
                    'cash.sessions.approve',
                    'cash.reports.read',
                ],
            ],
            'car_rental_agent' => [
                'label' => 'Agent de location',
                'permissions' => [
                    'rental.availability.read',
                    'rental.reservations.create',
                    'rental.reservations.read',
                    'rental.reservations.manage',
                    'rental.vehicles.read',
                    'rental.calendar.read',
                    'rental.payments.submit',
                    'rental.invoices.issue',
                    'cash.sessions.operate',
                ],
            ],
            'car_rental_fleet' => [
                'label' => 'Gestionnaire de flotte',
                'permissions' => [
                    'rental.vehicles.read',
                    'rental.vehicles.manage',
                    'rental.calendar.read',
                ],
            ],
        ],
        'auto_parts' => [
            'auto_parts_administrator' => [
                'label' => 'Administrateur Auto Parts',
                'permissions' => [
                    'parts.catalog.read',
                    'parts.catalog.manage',
                    'parts.stock.read',
                    'parts.stock.manage',
                    'parts.sales.create',
                    'parts.sales.read',
                    'parts.sales.wholesale',
                    'cash.sessions.operate',
                    'cash.sessions.approve',
                    'cash.reports.read',
                ],
            ],
            'auto_parts_seller' => [
                'label' => 'Vendeur',
                'permissions' => [
                    'parts.catalog.read',
                    'parts.stock.read',
                    'parts.sales.create',
                    'parts.sales.read',
                    'cash.sessions.operate',
                ],
            ],
            'auto_parts_storekeeper' => [
                'label' => 'Magasinier',
                'permissions' => [
                    'parts.catalog.read',
                    'parts.catalog.manage',
                    'parts.stock.read',
                    'parts.stock.manage',
                ],
            ],
        ],
    ];

    /** @return array<int, string> */
    public static function keysFor(string $activity): array
    {
        return array_keys(self::PROFILES[$activity] ?? self::PROFILES['car_rental']);
    }

    /** @return array<int, string> */
    public static function permissions(string $roleKey): array
    {
        foreach (self::PROFILES as $profiles) {
            if (isset($profiles[$roleKey])) {
                return $profiles[$roleKey]['permissions'];
            }
        }

        return [];
    }

    public static function label(string $roleKey): string
    {
        foreach (self::PROFILES as $profiles) {
            if (isset($profiles[$roleKey])) {
                return $profiles[$roleKey]['label'];
            }
        }

        return 'Utilisateur Clientèle Group ERP';
    }

    /** @return array<int, array{key: string, label: string}> */
    public static function optionsFor(string $activity): array
    {
        return array_map(
            static fn (string $key): array => ['key' => $key, 'label' => self::label($key)],
            self::keysFor($activity),
        );
    }
}
