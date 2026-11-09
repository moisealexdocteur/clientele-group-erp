<?php

namespace App\Http\Controllers\AutoParts\Customers;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\CustomerProfile;
use App\Support\AuditLogger;
use App\Support\AutoParts\PartsCustomerBook;
use App\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Correction des coordonnées d'un client ; le téléphone reste unique dans la société. */
final class UpdatePartsCustomer extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly PartsCustomerBook $book,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request, string $customer): JsonResponse
    {
        $company = $this->company($request);
        $actor = $request->user();
        $profile = CustomerProfile::query()->where('company_id', $company->id)->whereKey($customer)->first();
        abort_if($profile === null, 404, 'Client introuvable.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:40', 'regex:/\A\+?[0-9 ()-]{7,40}\z/'],
            'email' => ['nullable', 'email:rfc', 'max:254'],
            'address' => ['nullable', 'string', 'max:255'],
        ], [
            'name.required' => 'Saisissez le nom complet du client.',
            'phone.required' => 'Saisissez le téléphone du client.',
            'phone.regex' => 'Saisissez un numéro de téléphone valide, par exemple +509 3700 0000.',
            'email.email' => 'Saisissez une adresse courriel valide.',
        ]);

        $phone = CustomerProfile::normalizePhone($data['phone']);
        $duplicate = CustomerProfile::query()->where('company_id', $company->id)->where('is_active', true)
            ->where('phone_search_hash', CustomerProfile::searchHash($phone))
            ->whereKeyNot($profile->id)->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['phone' => 'Ce téléphone appartient déjà à un autre client.']);
        }

        $profile->display_name = trim($data['name']);
        $profile->address = Text::nullableTrimmed($data['address'] ?? null);
        $profile->assignContact($data['email'] ?? null, $phone);
        $profile->save();

        // Journal sans coordonnées : seule la fiche concernée est notée.
        $this->audit->record(
            eventType: 'parts.customer_updated',
            companyId: $company->id,
            actorId: $actor?->id,
            actorType: $actor === null ? 'SYSTEM' : 'USER',
            subjectType: CustomerProfile::class,
            subjectId: $profile->id,
            metadata: [],
        );

        return response()->json(['data' => $this->book->payload($profile)]);
    }
}
