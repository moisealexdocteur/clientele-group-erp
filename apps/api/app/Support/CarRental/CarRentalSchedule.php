<?php

namespace App\Support\CarRental;

use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Périodes de réservation et de planning, à l’heure de Cap-Haïtien. */
final class CarRentalSchedule
{
    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function interval(Company $company, string $pickupAt, string $dueAt): array
    {
        $pickup = CarbonImmutable::parse($pickupAt, $company->timezone)->utc();
        $due = CarbonImmutable::parse($dueAt, $company->timezone)->utc();

        if ($due->lessThanOrEqualTo($pickup)) {
            throw ValidationException::withMessages([
                'due_at' => 'La date de fin doit être postérieure à la date de début.',
            ]);
        }

        return [$pickup, $due];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function calendarInterval(Company $company, string $fromValue, string $toValue): array
    {
        $from = CarbonImmutable::parse($fromValue, $company->timezone);
        $to = CarbonImmutable::parse($toValue, $company->timezone);

        if ($this->isDateOnly($fromValue)) {
            $from = $from->startOfDay();
        }

        if ($this->isDateOnly($toValue)) {
            // Le champ Fin est inclusif dans l'interface : la requête utilise
            // donc le début du jour suivant comme borne de fin exclusive.
            $to = $to->addDay()->startOfDay();
        }

        $from = $from->utc();
        $to = $to->utc();

        if ($to->lessThanOrEqualTo($from)) {
            throw ValidationException::withMessages([
                'to' => 'La date de fin doit être postérieure ou égale à la date de début.',
            ]);
        }

        return [$from, $to];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function defaultCalendarInterval(Company $company): array
    {
        $today = CarbonImmutable::now($company->timezone)->startOfDay();
        $from = $today->startOfMonth();
        $monthEnd = $today->endOfMonth()->startOfDay();
        $to = $monthEnd->addDay();

        // Pendant les sept derniers jours, la vue comprend aussi les sept
        // premiers jours du mois suivant afin d'anticiper les retours.
        if ($today->greaterThanOrEqualTo($monthEnd->subDays(6))) {
            $to = $to->addDays(7);
        }

        return [$from->utc(), $to->utc()];
    }

    public function isDateOnly(string $value): bool
    {
        return preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1;
    }
}
