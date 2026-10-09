<?php

namespace App\Support\CarRental;

use App\Models\CarRentalVehicle;
use App\Models\CompanyUserAccess;
use App\Support\Money;
use App\Support\Text;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Règles de saisie et contrôles commerciaux des véhicules. */
final class CarRentalVehicleRules
{
    public function assertVehicleCommercialTermsConfigured(CarRentalVehicle $vehicle): void
    {
        if ($vehicle->daily_rate_usd === null || Money::toCents((string) $vehicle->daily_rate_usd) <= 0) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Le tarif quotidien en USD doit être configuré pour ce véhicule avant toute réservation.',
            ]);
        }

        if ($vehicle->minimum_security_deposit_usd === null || Money::toCents((string) $vehicle->minimum_security_deposit_usd) < 0) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Le dépôt minimum en USD doit être configuré pour ce véhicule avant toute réservation.',
            ]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function vehicleContractRules(): array
    {
        return [
            'color' => ['nullable', 'string', 'max:48'],
            'fuel_type' => ['nullable', Rule::in(CarRentalVehicle::FUEL_TYPES)],
            'transmission' => ['nullable', Rule::in(CarRentalVehicle::TRANSMISSIONS)],
            'engine_displacement_cc' => ['nullable', 'integer', 'between:50,10000'],
            'doors' => ['nullable', 'integer', 'between:2,6'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function vehicleContractAttributes(array $data): array
    {
        return [
            'color' => Text::nullableTrimmed($data['color'] ?? null),
            'fuel_type' => $data['fuel_type'] ?? null,
            'transmission' => $data['transmission'] ?? null,
            'engine_displacement_cc' => $data['engine_displacement_cc'] ?? null,
            'doors' => $data['doors'] ?? null,
        ];
    }

    /**
     * Le tarif vient de la fiche véhicule. Seul un rôle autorisé
     * (rental.reservations.override_rate) peut appliquer un autre montant ou
     * une autre devise. Retourne vrai lorsqu'un tarif particulier est appliqué.
     */
    public function assertRateAllowed(
        CompanyUserAccess $access,
        CarRentalVehicle $vehicle,
        string $currency,
        string $dailyRate,
    ): bool {
        $matchesVehicle = $currency === 'USD'
            && Money::toCents($dailyRate) === Money::toCents((string) $vehicle->daily_rate_usd);

        if ($matchesVehicle) {
            return false;
        }

        if (! $access->allows('rental.reservations.override_rate')) {
            throw ValidationException::withMessages([
                'daily_rate' => sprintf(
                    'Le tarif de la fiche véhicule s’applique : USD %s par jour. Seul un administrateur peut appliquer un autre tarif.',
                    Money::normalize((string) $vehicle->daily_rate_usd),
                ),
            ]);
        }

        return true;
    }

    /** @return array<string, string> */
    public function vehicleValidationMessages(): array
    {
        return [
            'site_id.required' => 'Sélectionnez une adresse.',
            'site_id.uuid' => 'Sélectionnez une adresse valide.',
            'category.required' => 'Sélectionnez une catégorie.',
            'category.in' => 'Sélectionnez une catégorie valide.',
            'operational_status.in' => 'Sélectionnez un état opérationnel valide.',
            'registration_number.required' => 'Saisissez la plaque d’immatriculation en cours.',
            'registration_number.max' => 'La plaque ne peut pas dépasser 32 caractères.',
            'registration_number.unique' => 'Cette plaque est déjà utilisée par un autre véhicule de cette société.',
            'registration_status.required' => 'Sélectionnez le type de plaque.',
            'registration_status.in' => 'Sélectionnez « Démonstration », « Location » ou « Normale ».',
            'reference_photo_key.in' => 'La photo de référence sélectionnée n’est pas disponible.',
            'vin.max' => 'Le VIN ne peut pas dépasser 64 caractères.',
            'vin.unique' => 'Ce VIN est déjà utilisé par un autre véhicule de cette société.',
            'latest_odometer_km.required' => 'Saisissez le kilométrage actuel.',
            'latest_odometer_km.integer' => 'Le kilométrage doit être un nombre entier.',
            'latest_odometer_km.min' => 'Le kilométrage ne peut pas être négatif.',
            'daily_rate_usd.required' => 'Saisissez le tarif quotidien en USD.',
            'daily_rate_usd.numeric' => 'Le tarif quotidien doit être un montant valide.',
            'daily_rate_usd.gt' => 'Le tarif quotidien doit être supérieur à zéro.',
            'fuel_type.in' => 'Choisissez Essence ou Diesel.',
            'transmission.in' => 'Choisissez Manuelle ou Automatique.',
            'engine_displacement_cc.between' => 'Indiquez une cylindrée en cm³ entre 50 et 10 000.',
            'doors.between' => 'Indiquez un nombre de portes entre 2 et 6.',
            'minimum_security_deposit_usd.required' => 'Saisissez le dépôt minimum en USD.',
            'minimum_security_deposit_usd.numeric' => 'Le dépôt minimum doit être un montant valide.',
            'minimum_security_deposit_usd.gte' => 'Le dépôt minimum ne peut pas être négatif.',
        ];
    }

    /** @return array<string, string> */
    public function vehicleDocumentValidationMessages(): array
    {
        return [
            'documents.required' => 'Ajoutez au moins un document à enregistrer.',
            'documents.array' => 'Les documents doivent être fournis dans un format valide.',
            'documents.min' => 'Ajoutez au moins un document à enregistrer.',
            'documents.max' => 'Vous pouvez enregistrer au plus trois documents à la fois.',
            'documents.*.type.required' => 'Sélectionnez le type de document.',
            'documents.*.type.distinct' => 'Chaque type de document ne peut être saisi qu’une fois.',
            'documents.*.type.in' => 'Sélectionnez un type de document valide.',
            'documents.*.document_number.max' => 'La référence ne peut pas dépasser 100 caractères.',
            'documents.*.issued_at.date_format' => 'Utilisez une date de délivrance valide.',
            'documents.*.expires_at.date_format' => 'Utilisez une date d’expiration valide.',
        ];
    }

    public function canonicalVehicleIdentifier(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $identifier = strtoupper(trim($value));

        return $identifier === '' ? null : $identifier;
    }
}
