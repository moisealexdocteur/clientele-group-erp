<?php

namespace App\Support\CarRental;

use App\Models\CarRentalInspection;
use App\Models\CarRentalInvoice;
use App\Models\CarRentalPayment;
use App\Models\CarRentalReservation;
use App\Models\CarRentalSecurityDeposit;
use App\Models\CarRentalVehicle;
use App\Models\CarRentalVehicleDocument;
use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Support\Money;
use App\Support\ReceiptService;
use Carbon\CarbonImmutable;

/** Mise en forme des véhicules, réservations, paiements et documents pour la PWA. */
final class CarRentalPresenter
{
    public function __construct(
        private readonly ReceiptService $receipts,
    ) {
    }

    /** @return array<string, mixed> */
    public function vehiclePayload(
        CarRentalVehicle $vehicle,
        bool $includeManagementDetails = false,
        ?Company $company = null,
    ): array
    {
        $payload = [
            'id' => $vehicle->id,
            'site_id' => $vehicle->site_id,
            'site' => $vehicle->relationLoaded('site') && $vehicle->site !== null ? [
                'id' => $vehicle->site->id,
                'code' => $vehicle->site->code,
                'name' => $vehicle->site->name,
            ] : null,
            'code' => $vehicle->code,
            'category' => $vehicle->category,
            'operational_status' => $vehicle->operational_status,
            'make' => $vehicle->make,
            'model' => $vehicle->model,
            'model_year' => $vehicle->model_year,
            'reference_photo' => $vehicle->referencePhoto(),
            'latest_odometer_km' => $vehicle->latest_odometer_km,
            'daily_rate_usd' => $vehicle->daily_rate_usd,
            'minimum_security_deposit_usd' => $vehicle->minimum_security_deposit_usd,
            'is_active' => $vehicle->is_active,
            'color' => $vehicle->color,
            'fuel_type' => $vehicle->fuel_type,
            'transmission' => $vehicle->transmission,
            'engine_displacement_cc' => $vehicle->engine_displacement_cc,
            'doors' => $vehicle->doors,
            'photo' => $vehicle->photo_file_id === null ? null : [
                'id' => $vehicle->photo_file_id,
                'url' => '/api/v1/car-rental/files/' . $vehicle->photo_file_id,
            ],
        ];

        if ($includeManagementDetails) {
            $payload['registration_number'] = $vehicle->registration_number ?: $vehicle->code;
            $payload['registration_status'] = $vehicle->registration_status ?: 'normal';
            $payload['vin'] = $vehicle->vin;
            $payload['document_statuses'] = $company !== null
                ? $this->vehicleDocumentStatuses($vehicle, $company)
                : [];
        }

        return $payload;
    }

    /** @return array<int, array<string, mixed>> */
    public function vehicleDocumentStatuses(CarRentalVehicle $vehicle, Company $company): array
    {
        $documents = $vehicle->relationLoaded('documents')
            ? $vehicle->documents->keyBy('document_type')
            : collect();

        return collect(CarRentalVehicleDocument::TYPES)
            ->map(function (string $type) use ($documents, $company): array {
                /** @var CarRentalVehicleDocument|null $document */
                $document = $documents->get($type);

                return [
                    'type' => $type,
                    'status' => $this->vehicleDocumentStatus($document, $company),
                    'expires_at' => $document?->expires_at?->format('Y-m-d'),
                ];
            })
            ->all();
    }

    /** @return array<string, mixed> */
    public function vehicleDocumentPayload(CarRentalVehicleDocument $document, Company $company): array
    {
        return [
            'id' => $document->id,
            'type' => $document->document_type,
            'document_number' => $document->document_number,
            'issued_at' => $document->issued_at?->format('Y-m-d'),
            'expires_at' => $document->expires_at?->format('Y-m-d'),
            'status' => $this->vehicleDocumentStatus($document, $company),
        ];
    }

