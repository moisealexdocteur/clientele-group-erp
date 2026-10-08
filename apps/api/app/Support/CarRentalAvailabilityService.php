<?php

namespace App\Support;

use App\Models\CarRentalReservation;
use App\Models\CarRentalVehicle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class CarRentalAvailabilityService
{
    /**
     * @return Collection<int, CarRentalVehicle>
     */
    public function availableVehicles(
        string $companyId,
        string $siteId,
        CarbonImmutable $pickupAt,
        CarbonImmutable $dueAt,
        ?string $category = null,
    ): Collection {
        $this->assertValidInterval($pickupAt, $dueAt);

        return $this->candidateQuery($companyId, $siteId, $pickupAt, $dueAt, $category)
            ->orderBy('code')
            ->get();
    }

    public function reserveVehicle(
        string $companyId,
        string $siteId,
        CarbonImmutable $pickupAt,
        CarbonImmutable $dueAt,
        ?string $vehicleId,
        ?string $category,
    ): CarRentalVehicle {
        $this->assertValidInterval($pickupAt, $dueAt);

        if ($vehicleId !== null) {
            $vehicle = CarRentalVehicle::query()
                ->where('company_id', $companyId)
                ->where('site_id', $siteId)
                ->whereKey($vehicleId)
                ->lockForUpdate()
                ->first();

            if ($vehicle === null) {
                throw ValidationException::withMessages([
                    'vehicle_id' => 'Véhicule introuvable pour cette société et cette adresse.',
                ]);
            }

            if ($category !== null && $vehicle->category !== $category) {
                throw ValidationException::withMessages([
                    'vehicle_id' => 'Le véhicule ne correspond pas à la catégorie demandée.',
                ]);
            }

            $this->assertVehicleCanBeReserved($vehicle, $pickupAt, $dueAt);

            return $vehicle;
        }

        if ($category === null) {
            throw ValidationException::withMessages([
                'category' => 'Choisissez une catégorie ou un véhicule précis.',
            ]);
        }

        $vehicle = $this->candidateQuery($companyId, $siteId, $pickupAt, $dueAt, $category)
            ->orderBy('code')
            ->lockForUpdate()
            ->first();

        if ($vehicle === null) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Aucun véhicule disponible pour cette période.',
            ]);
        }

        return $vehicle;
    }

    private function assertVehicleCanBeReserved(
        CarRentalVehicle $vehicle,
        CarbonImmutable $pickupAt,
        CarbonImmutable $dueAt,
    ): void {
        if (! $vehicle->is_active || $vehicle->operational_status === 'garage') {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Ce véhicule n’est pas disponible à la location.',
            ]);
        }

        $overlap = CarRentalReservation::query()
            ->where('company_id', $vehicle->company_id)
            ->where('vehicle_id', $vehicle->id)
            ->whereIn('state', CarRentalReservation::ACTIVE_STATES)
            ->where('pickup_at', '<', $dueAt)
            ->where('due_at', '>', $pickupAt)
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Ce véhicule est déjà réservé ou en circulation pendant cette période.',
            ]);
        }
    }

    private function candidateQuery(
        string $companyId,
        string $siteId,
        CarbonImmutable $pickupAt,
        CarbonImmutable $dueAt,
        ?string $category,
    ) {
        return CarRentalVehicle::query()
            ->where('company_id', $companyId)
            ->where('site_id', $siteId)
            ->where('is_active', true)
            ->where('operational_status', '!=', 'garage')
            ->when($category !== null, static fn ($query) => $query->where('category', $category))
            ->whereDoesntHave('reservations', static function ($query) use ($pickupAt, $dueAt): void {
                $query
                    ->whereIn('state', CarRentalReservation::ACTIVE_STATES)
                    ->where('pickup_at', '<', $dueAt)
                    ->where('due_at', '>', $pickupAt);
            });
    }

    private function assertValidInterval(CarbonImmutable $pickupAt, CarbonImmutable $dueAt): void
    {
        if ($dueAt->lessThanOrEqualTo($pickupAt)) {
            throw ValidationException::withMessages([
                'due_at' => 'Le retour prévu doit être après le début de la location.',
            ]);
        }
    }
}
