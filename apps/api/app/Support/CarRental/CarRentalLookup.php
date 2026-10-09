<?php

namespace App\Support\CarRental;

use App\Models\CarRentalReservation;
use App\Models\CarRentalVehicle;
use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\StoredFile;
use App\Support\CompanySiteAuthorizer;
use App\Support\FileVault;
use Illuminate\Validation\ValidationException;

/** Lecture d’une réservation ou d’un véhicule dans le contexte de société et d’adresse autorisé. */
final class CarRentalLookup
{
    public function __construct(
        private readonly FileVault $files,
        private readonly CompanySiteAuthorizer $siteAuthorizer,
    ) {
    }

    /**
     * @param array<int, string> $relations
     */
    public function reservationFor(
        Company $company,
        CompanyUserAccess $access,
        string $reservation,
        array $relations = [],
        bool $forUpdate = false,
    ): CarRentalReservation {
        $query = CarRentalReservation::query()
            ->where('company_id', $company->id)
            ->whereKey($reservation);

        if ($relations !== []) {
            $query->with($relations);
        }

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        $model = $query->first();
        abort_if($model === null, 404, 'Réservation introuvable.');
        $this->siteAuthorizer->siteFor($company, $access, $model->site_id);

        return $model;
    }

    public function vehicleForReservation(Company $company, CarRentalReservation $reservation): CarRentalVehicle
    {
        $vehicle = CarRentalVehicle::query()
            ->where('company_id', $company->id)
            ->whereKey($reservation->vehicle_id)
            ->lockForUpdate()
            ->first();

        if ($vehicle === null || $vehicle->site_id !== $reservation->site_id) {
            throw ValidationException::withMessages([
                'vehicle_id' => 'Le véhicule de cette réservation est introuvable pour cette adresse.',
            ]);
        }

        return $vehicle;
    }

    public function vehicleFor(Company $company, CompanyUserAccess $access, string $vehicle): CarRentalVehicle
    {
        $model = CarRentalVehicle::query()
            ->where('company_id', $company->id)
            ->whereKey($vehicle)
            ->first();

        abort_if($model === null, 404, 'Véhicule introuvable.');
        $this->siteAuthorizer->siteFor($company, $access, $model->site_id);

        return $model;
    }

    public function assertLockVersion(CarRentalReservation $reservation, int $expectedVersion): void
    {
        if ($reservation->lock_version !== $expectedVersion) {
            throw ValidationException::withMessages([
                'reservation' => 'Cette réservation a été modifiée par un autre utilisateur. Actualisez-la avant de continuer.',
            ]);
        }
    }

    /**
     * @param array<int, string> $ids
     * @return array<int, string>
     */
    public function inspectionPhotoIds(Company $company, array $ids): array
    {
        $found = [];

        foreach (array_values(array_unique($ids)) as $index => $photoId) {
            $found[] = $this->files->find($company, $photoId, StoredFile::PURPOSE_INSPECTION_PHOTO, "inspection_photo_file_ids.{$index}")->id;
        }

        return $found;
    }
}
