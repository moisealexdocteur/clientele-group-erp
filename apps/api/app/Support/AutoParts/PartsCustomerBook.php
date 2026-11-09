<?php

namespace App\Support\AutoParts;

use App\Models\Company;
use App\Models\CustomerIdentity;
use App\Models\CustomerProfile;
use App\Support\Text;
use Illuminate\Validation\ValidationException;

/**
 * Fiches clients d'Auto Parts. Un client est reconnu par son téléphone dans
 * la société : la même personne n'a qu'une fiche, complétée au fil des achats.
 * Les coordonnées restent chiffrées (customer_profiles) et propres à la société.
 */
final class PartsCustomerBook
{
    /**
     * Fiche existante choisie, sinon fiche du même téléphone, sinon nouvelle fiche.
     * Sans téléphone ni fiche choisie, aucune fiche n'est créée (vente anonyme).
     *
     * @param array{customer_profile_id?: ?string, name?: ?string, phone?: ?string, email?: ?string, address?: ?string} $data
     */
    public function resolve(Company $company, array $data): ?CustomerProfile
    {
        $profileId = $data['customer_profile_id'] ?? null;

        if ($profileId !== null) {
            $profile = CustomerProfile::query()
                ->where('company_id', $company->id)
                ->where('is_active', true)
                ->whereKey($profileId)
                ->first();

            if ($profile === null) {
                throw ValidationException::withMessages(['customer_profile_id' => 'Client introuvable pour cette société.']);
            }

            $this->complete($profile, $data);

            return $profile;
        }

        $phone = CustomerProfile::normalizePhone($data['phone'] ?? null);
        $name = Text::nullableTrimmed($data['name'] ?? null);

        if ($phone === null || $name === null) {
            return null;
        }

        $profile = CustomerProfile::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->where('phone_search_hash', CustomerProfile::searchHash($phone))
            ->first();

        if ($profile !== null) {
            $this->complete($profile, $data);

            return $profile;
        }

        $profile = new CustomerProfile([
            'company_id' => $company->id,
            'customer_identity_id' => CustomerIdentity::query()->create()->id,
            'customer_type' => 'individual',
            'display_name' => $name,
            'address' => Text::nullableTrimmed($data['address'] ?? null),
            'is_active' => true,
        ]);
        $profile->assignContact($data['email'] ?? null, $phone);
        $profile->save();

        return $profile;
    }

    /** Complète une fiche sans écraser ce qui est déjà connu. */
    private function complete(CustomerProfile $profile, array $data): void
    {
        $changed = false;
        $email = CustomerProfile::normalizeEmail($data['email'] ?? null);
        $address = Text::nullableTrimmed($data['address'] ?? null);

        if ($email !== null && ! filled($profile->email)) {
            $profile->assignContact($email, $profile->phone);
            $changed = true;
        }

        if ($address !== null && ! filled($profile->address)) {
            $profile->address = $address;
            $changed = true;
        }

        if ($changed) {
            $profile->save();
        }
    }

    /** @return array<string, mixed> */
    public function payload(CustomerProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'name' => $profile->display_name,
            'phone' => $profile->phone,
            'email' => $profile->email,
            'address' => $profile->address,
            'is_active' => $profile->is_active,
            'created_at' => $profile->created_at?->toIso8601String(),
        ];
    }
}
