<?php

namespace Tests\Feature;

use App\Models\ApiAccessToken;
use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class FileUploadSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const SVG = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

    public function test_svg_html_and_webp_are_refused_whatever_the_declared_name(): void
    {
        Storage::fake('local');
        [$company, $site, $token] = $this->context('RENT');

        foreach ([
            ['permis.svg', self::SVG],
            ['permis.png', self::SVG],
            ['recu.html', '<!doctype html><html><body><script>alert(1)</script></body></html>'],
            ['recu.pdf', '<html><body>faux PDF</body></html>'],
            ['photo.webp', base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==')],
        ] as [$name, $content]) {
            $this->withToken($token)->withHeader('X-Clientele-Company-Id', $company->id)
                ->postJson('/api/v1/car-rental/files', [
                    'purpose' => 'payment_proof',
                    'site_id' => $site->id,
                    'file' => UploadedFile::fake()->createWithContent($name, $content),
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('file');
        }

        $this->assertDatabaseCount('stored_files', 0);
    }

    public function test_a_file_of_another_company_is_not_found(): void
    {
        Storage::fake('local');
        [$company, $site, $token] = $this->context('RENT');
        [$other, , $otherToken] = $this->context('HOTEL');

        $file = $this->withToken($token)->withHeader('X-Clientele-Company-Id', $company->id)
            ->postJson('/api/v1/car-rental/files', [
                'purpose' => 'payment_proof',
                'site_id' => $site->id,
                'file' => UploadedFile::fake()->createWithContent('recu.pdf', "%PDF-1.4\nRecu\n%%EOF"),
            ])
            ->assertCreated()
            ->json('data');

        $this->withToken($otherToken)->withHeader('X-Clientele-Company-Id', $other->id)
            ->getJson($file['url'])
            ->assertNotFound();
    }

    /** @return array{0: Company, 1: Site, 2: string} */
    private function context(string $code): array
    {
        $company = Company::query()->create([
            'code' => $code,
            'legal_name' => "Société {$code} S.A.",
            'display_name' => "Société {$code}",
            'base_currency' => 'USD',
        ]);
        $site = Site::query()->create([
            'company_id' => $company->id,
            'code' => "{$code}-01",
            'name' => 'Bureau principal',
            'address' => 'Cap-Haïtien',
        ]);
        $user = User::factory()->create(['is_active' => true]);
        CompanyUserAccess::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role_key' => 'prepose',
            'site_scope' => 'all',
            'permissions' => ['rental.payments.submit', 'rental.payments.approve', 'rental.documents.sensitive'],
            'is_active' => true,
        ]);
        [, $token] = ApiAccessToken::issueFor($user, Request::create('/api/v1/auth/login', 'POST'));

        return [$company, $site, $token];
    }
}
