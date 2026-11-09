<?php

namespace App\Http\Controllers\AutoParts\Customers;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CustomerProfile;
use App\Models\MotoOrder;
use App\Models\PartsSale;
use App\Support\AutoParts\PartsCustomerBook;
use App\Support\CarRental\CarRentalCustomerDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Clients d'Auto Parts : recherche exacte par téléphone ou courriel
 * (empreinte), sinon par partie du nom ; sans recherche, les plus récents.
 */
final class ListPartsCustomers extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly PartsCustomerBook $book,
        private readonly CarRentalCustomerDirectory $directory,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        $query = trim((string) ($data['q'] ?? ''));
        $limit = $query === '' ? 50 : 20;

        $base = CustomerProfile::query()->where('company_id', $company->id)->where('is_active', true);

        if ($query === '') {
            $profiles = $base->orderByDesc('updated_at')->limit($limit)->get();
        } elseif (str_contains($query, '@')) {
            $profiles = $base->where('email_search_hash', CustomerProfile::searchHash(CustomerProfile::normalizeEmail($query)))->limit($limit)->get();
        } elseif (preg_match('/^[+\d][\d\s().-]{5,}$/', $query) === 1) {
            $profiles = $base->where('phone_search_hash', CustomerProfile::searchHash(CustomerProfile::normalizePhone($query)))->limit($limit)->get();
        } else {
            // Les noms sont chiffrés : comparaison après lecture, par lots.
            $needle = $this->directory->searchable($query);
            $profiles = new Collection();
            $base->orderByDesc('updated_at')->chunk(500, function ($chunk) use (&$profiles, $needle, $limit): bool {
                foreach ($chunk as $profile) {
                    if (str_contains($this->directory->searchable((string) $profile->display_name), $needle)) {
                        $profiles->push($profile);
                    }
                    if ($profiles->count() >= $limit) {
                        return false;
                    }
                }

                return true;
            });
        }

        $ids = $profiles->pluck('id');
        $orders = MotoOrder::query()->where('company_id', $company->id)->whereIn('customer_profile_id', $ids)
            ->selectRaw('customer_profile_id, count(*) as total')->groupBy('customer_profile_id')->pluck('total', 'customer_profile_id');
        $sales = PartsSale::query()->where('company_id', $company->id)->whereIn('customer_profile_id', $ids)
            ->selectRaw('customer_profile_id, count(*) as total')->groupBy('customer_profile_id')->pluck('total', 'customer_profile_id');

        return response()->json([
            'data' => $profiles->map(fn (CustomerProfile $profile): array => [
                ...$this->book->payload($profile),
                'moto_order_count' => (int) ($orders[$profile->id] ?? 0),
                'sale_count' => (int) ($sales[$profile->id] ?? 0),
            ])->values(),
        ]);
    }
}
