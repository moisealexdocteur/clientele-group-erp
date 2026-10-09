<?php

namespace App\Support\CarRental;

use App\Models\Company;
use App\Models\CustomerIdentity;
use App\Models\CustomerProfile;
use Illuminate\Validation\ValidationException;

/** Clients de la société : création, rattachement et recherche masquée. */
final class CarRentalCustomerDirectory
{
    /** @param array<string, mixed> $data */
    public function resolveCustomer(Company $company, array $data): CustomerProfile
    {
        $profileId = $data['customer_profile_id'] ?? null;

        if ($profileId !== null) {
            $profile = CustomerProfile::query()
                ->where('company_id', $company->id)
                ->whereKey($profileId)
                ->where('is_active', true)
                ->first();

            if ($profile === null) {
                throw ValidationException::withMessages([
                    'customer_profile_id' => 'Client introuvable pour cette société.',
                ]);
            }

            return $profile;
        }

        /** @var array<string, mixed> $customer */
        $customer = $data['customer'];
        $consent = (bool) ($customer['group_contact_sharing_consent'] ?? false);
        $identity = CustomerIdentity::query()->create();
        $profile = new CustomerProfile([
            'company_id' => $company->id,
            'customer_identity_id' => $identity->id,
            'customer_type' => $customer['customer_type'] ?? 'individual',
            'display_name' => $customer['display_name'],
            'group_contact_sharing_consent' => $consent,
            'group_contact_sharing_consented_at' => $consent ? now()->utc() : null,
            'is_active' => true,
        ]);
        $profile->assignContact($customer['email'] ?? null, $customer['phone'] ?? null);
        $profile->save();

        return $profile;
    }

    public function maskEmail(?string $email): ?string
    {
        if (! filled($email) || ! str_contains((string) $email, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', (string) $email, 2);

        return mb_substr($local, 0, 2) . '***@' . $domain;
    }

    public function maskPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return $digits === '' || $digits === null ? null : '••• ' . substr($digits, -4);
    }

    public function searchable(string $value): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return strtolower(trim((string) preg_replace('/\s+/', ' ', $ascii === false ? $value : $ascii)));
    }

    public function reservationSearchTerm(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $search = strtoupper((string) preg_replace('/\s+/', '', trim($value)));

        return $search === '' ? null : $search;
    }
}
