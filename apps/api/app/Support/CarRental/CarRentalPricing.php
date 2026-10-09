<?php

namespace App\Support\CarRental;

use App\Models\CarRentalReservation;
use App\Models\CarRentalSecurityDeposit;
use App\Models\CarRentalVehicle;
use App\Models\Company;
use App\Models\Site;
use App\Support\Money;
use App\Support\ReceiptService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Frais, lieux, contrat figé et facture figée. */
final class CarRentalPricing
{
    public function __construct(
        private readonly ReceiptService $receipts,
    ) {
    }

    /** Frais aéroport réglé dans Configuration pour la société, en USD. */
    public function airportServiceFee(Company $company, string $locationType, bool $apply, string $field): string
    {
        if (! $apply) {
            return '0.00';
        }

        if ($locationType !== 'cap_haitien_airport') {
            throw ValidationException::withMessages([
                $field => 'Le frais aéroport s’applique uniquement lorsque le lieu est l’Aéroport International du Cap-Haïtien.',
            ]);
        }

        return Money::normalize((string) ($company->rental_airport_fee_usd ?? '0'));
    }

    /**
     * Frais retenus au retour, dans la devise de la réservation. Aucun frais
     * n'est appliqué sans case cochée : nettoyage (montant réglé dans
     * Configuration) et kilométrage supplémentaire selon le prix au kilomètre
     * du contrat.
     *
     * @param array<string, mixed> $data
     * @return array<int, array{code: string, label: string, amount: string}>
     */
    public function returnCharges(Company $company, CarRentalReservation $reservation, int $drivenKm, array $data): array
    {
        $charges = [];

        if (($data['apply_cleaning_fee'] ?? false) === true) {
            if ($reservation->currency !== 'USD') {
                throw ValidationException::withMessages([
                    'apply_cleaning_fee' => 'Les frais de nettoyage sont réglés en USD et s’appliquent à une location en USD. Utilisez « Autres frais » pour une location en HTG.',
                ]);
            }

            $charges[] = ['code' => 'cleaning', 'label' => 'Frais de nettoyage', 'amount' => Money::normalize((string) ($company->rental_cleaning_fee_usd ?? '0'))];
        }

        if (($data['apply_extra_km'] ?? false) === true) {
            $extra = $this->extraKilometers($reservation, $drivenKm);

            if ($extra === 0 || $reservation->additional_km_rate === null) {
                throw ValidationException::withMessages([
                    'apply_extra_km' => 'Aucun kilométrage supplémentaire facturable pour cette location.',
                ]);
            }

            $charges[] = [
                'code' => 'extra_km',
                'label' => sprintf('Kilométrage supplémentaire : %d km', $extra),
                'amount' => Money::fromCents($extra * Money::toCents((string) $reservation->additional_km_rate)),
            ];
        }

        foreach ($data['other_charges'] ?? [] as $charge) {
            $charges[] = [
                'code' => 'other',
                'label' => trim((string) $charge['label']),
                'amount' => Money::normalize((string) $charge['amount']),
            ];
        }

        return $charges;
    }

    public function extraKilometers(CarRentalReservation $reservation, int $drivenKm): int
    {
        if ($reservation->kilometer_plan !== 'limited' || $reservation->included_km === null) {
            return 0;
        }

        return max(0, $drivenKm - (int) $reservation->included_km);
    }