    public function vehicleDocumentStatus(?CarRentalVehicleDocument $document, Company $company): string
    {
        if ($document === null || $document->expires_at === null) {
            return $document === null ? 'not_recorded' : 'not_applicable';
        }

        $today = CarbonImmutable::now($company->timezone)->startOfDay();
        $expiration = CarbonImmutable::instance($document->expires_at)->startOfDay();

        if ($expiration->lessThan($today)) {
            return 'expired';
        }

        if ($expiration->lessThanOrEqualTo($today->addDays(30))) {
            return 'expiring_soon';
        }

        return 'current';
    }

    /** @return array<string, mixed> */
    public function calendarPayload(CarRentalReservation $reservation): array
    {
        return [
            'id' => $reservation->id,
            'number' => $reservation->formattedNumber(),
            'state' => $reservation->state,
            'pickup_at' => $reservation->pickup_at?->toIso8601String(),
            'due_at' => $reservation->due_at?->toIso8601String(),
            'site' => $reservation->site === null ? null : [
                'id' => $reservation->site->id,
                'code' => $reservation->site->code,
                'name' => $reservation->site->name,
            ],
            'vehicle' => $reservation->vehicle === null ? null : $this->vehiclePayload($reservation->vehicle),
        ];
    }

    /** @return array<string, mixed> */
    public function reservationListPayload(CarRentalReservation $reservation): array
    {
        return [
            'id' => $reservation->id,
            'number' => $reservation->formattedNumber(),
            'state' => $reservation->state,
            'pickup_at' => $reservation->pickup_at?->toIso8601String(),
            'due_at' => $reservation->due_at?->toIso8601String(),
            'site' => $reservation->site === null ? null : [
                'id' => $reservation->site->id,
                'code' => $reservation->site->code,
                'name' => $reservation->site->name,
            ],
            'vehicle' => $reservation->vehicle === null ? null : $this->vehiclePayload($reservation->vehicle),
            'customer' => $reservation->customerProfile === null ? null : [
                'id' => $reservation->customerProfile->id,
                'display_name' => $reservation->customerProfile->display_name,
                'customer_type' => $reservation->customerProfile->customer_type,
            ],
        ];
    }

    public function fileUrl(?string $fileId): ?string
    {
        return $fileId === null ? null : '/api/v1/car-rental/files/' . $fileId;
    }

    /** @return array<string, mixed>|null */
    public function inspectionPayload(CarRentalReservation $reservation, string $stage, bool $includeSignatures): ?array
    {
        $inspection = $reservation->relationLoaded('inspections')
            ? $reservation->inspections->firstWhere('stage', $stage)
            : $reservation->inspections()->where('stage', $stage)->first();

        if (! $inspection instanceof CarRentalInspection || $inspection->status !== 'finalized') {
            return null;
        }

        return [
            'inspected_at' => $inspection->inspected_at?->toIso8601String(),
            'odometer_km' => $inspection->odometer_km,
            'fuel_level_percent' => $inspection->fuel_level_percent === null ? null : (int) round((float) $inspection->fuel_level_percent),
            'accessories' => $inspection->accessories ?? [],
            'damage_notes' => $includeSignatures ? $inspection->notes : null,
            'damage_marks' => $inspection->damage_sketch ?? [],
            'photo_urls' => array_map(fn (string $id): string => (string) $this->fileUrl($id), $inspection->photo_file_ids ?? []),
            'company_signer_name' => $inspection->company_signer_name,
            'customer_signed_at' => $inspection->customer_signed_at?->toIso8601String(),
            'company_signed_at' => $inspection->company_signed_at?->toIso8601String(),
            'customer_signature_url' => $includeSignatures ? $this->fileUrl($inspection->customer_signature_file_id) : null,
            'company_signature_url' => $includeSignatures ? $this->fileUrl($inspection->company_signature_file_id) : null,
        ];
    }

    /** @return array<string, mixed>|null */
    public function invoicePayload(CarRentalReservation $reservation): ?array
    {
        $invoice = $reservation->relationLoaded('invoice')
            ? $reservation->invoice
            : $reservation->invoice()->first();

        if (! $invoice instanceof CarRentalInvoice) {
            return null;
        }

        return [
            'id' => $invoice->id,
            'number' => $invoice->formattedNumber(),
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            'currency' => $invoice->currency,
            'total' => $invoice->total,
            'balance_due' => $invoice->balance_due,
            'file_url' => $this->fileUrl($invoice->file_id),
            'snapshot' => $invoice->snapshot,
        ];
    }

