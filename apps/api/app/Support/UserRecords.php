<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Indique si un compte a des écritures : réservation, paiement, caisse,
 * document ou action métier journalisée. Un compte avec écritures ne peut
 * pas être supprimé définitivement ; il se désactive.
 *
 * À appeler dans le contexte de la société (CompanyContext::within) : les
 * tables sous RLS ne montrent que les écritures de cette société.
 */
final class UserRecords
{
    /** Colonnes qui désignent l'auteur d'une écriture. */
    private const AUTHOR_COLUMNS = [
        'car_rental_payments' => ['approved_by'],
        'car_rental_inspections' => ['inspector_user_id'],
        'car_rental_security_deposits' => ['settled_by'],
        'car_rental_invoices' => ['issued_by'],
        'car_rental_vehicle_registration_events' => ['changed_by'],
        'cash_sessions' => ['opened_by', 'closed_by', 'reviewed_by'],
        'cash_movements' => ['recorded_by'],
        'stored_files' => ['uploaded_by'],
        'group_exchange_rates' => ['set_by'],
    ];

    /**
     * Événements du journal qui ne sont pas des écritures métier :
     * connexion, refus d'accès, configuration, consultation et impression.
     */
    private const NON_BUSINESS_EVENT_PREFIXES = [
        'auth.',
        'authorization.',
        'configuration.',
        'identity.',
        'file.read',
        'report.',
    ];

    public function hasRecords(string $userId): bool
    {
        return in_array($userId, $this->withRecords([$userId]), true);
    }

    /**
     * @param  array<int, string>  $userIds
     * @return array<int, string> identifiants qui ont au moins une écriture
     */
    public function withRecords(array $userIds): array
    {
        $remaining = array_values(array_unique(array_filter($userIds)));
        $found = [];

        foreach (self::AUTHOR_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if ($remaining === []) {
                    return $found;
                }

                $hits = DB::table($table)
                    ->whereIn($column, $remaining)
                    ->distinct()
                    ->pluck($column)
                    ->map(static fn ($id): string => (string) $id)
                    ->all();

                $found = array_merge($found, $hits);
                $remaining = array_values(array_diff($remaining, $hits));
            }
        }

        if ($remaining !== []) {
            $audit = DB::table('audit_events')->whereIn('actor_id', $remaining);
            foreach (self::NON_BUSINESS_EVENT_PREFIXES as $prefix) {
                $audit->where('event_type', 'not like', $prefix.'%');
            }

            $found = array_merge($found, $audit->distinct()->pluck('actor_id')->map(static fn ($id): string => (string) $id)->all());
        }

        return array_values(array_unique($found));
    }
}