    /** @return array<string, mixed> */
    public function invoiceSnapshot(Company $company, CarRentalReservation $reservation): array
    {
        $currency = $reservation->currency;
        $days = max(1, (int) ceil(CarbonImmutable::instance($reservation->pickup_at)->diffInMinutes(CarbonImmutable::instance($reservation->due_at)) / 1440));
        $rateCents = Money::toCents((string) $reservation->daily_rate);
        $lines = [[
            'label' => sprintf('Location : %d jour%s × %s %s', $days, $days > 1 ? 's' : '', Money::formatFr((string) $reservation->daily_rate), $currency),
            'amount' => Money::fromCents($days * $rateCents),
        ]];

        $airport = Money::toCents((string) $reservation->airport_pickup_fee_usd) + Money::toCents((string) $reservation->airport_dropoff_fee_usd);

        if ($airport > 0 && $currency === 'USD') {
            $lines[] = ['label' => 'Frais aéroport', 'amount' => Money::fromCents($airport)];
        }

        foreach ($reservation->additional_charges ?? [] as $charge) {
            $lines[] = ['label' => (string) $charge['label'], 'amount' => (string) $charge['amount']];
        }

        $total = Money::sumCents(array_column($lines, 'amount'));

        $payments = [];
        $otherCurrencyPayments = [];

        foreach ($reservation->payments as $payment) {
            if ($payment->payment_kind !== 'rental' || $payment->status !== 'approved') {
                continue;
            }

            $entry = [
                'method' => $payment->method,
                'currency' => $payment->currency,
                'amount' => (string) $payment->amount,
                'date' => ($payment->approved_at ?? $payment->submitted_at)?->toIso8601String(),
                'receipt_number' => $payment->receipt_number === null ? null : $this->receipts->display($payment->receipt_number),
            ];

            if ($payment->currency === $currency) {
                $payments[] = $entry;
            } elseif ($payment->amount_in_reservation_currency !== null && $payment->exchange_rate_htg_per_usd !== null) {
                // Converti au taux enregistré lors du paiement.
                $payments[] = [
                    ...$entry,
                    'currency' => $currency,
                    'amount' => (string) $payment->amount_in_reservation_currency,
                    'original_currency' => $payment->currency,
                    'original_amount' => (string) $payment->amount,
                    'exchange_rate_htg_per_usd' => (string) $payment->exchange_rate_htg_per_usd,
                ];
            } else {
                $otherCurrencyPayments[] = $entry;
            }
        }

        // Les paiements à crédit sont accordés, pas encaissés : ils restent dus.
        $paid = Money::sumCents(array_map(
            static fn (array $payment): string => $payment['method'] === 'credit' ? '0' : $payment['amount'],
            $payments,
        ));
        $credit = Money::sumCents(array_map(
            static fn (array $payment): string => $payment['method'] === 'credit' ? $payment['amount'] : '0',
            $payments,
        ));
        $usdDeposits = $reservation->securityDeposits
            ->filter(static fn (CarRentalSecurityDeposit $deposit): bool => $deposit->currency === 'USD');
        $depositApplied = Money::sumCents($usdDeposits->map(static fn (CarRentalSecurityDeposit $deposit): string => (string) ($deposit->applied_amount ?? '0')));
        $depositCounted = $currency === 'USD' ? $depositApplied : 0;
        $depositReleased = Money::sumCents($usdDeposits->map(static fn (CarRentalSecurityDeposit $deposit): string => (string) $deposit->amount)) - $depositApplied;

        $checkout = $reservation->inspections->firstWhere('stage', 'pre_rental');
        $return = $reservation->inspections->firstWhere('stage', 'post_rental');
        $vehicle = $reservation->vehicle;
        $profile = $reservation->customerProfile;

        return [
            'lessor' => [
                'name' => $company->legal_name,
                'tax_identification_number' => $company->tax_identification_number,
                'address' => $company->legal_address,
                'phone_numbers' => $company->phone_numbers,
            ],
            'customer' => [
                'name' => $profile?->display_name,
                'email' => $profile?->email,
                'phone' => $profile?->phone,
            ],
            'reservation_number' => $reservation->formattedNumber(),
            'vehicle' => trim(implode(' ', array_filter([$vehicle?->make, $vehicle?->model, $vehicle?->model_year]))),
            'pickup_at' => $reservation->checked_out_at?->toIso8601String() ?? $reservation->pickup_at?->toIso8601String(),
            'due_at' => $reservation->due_at?->toIso8601String(),
            'returned_at' => $reservation->returned_at?->toIso8601String(),
            'odometer_out_km' => $checkout?->odometer_km,
            'odometer_in_km' => $return?->odometer_km,
            'fuel_out_percent' => $checkout?->fuel_level_percent === null ? null : (int) round((float) $checkout->fuel_level_percent),
            'fuel_in_percent' => $return?->fuel_level_percent === null ? null : (int) round((float) $return->fuel_level_percent),
            'currency' => $currency,
            'lines' => $lines,
            'payments' => $payments,
            'other_currency_payments' => $otherCurrencyPayments,
            'totals' => [
                'total' => Money::fromCents($total),
                'paid' => Money::fromCents($paid),
                'credit' => Money::fromCents($credit),
                'deposit_applied' => Money::fromCents($depositCounted),
                'balance_due' => Money::fromCents(max(0, $total - $paid - $depositCounted)),
                'overpaid' => Money::fromCents(max(0, $paid + $depositCounted - $total)),
            ],
            'deposit' => [
                'retained_usd' => Money::fromCents($depositApplied),
                'released_usd' => Money::fromCents(max(0, $depositReleased)),
            ],
            'timezone' => $company->timezone,
        ];
    }

    /** @return array<string, mixed> */
    /**
     * Copie figée de ce qui figure au contrat : identité du loueur,
     * conditions générales et caractéristiques du véhicule au moment de
     * la signature. Une modification ultérieure ne change pas le contrat.
     *
     * @return array<string, mixed>
     */
    public function contractSnapshot(Company $company, CarRentalVehicle $vehicle): array
    {
        return [
            'lessor' => [
                'name' => $company->legal_name,
                'display_name' => $company->display_name,
                'representative' => $company->legal_representative,
                'tax_identification_number' => $company->tax_identification_number,
                'address' => $company->legal_address,
                'phone_numbers' => $company->phone_numbers,
            ],
            'terms' => (string) $company->rental_contract_terms,
            'terms_sha256' => hash('sha256', (string) $company->rental_contract_terms),
            'vehicle' => [
                'make' => $vehicle->make,
                'model' => $vehicle->model,
                'model_year' => $vehicle->model_year,
                'registration_number' => $vehicle->registration_number ?: $vehicle->code,
                'vin' => $vehicle->vin,
                'color' => $vehicle->color,
                'fuel_type' => $vehicle->fuel_type,
                'transmission' => $vehicle->transmission,
                'engine_displacement_cc' => $vehicle->engine_displacement_cc,
                'doors' => $vehicle->doors,
                'category' => $vehicle->category,
            ],
            'timezone' => $company->timezone,
        ];
    }

    public function locationDetail(string $type, ?string $detail, Site $site): ?string
    {
        return match ($type) {
            'cap_haitien_airport' => 'Aéroport International du Cap-Haïtien',
            'site' => trim($site->name . ' · ' . $site->address),
            default => $detail,
        };
    }
}