    public function reservationPayload(CarRentalReservation $reservation, ?CompanyUserAccess $access = null): array
    {
        // Les coordonnées du client ne sont renvoyées qu'aux rôles qui gèrent la réservation.
        $includeContact = $access?->allows('rental.reservations.manage') ?? false;
        $includeDocuments = $access?->allows('rental.documents.sensitive') ?? false;

        $airportPickupFee = Money::toCents((string) $reservation->airport_pickup_fee_usd);
        $airportDropoffFee = Money::toCents((string) $reservation->airport_dropoff_fee_usd);

        return [
            'id' => $reservation->id,
            'number' => $reservation->formattedNumber(),
            'state' => $reservation->state,
            'site_id' => $reservation->site_id,
            'site' => $reservation->relationLoaded('site') && $reservation->site !== null ? [
                'id' => $reservation->site->id,
                'code' => $reservation->site->code,
                'name' => $reservation->site->name,
            ] : null,
            'pickup_at' => $reservation->pickup_at?->toIso8601String(),
            'due_at' => $reservation->due_at?->toIso8601String(),
            'checked_out_at' => $reservation->checked_out_at?->toIso8601String(),
            'returned_at' => $reservation->returned_at?->toIso8601String(),
            'lock_version' => $reservation->lock_version,
            'pickup_location' => [
                'type' => $reservation->pickup_location_type,
                'detail' => $reservation->pickup_location_detail,
            ],
            'dropoff_location' => [
                'type' => $reservation->dropoff_location_type,
                'detail' => $reservation->dropoff_location_detail,
            ],
            'airport_pickup_fee_usd' => Money::fromCents($airportPickupFee),
            'airport_dropoff_fee_usd' => Money::fromCents($airportDropoffFee),
            'airport_fees_total_usd' => Money::fromCents($airportPickupFee + $airportDropoffFee),
            'currency' => $reservation->currency,
            'daily_rate' => $reservation->daily_rate,
            'rate_overridden' => (bool) $reservation->rate_overridden,
            'minimum_security_deposit_usd' => $reservation->minimum_security_deposit_usd,
            'kilometer_plan' => $reservation->kilometer_plan,
            'included_km' => $reservation->included_km,
            'additional_km_rate' => $reservation->additional_km_rate,
            'driver_full_name' => $reservation->driver_full_name,
            'driver_license_expires_at' => $reservation->driver_license_expires_at?->format('Y-m-d'),
            'driver_license_verified' => $reservation->driver_license_verified_at !== null,
            'vehicle' => $reservation->vehicle === null ? null : $this->vehiclePayload($reservation->vehicle),
            'customer' => $reservation->customerProfile === null ? null : [
                'id' => $reservation->customerProfile->id,
                'display_name' => $reservation->customerProfile->display_name,
                'customer_type' => $reservation->customerProfile->customer_type,
                ...($includeContact ? [
                    'email' => $reservation->customerProfile->email,
                    'phone' => $reservation->customerProfile->phone,
                ] : []),
            ],
            'payments' => $reservation->relationLoaded('payments')
                ? $reservation->payments->map(fn (CarRentalPayment $payment): array => $this->paymentPayload($payment))
                : null,
            'security_deposits' => $reservation->relationLoaded('securityDeposits')
                ? $reservation->securityDeposits->map(fn (CarRentalSecurityDeposit $deposit): array => $this->securityDepositPayload($deposit))
                : null,
            'checkout_requirements' => $this->checkoutRequirements($reservation),
            'driver_license' => $includeContact && $reservation->driver_license_country !== null ? [
                'country' => $reservation->driver_license_country,
                'subdivision' => $reservation->driver_license_subdivision,
                'number' => $reservation->driver_license_number,
                'expires_at' => $reservation->driver_license_expires_at?->format('Y-m-d'),
                'front_url' => $includeDocuments ? $this->fileUrl($reservation->driver_license_front_file_id) : null,
                'back_url' => $includeDocuments ? $this->fileUrl($reservation->driver_license_back_file_id) : null,
            ] : null,
            'additional_driver' => $includeContact && filled($reservation->additional_driver_name) ? [
                'name' => $reservation->additional_driver_name,
                'license_number' => $reservation->additional_driver_license_number,
            ] : null,
            'checkout_inspection' => in_array($reservation->state, ['checked_out', 'completed'], true)
                ? $this->inspectionPayload($reservation, 'pre_rental', $includeContact)
                : null,
            'return_inspection' => $reservation->state === 'completed'
                ? $this->inspectionPayload($reservation, 'post_rental', $includeContact)
                : null,
            'additional_charges' => $reservation->additional_charges ?? [],
            'invoice' => $this->invoicePayload($reservation),
            'contract' => [
                'issued_at' => $reservation->contract_issued_at?->toIso8601String(),
                'file_url' => $includeContact ? $this->fileUrl($reservation->contract_file_id) : null,
                'snapshot' => $includeContact ? $reservation->contract_snapshot : null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function paymentPayload(CarRentalPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'kind' => $payment->payment_kind,
            'method' => $payment->method,
            'status' => $payment->status,
            'currency' => $payment->currency,
            'amount' => $payment->amount,
            'proof_file_url' => $payment->proof_file_id === null ? null : '/api/v1/car-rental/files/' . $payment->proof_file_id,
            'exchange_rate_htg_per_usd' => $payment->exchange_rate_htg_per_usd,
            'amount_in_reservation_currency' => $payment->amount_in_reservation_currency,
            'receipt_number' => $payment->receipt_number === null ? null : $this->receipts->display($payment->receipt_number),
            'submitted_at' => $payment->submitted_at?->toIso8601String(),
            'approved_at' => $payment->approved_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function securityDepositPayload(CarRentalSecurityDeposit $deposit): array
    {
        return [
            'id' => $deposit->id,
            'payment_id' => $deposit->payment_id,
            'method' => $deposit->method,
            'status' => $deposit->status,
            'currency' => $deposit->currency,
            'amount' => $deposit->amount,
            'held_at' => $deposit->held_at?->toIso8601String(),
            'released_at' => $deposit->released_at?->toIso8601String(),
            'applied_amount' => $deposit->applied_amount,
            'settlement_note' => $deposit->settlement_note,
        ];
    }

    /** @return array<string, mixed> */
    public function checkoutRequirements(CarRentalReservation $reservation): array
    {
        $payments = $reservation->relationLoaded('payments')
            ? $reservation->payments
            : $reservation->payments()->get();
        $deposits = $reservation->relationLoaded('securityDeposits')
            ? $reservation->securityDeposits
            : $reservation->securityDeposits()->get();
        $minimumDeposit = Money::toCents((string) ($reservation->minimum_security_deposit_usd ?? '0'));
        $heldDepositUsd = Money::sumCents($deposits
            ->filter(static fn (CarRentalSecurityDeposit $deposit): bool => $deposit->status === 'held' && $deposit->currency === 'USD')
            ->map(static fn (CarRentalSecurityDeposit $deposit): string => (string) $deposit->amount));

        return [
            'contract_terms_configured' => filled(Company::query()->whereKey($reservation->company_id)->value('rental_contract_terms')),
            'driver_license_verified' => $reservation->driver_license_verified_at !== null,
            'minimum_security_deposit_configured' => $reservation->minimum_security_deposit_usd !== null,
            'approved_rental_payment' => $payments->contains(
                static fn (CarRentalPayment $payment): bool => $payment->payment_kind === 'rental' && $payment->status === 'approved',
            ),
            'minimum_security_deposit_usd' => Money::fromCents($minimumDeposit),
            'held_security_deposit_usd' => Money::fromCents($heldDepositUsd),
            'security_deposit_satisfied' => $heldDepositUsd >= $minimumDeposit,
        ];
    }
}
