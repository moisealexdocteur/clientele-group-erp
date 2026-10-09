<?php

namespace App\Http\Controllers\CarRental;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CarRentalReservation;
use App\Models\CustomerProfile;
use App\Support\CarRental\CarRentalCustomerDirectory;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SearchCustomers extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly CarRentalCustomerDirectory $customers,
    ) {
    }

    /**
     * Clients connus de la société, pour réutiliser une fiche à la
     * réservation. Recherche exacte par courriel ou téléphone (empreinte),
     * ou par partie du nom. Huit résultats au plus, société active seulement.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
        ]);
        $query = trim($data['q']);

        $base = CustomerProfile::query()
            ->where('company_id', $company->id)
            ->where('is_active', true);

        if (str_contains($query, '@')) {
            $profiles = $base->where('email_search_hash', CustomerProfile::searchHash(CustomerProfile::normalizeEmail($query)))
                ->limit(8)
                ->get();
        } elseif (preg_match('/^[+\d][\d\s().-]{5,}$/', $query) === 1) {
            $profiles = $base->where('phone_search_hash', CustomerProfile::searchHash(CustomerProfile::normalizePhone($query)))
                ->limit(8)
                ->get();
        } else {
            // Les noms sont chiffrés en base : la comparaison se fait après lecture, par lots.
            $needle = $this->customers->searchable($query);
            $profiles = collect();
            $base->orderByDesc('updated_at')->chunk(500, function ($chunk) use (&$profiles, $needle): bool {
                foreach ($chunk as $profile) {
                    if (str_contains($this->customers->searchable((string) $profile->display_name), $needle)) {
                        $profiles->push($profile);
                    }

                    if ($profiles->count() >= 8) {
                        return false;
                    }
                }

                return true;
            });
        }

        $lastReservations = CarRentalReservation::query()
            ->where('company_id', $company->id)
            ->whereIn('customer_profile_id', $profiles->pluck('id'))
            ->selectRaw('customer_profile_id, max(pickup_at) as last_pickup_at, count(*) as reservation_count')
            ->groupBy('customer_profile_id')
            ->get()
            ->keyBy('customer_profile_id');

        // Coordonnées masquées : elles servent à reconnaître le client, pas à les recopier.
        return response()->json([
            'data' => $profiles->map(fn (CustomerProfile $profile): array => [
                'id' => $profile->id,
                'display_name' => $profile->display_name,
                'customer_type' => $profile->customer_type,
                'email_hint' => $this->customers->maskEmail($profile->email),
                'phone_hint' => $this->customers->maskPhone($profile->phone),
                'reservation_count' => (int) ($lastReservations[$profile->id]->reservation_count ?? 0),
                'last_pickup_at' => isset($lastReservations[$profile->id])
                    ? CarbonImmutable::parse($lastReservations[$profile->id]->last_pickup_at)->toIso8601String()
                    : null,
            ])->values(),
        ]);
    }
}
