<?php

namespace Tests\Feature;

use App\Models\ApiAccessToken;
use App\Models\AuditEvent;
use App\Models\CarRentalVehicle;
use App\Models\CarRentalReservation;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\CompanyUserAccess;
use App\Models\CompanyUserSiteAccess;
use App\Models\Site;
use App\Models\StoredFile;
use App\Models\User;
use App\Mail\CarRentalCustomerNotificationMail;
use App\Support\CarRentalCustomerNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class CarRentalReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authorized_agent_can_reserve_an_available_vehicle_with_a_numbered_reference(): void
    {
        [$user, $company, $site, $token] = $this->context([
            'rental.availability.read',
            'rental.reservations.create',
            'rental.reservations.read',
        ]);
        $vehicle = $this->vehicle($company, $site, 'SUV-01', 'suv');

        $response = $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
            ]));

        $response
            ->assertCreated()
            ->assertJsonPath('data.number', '0000 0001')
            ->assertJsonPath('data.state', 'reserved')
            ->assertJsonPath('data.vehicle.code', 'SUV-01')
            ->assertJsonPath('data.pickup_location.detail', 'Bureau Cap-Haïtien · Cap-Haïtien')
            ->assertJsonPath('data.dropoff_location.detail', 'Aéroport International du Cap-Haïtien')
            ->assertJsonPath('data.customer.display_name', 'Jean Pierre');

        $this->assertDatabaseHas('car_rental_reservations', [
            'company_id' => $company->id,
            'vehicle_id' => $vehicle->id,
            'reservation_number' => '00000001',
            'state' => 'reserved',
        ]);

        $event = AuditEvent::query()->where('event_type', 'car_rental.reservation_created')->firstOrFail();
        self::assertSame($company->id, $event->company_id);
        self::assertArrayNotHasKey('email', $event->metadata);
        self::assertArrayNotHasKey('phone', $event->metadata);
    }

    public function test_a_reservation_email_uses_the_customer_notification_template(): void
    {
        Mail::fake();

        [, $company, $site, $token] = $this->context([
            'rental.reservations.create',
        ]);
        $vehicle = $this->vehicle($company, $site, 'SUV-MAIL', 'suv');

        $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
            ]))
            ->assertCreated()
            ->assertJsonPath('customer_notification_sent', true);

        Mail::assertSent(CarRentalCustomerNotificationMail::class, function (CarRentalCustomerNotificationMail $mail): bool {
            return $mail->reservationNumber === '0000 0001'
                && $mail->subjectLine === 'Votre réservation est confirmée - Clientèle Group'
                && ($mail->details['Véhicule'] ?? null) === 'Toyota Test'
                && ! in_array('SUV-MAIL', $mail->details, true)
                && str_ends_with((string) $mail->vehicleImageUrl, '/vehicle-images/car-rental-suv.svg')
                && $mail->pdfAttachments === [];
        });
    }

    public function test_an_invoice_notification_requires_a_valid_pdf_before_it_is_sent(): void
    {
        Mail::fake();

        [, $company, $site, $token] = $this->context([
            'rental.reservations.create',
        ]);
        $vehicle = $this->vehicle($company, $site, 'SUV-INVOICE', 'suv');
        $reservationId = $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
            ]))
            ->assertCreated()
            ->json('data.id');

        Mail::fake();
        $reservation = CarRentalReservation::query()
            ->with(['vehicle', 'customerProfile'])
            ->findOrFail($reservationId);
        $notifications = app(CarRentalCustomerNotificationService::class);

        self::assertFalse($notifications->notifyInvoice($company, $reservation, []));
        Mail::assertNothingSent();

        self::assertTrue($notifications->notifyInvoice($company, $reservation, [[
            'name' => 'facture-0000-0001.pdf',
            'content' => '%PDF-1.7\nClientèle Group',
            'mime' => 'application/pdf',
        ]]));

        Mail::assertSent(CarRentalCustomerNotificationMail::class, function (CarRentalCustomerNotificationMail $mail): bool {
            return $mail->subjectLine === 'Votre facture est disponible - Clientèle Group'
                && count($mail->pdfAttachments) === 1
                && $mail->pdfAttachments[0]['name'] === 'facture-0000-0001.pdf';
        });

        $audit = AuditEvent::query()
            ->where('event_type', 'car_rental.customer_notification_skipped_no_pdf')
            ->firstOrFail();
        self::assertArrayNotHasKey('email', $audit->metadata);
        self::assertArrayNotHasKey('phone', $audit->metadata);
    }

    public function test_a_signed_contract_notification_requires_a_valid_pdf_before_it_is_sent(): void
    {
        Mail::fake();

        [, $company, $site, $token] = $this->context([
            'rental.reservations.create',
        ]);
        $vehicle = $this->vehicle($company, $site, 'SUV-CONTRACT', 'suv');
        $reservationId = $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
            ]))
            ->assertCreated()
            ->json('data.id');

        Mail::fake();
        $reservation = CarRentalReservation::query()
            ->with(['vehicle', 'customerProfile'])
            ->findOrFail($reservationId);
        $notifications = app(CarRentalCustomerNotificationService::class);

        self::assertFalse($notifications->notifySignedContract($company, $reservation, []));
        Mail::assertNothingSent();

        self::assertTrue($notifications->notifySignedContract($company, $reservation, [[
            'name' => 'contrat-signe-0000-0001.pdf',
            'content' => '%PDF-1.7\nClientèle Group',
            'mime' => 'application/pdf',
        ]]));

        Mail::assertSent(CarRentalCustomerNotificationMail::class, function (CarRentalCustomerNotificationMail $mail): bool {
            return $mail->subjectLine === 'Votre contrat de location signé est disponible - Clientèle Group'
                && count($mail->pdfAttachments) === 1
                && $mail->pdfAttachments[0]['name'] === 'contrat-signe-0000-0001.pdf';
        });
    }

    public function test_an_overlapping_reservation_is_refused_and_the_vehicle_can_be_selected_by_category(): void
    {
        [, $company, $site, $token] = $this->context([
            'rental.availability.read',
            'rental.reservations.create',
        ]);
        $vehicle = $this->vehicle($company, $site, 'PICK-01', 'pickup');

        $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
                'category' => 'pickup',
            ]))
            ->assertCreated();

        $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'category' => 'pickup',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('vehicle_id');

        $this->requestFor($token, $company)
            ->getJson('/api/v1/car-rental/availability?' . http_build_query([
                'site_id' => $site->id,
                'pickup_at' => '2026-11-02T10:00:00-04:00',
                'due_at' => '2026-11-05T10:00:00-04:00',
                'category' => 'pickup',
            ]))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_an_agent_can_apply_optional_airport_service_fees_in_usd(): void
    {
        [, $company, $site, $token] = $this->context([
            'rental.reservations.create',
        ]);
        $vehicle = $this->vehicle($company, $site, 'SUV-AIRPORT', 'suv');

        $reservation = $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
                'pickup_location_type' => 'cap_haitien_airport',
                'dropoff_location_type' => 'cap_haitien_airport',
                'apply_airport_pickup_fee' => true,
                'apply_airport_dropoff_fee' => true,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.airport_pickup_fee_usd', '20.00')
            ->assertJsonPath('data.airport_dropoff_fee_usd', '20.00')
            ->assertJsonPath('data.airport_fees_total_usd', '40.00')
            ->json('data');

        $this->assertDatabaseHas('car_rental_reservations', [
            'id' => $reservation['id'],
            'airport_pickup_fee_usd' => '20.00',
            'airport_dropoff_fee_usd' => '20.00',
        ]);

        $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
                'pickup_location_type' => 'site',
                'apply_airport_pickup_fee' => true,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('apply_airport_pickup_fee');

        // Le frais vient de la configuration de la société, plus d'une constante.
        $company->forceFill(['rental_airport_fee_usd' => '25.50'])->save();
        $other = $this->vehicle($company, $site, 'SUV-AIRPORT-2', 'suv');
        $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $other->id,
                'pickup_location_type' => 'cap_haitien_airport',
                'apply_airport_pickup_fee' => true,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.airport_pickup_fee_usd', '25.50')
            ->assertJsonPath('data.airport_fees_total_usd', '25.50');

        // Un montant avec exposant ou trois décimales est refusé.
        $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $other->id,
                'daily_rate' => '1.3e2',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('daily_rate');
    }

    public function test_a_selected_site_scope_cannot_read_or_create_outside_its_grant(): void
    {
        [$user, $company, $site, $token, $access] = $this->context([
            'rental.availability.read',
        ], 'selected');

        $this->requestFor($token, $company)
            ->getJson('/api/v1/car-rental/availability?' . http_build_query([
                'site_id' => $site->id,
                'pickup_at' => '2026-11-01T10:00:00-04:00',
                'due_at' => '2026-11-02T10:00:00-04:00',
            ]))
            ->assertForbidden();

        CompanyUserSiteAccess::query()->create([
            'company_user_access_id' => $access->id,
            'company_id' => $company->id,
            'site_id' => $site->id,
            'is_active' => true,
        ]);
        $this->vehicle($company, $site, 'MID-01', 'mid_suv');

        $this->requestFor($token, $company)
            ->getJson('/api/v1/car-rental/availability?' . http_build_query([
                'site_id' => $site->id,
                'pickup_at' => '2026-11-01T10:00:00-04:00',
                'due_at' => '2026-11-02T10:00:00-04:00',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_sogebank_transfer_requires_an_uploaded_receipt_and_an_authorized_approval(): void
    {
        Storage::fake('local');

        [$user, $company, $site, $token] = $this->context([
            'rental.reservations.create',
            'rental.payments.submit',
            'rental.payments.approve',
        ]);
        $vehicle = $this->vehicle($company, $site, 'SUV-02', 'suv');
        $reservation = $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
            ]))
            ->assertCreated()
            ->json('data');

        $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation['id']}/payments", [
                'payment_kind' => 'security_deposit',
                'method' => 'bank_transfer',
                'currency' => 'USD',
                'amount' => '250.00',
                'bank_reference' => 'SOG-2026-0001',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('proof_file_id');

        $receipt = $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/files', [
                'purpose' => 'payment_proof',
                'site_id' => $site->id,
                'file' => UploadedFile::fake()->createWithContent('recu sogebank Jean.pdf', "%PDF-1.4\nRecu Sogebank\n%%EOF"),
            ])
            ->assertCreated()
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->json('data');

        $stored = StoredFile::query()->findOrFail($receipt['id']);
        self::assertSame(hash('sha256', "%PDF-1.4\nRecu Sogebank\n%%EOF"), $stored->sha256);
        self::assertStringNotContainsString('Jean', $stored->path);
        Storage::disk('local')->assertExists($stored->path);

        $payment = $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation['id']}/payments", [
                'payment_kind' => 'security_deposit',
                'method' => 'bank_transfer',
                'currency' => 'USD',
                'amount' => '250.00',
                'bank_reference' => 'SOG-2026-0001',
                'proof_file_id' => $receipt['id'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.proof_file_url', '/api/v1/car-rental/files/' . $receipt['id'])
            ->json('data');

        $this->assertDatabaseHas('car_rental_payments', [
            'id' => $payment['id'],
            'bank_name' => 'Sogebank',
            'proof_file_id' => $receipt['id'],
            'proof_sha256' => $stored->sha256,
        ]);

        $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation['id']}/payments/{$payment['id']}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $event = AuditEvent::query()->where('event_type', 'car_rental.payment_submitted')->firstOrFail();
        self::assertArrayNotHasKey('bank_reference', $event->metadata);
    }

    public function test_a_receipt_can_be_read_by_its_uploader_or_an_approver_only(): void
    {
        Storage::fake('local');

        [, $company, $site, $token] = $this->context(['rental.payments.submit']);
        $receipt = $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/files', [
                'purpose' => 'payment_proof',
                'site_id' => $site->id,
                'file' => UploadedFile::fake()->createWithContent('recu.pdf', "%PDF-1.4\nRecu\n%%EOF"),
            ])
            ->assertCreated()
            ->json('data');

        $this->requestFor($token, $company)
            ->get($receipt['url'])
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $otherToken = $this->additionalUser($company, ['rental.reservations.read']);
        $this->requestFor($otherToken, $company)
            ->getJson($receipt['url'])
            ->assertForbidden();

        $approverToken = $this->additionalUser($company, ['rental.payments.approve']);
        $this->requestFor($approverToken, $company)
            ->get($receipt['url'])
            ->assertOk();

        $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/files', [
                'purpose' => 'payment_proof',
                'site_id' => $site->id,
                'file' => UploadedFile::fake()->createWithContent('recu.txt', 'pas un document'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/files', [
                'purpose' => 'vehicle_photo',
                'site_id' => $site->id,
                'file' => UploadedFile::fake()->createWithContent('photo.png', $this->pngBytes()),
            ])
            ->assertForbidden();
    }

    public function test_only_an_authorized_role_can_change_the_vehicle_rate(): void
    {
        [, $company, $site, $agentToken] = $this->context(['rental.reservations.create']);
        $vehicle = $this->vehicle($company, $site, 'SUV-RATE', 'suv');

        $this->requestFor($agentToken, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
                'daily_rate' => '100.00',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('daily_rate');

        $this->requestFor($agentToken, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
                'currency' => 'HTG',
                'daily_rate' => '130.00',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('daily_rate');

        $adminToken = $this->additionalUser($company, ['rental.reservations.create', 'rental.reservations.override_rate']);
        $this->requestFor($adminToken, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
                'daily_rate' => '100.00',
            ]))
            ->assertCreated()
            ->assertJsonPath('data.daily_rate', '100.00')
            ->assertJsonPath('data.rate_overridden', true);
    }

    public function test_a_reserved_booking_can_be_fully_edited_and_the_confirmation_resent(): void
    {
        Mail::fake();

        [, $company, $site, $token] = $this->context([
            'rental.reservations.create',
            'rental.reservations.read',
            'rental.reservations.manage',
        ]);
        $suv = $this->vehicle($company, $site, 'SUV-EDIT', 'suv');
        $pickup = $this->vehicle($company, $site, 'PICK-EDIT', 'pickup');
        $pickup->forceFill(['daily_rate_usd' => '200.00', 'minimum_security_deposit_usd' => '800.00'])->save();

        $reservation = $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $suv->id,
            ]))
            ->assertCreated()
            ->json('data');

        Mail::fake();

        $updated = $this->requestFor($token, $company)
            ->patchJson("/api/v1/car-rental/reservations/{$reservation['id']}", [
                'expected_lock_version' => $reservation['lock_version'],
                'vehicle_id' => $pickup->id,
                'pickup_at' => '2026-11-03T09:00:00-05:00',
                'due_at' => '2026-11-06T09:00:00-05:00',
                'customer' => [
                    'customer_type' => 'individual',
                    'display_name' => 'Marie Joseph',
                    'email' => 'marie.joseph@example.test',
                    'phone' => '+509 3700 1111',
                ],
                'daily_rate' => '130.00',
                'notify_customer' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.vehicle.code', 'PICK-EDIT')
            ->assertJsonPath('data.daily_rate', '200.00')
            ->assertJsonPath('data.minimum_security_deposit_usd', '800.00')
            ->assertJsonPath('data.rate_overridden', false)
            ->assertJsonPath('data.customer.display_name', 'Marie Joseph')
            ->assertJsonPath('data.customer.email', 'marie.joseph@example.test')
            ->assertJsonPath('customer_notification_sent', true)
            ->json('data');

        Mail::assertSent(CarRentalCustomerNotificationMail::class, function (CarRentalCustomerNotificationMail $mail): bool {
            return $mail->subjectLine === 'Votre réservation a été mise à jour - Clientèle Group'
                && ! in_array('PICK-EDIT', $mail->details, true);
        });

        $event = AuditEvent::query()->where('event_type', 'car_rental.reservation_updated')->firstOrFail();
        self::assertContains('vehicle', $event->metadata['changed']);
        self::assertContains('customer', $event->metadata['changed']);
        self::assertArrayNotHasKey('email', $event->metadata);

        Mail::fake();
        $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation['id']}/notify")
            ->assertOk()
            ->assertJsonPath('customer_notification_sent', true);
        Mail::assertSent(CarRentalCustomerNotificationMail::class);

        $this->requestFor($token, $company)
            ->patchJson("/api/v1/car-rental/reservations/{$reservation['id']}", [
                'expected_lock_version' => $updated['lock_version'],
                'vehicle_id' => $pickup->id,
                'pickup_at' => '2026-11-03T09:00:00-05:00',
                'due_at' => '2026-11-06T09:00:00-05:00',
                'daily_rate' => '150.00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('daily_rate');
    }

    public function test_credit_is_limited_to_authorized_roles_and_to_the_rental_amount(): void
    {
        [, $company, $site, $agentToken] = $this->context([
            'rental.reservations.create',
            'rental.payments.submit',
        ]);
        $vehicle = $this->vehicle($company, $site, 'SUV-CREDIT', 'suv');
        $reservation = $this->requestFor($agentToken, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
            ]))
            ->assertCreated()
            ->json('data');

        $credit = [
            'payment_kind' => 'rental',
            'method' => 'credit',
            'currency' => 'USD',
            'amount' => '390.00',
        ];

        $this->requestFor($agentToken, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation['id']}/payments", $credit)
            ->assertForbidden();

        $adminToken = $this->additionalUser($company, ['rental.payments.submit', 'rental.payments.credit']);
        $this->requestFor($adminToken, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation['id']}/payments", [...$credit, 'payment_kind' => 'security_deposit'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('method');

        $this->requestFor($adminToken, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation['id']}/payments", $credit)
            ->assertCreated()
            ->assertJsonPath('data.method', 'credit')
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_check_out_requires_payment_deposit_international_license_checkout_sheet_and_signatures(): void
    {
        Mail::fake();
        Storage::fake('local');

        [, $company, $site, $token] = $this->context([
            'rental.reservations.create',
            'rental.reservations.manage',
            'rental.payments.submit',
            'rental.payments.approve',
        ]);
        $vehicle = $this->vehicle($company, $site, 'SUV-CHECKOUT', 'suv');
        $reservation = $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
            ]))
            ->assertCreated()
            ->json('data');

        $checkout = [
            'expected_lock_version' => $reservation['lock_version'],
            'driver_full_name' => 'Jean Pierre',
            'driver_license_number' => 'HT-123456',
            'driver_license_expires_at' => '2027-01-01',
            'driver_license_country' => 'ht',
            'driver_license_front_file_id' => $this->upload($token, $company, $site, 'driver_license_front'),
            'driver_license_back_file_id' => $this->upload($token, $company, $site, 'driver_license_back'),
            'driver_license_verified' => true,
            'odometer_km' => 150,
            'fuel_level_percent' => 100,
            'accessories' => ['spare_tire', 'jack'],
            'damage_notes' => 'Rayure légère sur le pare-chocs arrière.',
            'inspection_photo_file_ids' => [$this->upload($token, $company, $site, 'inspection_photo')],
            'terms_accepted' => true,
            'customer_signature_file_id' => $this->upload($token, $company, $site, 'signature'),
            'company_signature_file_id' => $this->upload($token, $company, $site, 'signature'),
            'company_signer_name' => 'Agent Comptoir',
        ];
        $url = "/api/v1/car-rental/reservations/{$reservation['id']}/check-out";

        // Les conditions du contrat doivent être configurées avant toute remise.
        $this->requestFor($token, $company)
            ->postJson($url, $checkout)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contract_terms');

        $company->forceFill(['rental_contract_terms' => "Article 1 - Objet\nTexte de test."])->save();

        $this->requestFor($token, $company)
            ->postJson($url, $checkout)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('payment');

        $cashRegister = CashRegister::query()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'code' => 'CAR-01',
            'name' => 'Caisse Car Rental 1',
            'is_active' => true,
        ]);

        $rentalPayment = $this->submitCashPayment($token, $company, $reservation['id'], $cashRegister->id, 'rental', '130.00');
        $this->approvePayment($token, $company, $reservation['id'], $rentalPayment['id']);
        $depositPayment = $this->submitCashPayment($token, $company, $reservation['id'], $cashRegister->id, 'security_deposit', '250.00');
        $this->approvePayment($token, $company, $reservation['id'], $depositPayment['id']);

        $this->requestFor($token, $company)
            ->postJson($url, [...$checkout, 'driver_license_verified' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('driver_license_verified');

        // Un permis américain est délivré par un État : la subdivision est obligatoire.
        $this->requestFor($token, $company)
            ->postJson($url, [...$checkout, 'driver_license_country' => 'US'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('driver_license_subdivision');

        $this->requestFor($token, $company)
            ->postJson($url, [...$checkout, 'customer_signature_file_id' => null, 'terms_accepted' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_signature_file_id', 'terms_accepted']);

        // Une photo d’inspection ne peut pas servir de recto du permis.
        $this->requestFor($token, $company)
            ->postJson($url, [...$checkout, 'driver_license_front_file_id' => $this->upload($token, $company, $site, 'inspection_photo')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('driver_license_front_file_id');

        $this->requestFor($token, $company)
            ->postJson($url, [...$checkout, 'odometer_km' => 50])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('odometer_km');

        $checkedOut = $this->requestFor($token, $company)
            ->postJson($url, $checkout)
            ->assertOk()
            ->assertJsonPath('data.state', 'checked_out')
            ->assertJsonPath('data.driver_license_verified', true)
            ->assertJsonPath('data.driver_license.country', 'HT')
            ->assertJsonPath('data.driver_license.subdivision', null)
            ->assertJsonPath('data.driver_license.front_url', null)
            ->assertJsonPath('data.checkout_inspection.odometer_km', 150)
            ->assertJsonPath('data.checkout_inspection.fuel_level_percent', 100)
            ->assertJsonPath('data.checkout_inspection.accessories', ['spare_tire', 'jack'])
            ->assertJsonPath('data.checkout_inspection.company_signer_name', 'Agent Comptoir')
            ->assertJsonPath('data.contract.snapshot.terms', "Article 1 - Objet\nTexte de test.")
            ->assertJsonPath('data.contract.file_url', null)
            ->assertJsonMissingPath('data.driver_license_number')
            ->json('data');

        $this->assertNotNull($checkedOut['checkout_inspection']['customer_signature_url']);
        $this->assertSame(150, $vehicle->fresh()->latest_odometer_km);
        $this->assertDatabaseHas('car_rental_inspections', [
            'company_id' => $company->id,
            'reservation_id' => $reservation['id'],
            'stage' => 'pre_rental',
            'status' => 'finalized',
            'odometer_km' => 150,
        ]);
        $this->assertDatabaseHas('car_rental_security_deposits', [
            'company_id' => $company->id,
            'reservation_id' => $reservation['id'],
            'payment_id' => $depositPayment['id'],
            'status' => 'held',
            'currency' => 'USD',
            'amount' => '250.00',
        ]);

        // Une modification ultérieure des conditions ne change pas le contrat signé.
        $company->forceFill(['rental_contract_terms' => 'Nouvelle version'])->save();

        $contractFile = $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/files', [
                'purpose' => 'rental_contract',
                'site_id' => $site->id,
                'file' => UploadedFile::fake()->createWithContent('contrat.pdf', "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n"),
            ])
            ->assertCreated()
            ->json('data.id');

        $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation['id']}/contract", ['file_id' => $contractFile, 'send_to_customer' => true])
            ->assertOk()
            ->assertJsonPath('data.contract.file_url', '/api/v1/car-rental/files/' . $contractFile)
            ->assertJsonPath('data.contract.snapshot.terms', "Article 1 - Objet\nTexte de test.")
            ->assertJsonPath('customer_notification_sent', true);

        Mail::assertSent(CarRentalCustomerNotificationMail::class, static fn (CarRentalCustomerNotificationMail $mail): bool => count($mail->pdfAttachments) === 1);

        $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation['id']}/contract", ['file_id' => $contractFile])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reservation');
    }

    public function test_return_records_the_sheet_and_charges_then_deposit_settlement_and_invoice(): void
    {
        Mail::fake();
        Storage::fake('local');

        [, $company, $site, $token] = $this->context([
            'rental.reservations.create',
            'rental.reservations.read',
            'rental.reservations.manage',
            'rental.payments.submit',
            'rental.payments.approve',
            'rental.deposits.settle',
            'rental.invoices.issue',
        ]);
        $company->forceFill(['rental_contract_terms' => 'Article 1 - Objet'])->save();
        $vehicle = $this->vehicle($company, $site, 'SUV-RETURN', 'suv');
        $reservation = $this->checkedOutReservation($token, $company, $site, $vehicle);
        $url = "/api/v1/car-rental/reservations/{$reservation['id']}/return";
        $return = [
            'expected_lock_version' => $reservation['lock_version'],
            'odometer_km' => 500,
            'fuel_level_percent' => 75,
            'accessories' => ['spare_tire', 'jack'],
            'damage_notes' => 'Rayure sur la portière avant gauche.',
            'damage_marks' => [['x' => 0.2, 'y' => 0.3, 'kind' => 'scratch', 'note' => 'Portière']],
            'apply_cleaning_fee' => true,
            'apply_extra_km' => true,
        ];

        $this->requestFor($token, $company)
            ->postJson($url, [...$return, 'odometer_km' => 120])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('odometer_km');

        $this->requestFor($token, $company)
            ->postJson($url, [...$return, 'damage_marks' => [['x' => 2, 'y' => 0.3, 'kind' => 'scratch']]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('damage_marks.0.x');

        // Un agent sans droit de règlement ne peut pas ajouter de frais libres.
        $agentToken = $this->additionalUser($company, ['rental.reservations.manage', 'rental.reservations.read']);
        $this->requestFor($agentToken, $company)
            ->postJson($url, [...$return, 'other_charges' => [['label' => 'Rétroviseur', 'amount' => '80.00']]])
            ->assertForbidden();

        // 350 km parcourus, 300 inclus : 50 km à 0,50 USD.
        $this->requestFor($token, $company)
            ->postJson($url, $return)
            ->assertOk()
            ->assertJsonPath('data.state', 'completed')
            ->assertJsonPath('data.return_inspection.odometer_km', 500)
            ->assertJsonPath('data.return_inspection.fuel_level_percent', 75)
            ->assertJsonPath('data.return_inspection.damage_marks.0.kind', 'scratch')
            ->assertJsonPath('data.additional_charges.0.code', 'cleaning')
            ->assertJsonPath('data.additional_charges.0.amount', '20.00')
            ->assertJsonPath('data.additional_charges.1.code', 'extra_km')
            ->assertJsonPath('data.additional_charges.1.amount', '25.00');

        $this->assertSame(500, $vehicle->fresh()->latest_odometer_km);
        $this->assertSame('preparation', $vehicle->fresh()->operational_status);

        $invoiceUrl = "/api/v1/car-rental/reservations/{$reservation['id']}/invoice";
        $this->requestFor($token, $company)
            ->postJson($invoiceUrl)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reservation');

        $settlementUrl = "/api/v1/car-rental/reservations/{$reservation['id']}/deposit-settlement";
        $this->requestFor($token, $company)
            ->postJson($settlementUrl, ['retained_amount_usd' => 45])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
        $this->requestFor($token, $company)
            ->postJson($settlementUrl, ['retained_amount_usd' => 300, 'reason' => 'Frais'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('retained_amount_usd');
        $this->requestFor($token, $company)
            ->postJson($settlementUrl, ['retained_amount_usd' => 45, 'reason' => 'Nettoyage et kilométrage'])
            ->assertOk()
            ->assertJsonPath('data.security_deposits.0.status', 'partially_applied')
            ->assertJsonPath('data.security_deposits.0.applied_amount', '45.00');

        // Frais aéroport non choisis : 3 jours × 130 + nettoyage 20 + kilomètres 25 = 435 ;
        // payé 390 ; dépôt retenu 45 ; solde nul.
        $invoice = $this->requestFor($token, $company)
            ->postJson($invoiceUrl)
            ->assertCreated()
            ->assertJsonPath('data.invoice.number', '0000 0001')
            ->assertJsonPath('data.invoice.total', '435.00')
            ->assertJsonPath('data.invoice.balance_due', '0.00')
            ->assertJsonPath('data.invoice.snapshot.totals.paid', '390.00')
            ->assertJsonPath('data.invoice.snapshot.totals.deposit_applied', '45.00')
            ->assertJsonPath('data.invoice.snapshot.deposit.released_usd', '205.00')
            ->json('data.invoice');

        self::assertStringNotContainsString('SUV-RETURN', json_encode($invoice['snapshot'], JSON_THROW_ON_ERROR));

        $this->requestFor($token, $company)
            ->postJson($invoiceUrl)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reservation');

        $file = $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/files', [
                'purpose' => 'rental_invoice',
                'site_id' => $site->id,
                'file' => UploadedFile::fake()->createWithContent('facture.pdf', "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n"),
            ])
            ->assertCreated()
            ->json('data.id');

        $this->requestFor($token, $company)
            ->postJson("{$invoiceUrl}/file", ['file_id' => $file])
            ->assertOk()
            ->assertJsonPath('data.invoice.file_url', '/api/v1/car-rental/files/' . $file)
            ->assertJsonPath('customer_notification_sent', true);

        Mail::assertSent(
            CarRentalCustomerNotificationMail::class,
            static fn (CarRentalCustomerNotificationMail $mail): bool => count($mail->pdfAttachments) === 1
                && str_starts_with($mail->pdfAttachments[0]['name'], 'Facture-'),
        );
    }

    public function test_group_exchange_rate_is_manual_with_brh_alert_and_converts_payments_in_another_currency(): void
    {
        [$user, $company, $site, $token] = $this->context([
            'rental.reservations.create',
            'rental.reservations.read',
            'rental.payments.submit',
            'rental.payments.approve',
        ]);
        // Droit accordé par le propriétaire dans Configuration.
        $user->forceFill(['can_manage_exchange_rates' => true])->save();
        $agentToken = $this->additionalUser($company, ['rental.reservations.read']);

        $this->requestFor($agentToken, $company)
            ->postJson('/api/v1/exchange-rates', ['rate_htg_per_usd' => 130])
            ->assertForbidden();

        // Sous la référence BRH : confirmation et motif obligatoires.
        $this->requestFor($token, $company)
            ->postJson('/api/v1/exchange-rates', [
                'rate_htg_per_usd' => 128,
                'brh_reference_rate' => 131.25,
                'brh_reference_date' => '2026-10-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirm_below_brh');

        $this->requestFor($token, $company)
            ->postJson('/api/v1/exchange-rates', [
                'rate_htg_per_usd' => 128,
                'brh_reference_rate' => 131.25,
                'brh_reference_date' => '2026-10-01',
                'confirm_below_brh' => true,
                'note' => 'Taux négocié pour un client institutionnel.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.below_brh', true);

        $this->travel(1)->seconds();
        $this->requestFor($token, $company)
            ->postJson('/api/v1/exchange-rates', ['rate_htg_per_usd' => 130])
            ->assertCreated()
            ->assertJsonPath('data.below_brh', false);

        $this->withToken($agentToken)
            ->getJson('/api/v1/exchange-rates')
            ->assertOk()
            ->assertJsonPath('current.rate_htg_per_usd', '130.0000')
            ->assertJsonPath('can_manage', false)
            ->assertJsonCount(2, 'history');

        $this->requestFor($agentToken, $company)
            ->getJson('/api/v1/context')
            ->assertOk()
            ->assertJsonPath('exchange_rate.rate_htg_per_usd', '130.0000');

        $vehicle = $this->vehicle($company, $site, 'SUV-HTG', 'suv');
        $reservation = $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
            ]))
            ->assertCreated()
            ->json('data');
        $register = CashRegister::query()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'code' => 'CAR-HTG',
            'name' => 'Caisse Car Rental',
            'is_active' => true,
        ]);

        // 13 000 HTG au taux de 130 = 100 USD sur une réservation en USD.
        $payment = $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation['id']}/payments", [
                'payment_kind' => 'rental',
                'method' => 'cash',
                'currency' => 'HTG',
                'amount' => '13000.00',
                'cash_register_id' => $register->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.exchange_rate_htg_per_usd', '130.0000')
            ->assertJsonPath('data.amount_in_reservation_currency', '100.00')
            ->assertJsonPath('data.receipt_number', null)
            ->json('data');

        $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation['id']}/payments/{$payment['id']}/approve")
            ->assertOk()
            ->assertJsonPath('data.receipt_number', '0000 0001');
    }

    public function test_an_approved_payment_receives_a_numbered_receipt_with_a_signed_qr(): void
    {
        [, $company, $site, $token] = $this->context([
            'rental.reservations.create',
            'rental.reservations.read',
            'rental.payments.submit',
            'rental.payments.approve',
            'rental.payments.credit',
        ]);
        $vehicle = $this->vehicle($company, $site, 'SUV-RECU', 'suv');
        $reservation = $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
            ]))
            ->assertCreated()
            ->json('data');
        $register = CashRegister::query()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'code' => 'CAR-RECU',
            'name' => 'Caisse Car Rental',
            'is_active' => true,
        ]);

        $payment = $this->submitCashPayment($token, $company, $reservation['id'], $register->id, 'rental', '130.00');
        $this->approvePayment($token, $company, $reservation['id'], $payment['id']);

        $receipt = $this->requestFor($token, $company)
            ->getJson("/api/v1/car-rental/payments/{$payment['id']}/receipt")
            ->assertOk()
            ->assertJsonPath('data.number', '0000 0001')
            ->assertJsonPath('data.cash_register', 'Caisse Car Rental')
            ->assertJsonPath('data.amount', '130.00')
            ->json('data');

        self::assertStringNotContainsString('SUV-RECU', json_encode($receipt, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('/verification/recu/RENT/00000001?s=', (string) $receipt['verification_url']);

        $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/payments/{$payment['id']}/receipt/prints", ['copy' => 'client'])
            ->assertOk()
            ->assertJsonPath('data.print_count', 1);
        $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/payments/{$payment['id']}/receipt/prints", ['copy' => 'administration'])
            ->assertOk()
            ->assertJsonPath('data.print_count', 2);
        self::assertTrue(AuditEvent::query()->where('event_type', 'receipt.reprinted')->exists());

        // Vérification publique : sans session, sans donnée client.
        parse_str((string) parse_url((string) $receipt['verification_url'], PHP_URL_QUERY), $query);
        $verified = $this->getJson('/api/v1/public/receipts/RENT/00000001?s=' . $query['s'])
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('number', '0000 0001')
            ->assertJsonPath('amount', '130.00')
            ->json();
        self::assertArrayNotHasKey('customer', $verified);

        $this->getJson('/api/v1/public/receipts/RENT/00000001?s=0000000000')
            ->assertOk()
            ->assertJsonPath('valid', false);

        // Montant modifié après l'émission : l'ancienne signature est refusée.
        \App\Models\CarRentalPayment::query()->whereKey($payment['id'])->update(['amount' => '131.00']);
        $this->getJson('/api/v1/public/receipts/RENT/00000001?s=' . $query['s'])
            ->assertOk()
            ->assertJsonPath('valid', false);
        \App\Models\CarRentalPayment::query()->whereKey($payment['id'])->update(['amount' => '130.00']);

        // Un crédit accordé n'est pas un encaissement : pas de reçu.
        $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation['id']}/payments", [
                'payment_kind' => 'rental',
                'method' => 'credit',
                'currency' => 'USD',
                'amount' => '100.00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.receipt_number', null);
    }

    public function test_known_customers_are_found_by_name_email_or_phone_with_masked_contacts(): void
    {
        [, $company, $site, $token] = $this->context([
            'rental.reservations.create',
            'rental.reservations.read',
        ]);
        $vehicle = $this->vehicle($company, $site, 'SUV-CLIENT', 'suv');
        $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
            ]))
            ->assertCreated();

        $this->requestFor($token, $company)
            ->getJson('/api/v1/car-rental/customers?q=' . urlencode('jean pi'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.display_name', 'Jean Pierre')
            ->assertJsonPath('data.0.email_hint', 'je***@example.test')
            ->assertJsonPath('data.0.phone_hint', '••• 0000')
            ->assertJsonPath('data.0.reservation_count', 1)
            ->assertJsonMissingPath('data.0.email');

        $this->requestFor($token, $company)
            ->getJson('/api/v1/car-rental/customers?q=' . urlencode('JEAN.PIERRE@example.test'))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->requestFor($token, $company)
            ->getJson('/api/v1/car-rental/customers?q=' . urlencode('+509 3700 0000'))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $profileId = $this->requestFor($token, $company)
            ->getJson('/api/v1/car-rental/customers?q=jean')
            ->json('data.0.id');

        // La réservation réutilise la fiche du client connu.
        $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', [
                ...array_diff_key($this->reservationPayload([
                    'site_id' => $site->id,
                    'vehicle_id' => $vehicle->id,
                    'pickup_at' => '2026-11-10T10:00:00-04:00',
                    'due_at' => '2026-11-12T10:00:00-04:00',
                ]), ['customer' => true]),
                'customer_profile_id' => $profileId,
            ])
            ->assertCreated()
            ->assertJsonPath('data.customer.id', $profileId);
    }

    public function test_the_hand_over_email_waits_for_the_signed_contract_and_shows_roadside_assistance(): void
    {
        Mail::fake();
        Storage::fake('local');

        [, $company, $site, $token] = $this->context([
            'rental.reservations.create',
            'rental.reservations.read',
            'rental.reservations.manage',
            'rental.payments.submit',
            'rental.payments.approve',
        ]);
        $company->forceFill([
            'rental_contract_terms' => 'Article 1 - Objet',
            'roadside_assistance_phone' => '+509 0000-0001',
        ])->save();
        $vehicle = $this->vehicle($company, $site, 'SUV-MAIL', 'suv');
        $reservation = $this->checkedOutReservation($token, $company, $site, $vehicle, deferNotification: true);

        Mail::assertNotSent(
            CarRentalCustomerNotificationMail::class,
            static fn (CarRentalCustomerNotificationMail $mail): bool => str_contains($mail->subjectLine, 'en circulation'),
        );

        $file = $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/files', [
                'purpose' => 'rental_contract',
                'site_id' => $site->id,
                'file' => UploadedFile::fake()->createWithContent('contrat.pdf', "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF\n"),
            ])
            ->json('data.id');

        $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation['id']}/contract", ['file_id' => $file, 'send_to_customer' => true])
            ->assertOk()
            ->assertJsonPath('customer_notification_sent', true);

        Mail::assertSent(CarRentalCustomerNotificationMail::class, static function (CarRentalCustomerNotificationMail $mail): bool {
            $html = $mail->render();

            return str_contains($mail->subjectLine, 'en circulation')
                && count($mail->pdfAttachments) === 1
                && str_contains($mail->intro, 'contrat de location signé est joint')
                && str_contains($html, 'Assistance routière : +509 0000-0001')
                && ! str_contains($html, 'SUV-MAIL');
        });
    }

    /** @return array<string, mixed> */
    private function checkedOutReservation(string $token, Company $company, Site $site, CarRentalVehicle $vehicle, bool $deferNotification = false): array
    {
        $reservation = $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/reservations', $this->reservationPayload([
                'site_id' => $site->id,
                'vehicle_id' => $vehicle->id,
            ]))
            ->assertCreated()
            ->json('data');

        $cashRegister = CashRegister::query()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'code' => 'CAR-RET',
            'name' => 'Caisse Car Rental',
            'is_active' => true,
        ]);
        $rental = $this->submitCashPayment($token, $company, $reservation['id'], $cashRegister->id, 'rental', '390.00');
        $this->approvePayment($token, $company, $reservation['id'], $rental['id']);
        $deposit = $this->submitCashPayment($token, $company, $reservation['id'], $cashRegister->id, 'security_deposit', '250.00');
        $this->approvePayment($token, $company, $reservation['id'], $deposit['id']);

        return $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservation['id']}/check-out", [
                'expected_lock_version' => $reservation['lock_version'],
                'driver_full_name' => 'Jean Pierre',
                'driver_license_number' => 'HT-123456',
                'driver_license_expires_at' => '2028-01-01',
                'driver_license_country' => 'HT',
                'driver_license_front_file_id' => $this->upload($token, $company, $site, 'driver_license_front'),
                'driver_license_back_file_id' => $this->upload($token, $company, $site, 'driver_license_back'),
                'driver_license_verified' => true,
                'odometer_km' => 150,
                'fuel_level_percent' => 100,
                'accessories' => ['spare_tire', 'jack'],
                'damage_marks' => [['x' => 0.8, 'y' => 0.5, 'kind' => 'dent']],
                'terms_accepted' => true,
                'customer_signature_file_id' => $this->upload($token, $company, $site, 'signature'),
                'company_signature_file_id' => $this->upload($token, $company, $site, 'signature'),
                'company_signer_name' => 'Agent Comptoir',
                'defer_customer_notification' => $deferNotification,
            ])
            ->assertOk()
            ->assertJsonPath('data.checkout_inspection.damage_marks.0.kind', 'dent')
            ->json('data');
    }

    private function upload(string $token, Company $company, Site $site, string $purpose): string
    {
        return $this->requestFor($token, $company)
            ->postJson('/api/v1/car-rental/files', [
                'purpose' => $purpose,
                'site_id' => $site->id,
                'file' => UploadedFile::fake()->createWithContent('image.png', $this->pngBytes()),
            ])
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * @param array<int, string> $permissions
     * @return array{0: User, 1: Company, 2: Site, 3: string, 4: CompanyUserAccess}
     */
    private function context(array $permissions, string $siteScope = 'all'): array
    {
        $user = User::factory()->create(['is_active' => true]);
        $company = Company::query()->create([
            'code' => 'RENT',
            'legal_name' => 'Clientèle Rent a Car S.A.',
            'display_name' => 'Clientèle Rent a Car',
            'base_currency' => 'USD',
        ]);
        $site = Site::query()->create([
            'company_id' => $company->id,
            'code' => 'CAP-AERO',
            'name' => 'Bureau Cap-Haïtien',
            'address' => 'Cap-Haïtien',
        ]);
        $access = CompanyUserAccess::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role_key' => 'prepose',
            'site_scope' => $siteScope,
            'permissions' => $permissions,
            'is_active' => true,
        ]);
        [, $token] = ApiAccessToken::issueFor($user, Request::create('/api/v1/auth/login', 'POST'));

        return [$user, $company, $site, $token, $access];
    }

    private function vehicle(Company $company, Site $site, string $code, string $category): CarRentalVehicle
    {
        return CarRentalVehicle::query()->create([
            'company_id' => $company->id,
            'site_id' => $site->id,
            'code' => $code,
            'category' => $category,
            'operational_status' => 'available',
            'make' => 'Toyota',
            'model' => 'Test',
            'registration_number' => $code,
            'registration_status' => 'normal',
            'latest_odometer_km' => 100,
            'daily_rate_usd' => '130.00',
            'minimum_security_deposit_usd' => '250.00',
            'is_active' => true,
        ]);
    }

    /** @param array<int, string> $permissions */
    private function additionalUser(Company $company, array $permissions): string
    {
        $user = User::factory()->create(['is_active' => true]);
        CompanyUserAccess::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role_key' => 'prepose',
            'site_scope' => 'all',
            'permissions' => $permissions,
            'is_active' => true,
        ]);
        [, $token] = ApiAccessToken::issueFor($user, Request::create('/api/v1/auth/login', 'POST'));

        return $token;
    }

    private function pngBytes(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    }

    /** @return array<string, mixed> */
    private function submitCashPayment(
        string $token,
        Company $company,
        string $reservationId,
        string $cashRegisterId,
        string $kind,
        string $amount,
    ): array {
        return $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservationId}/payments", [
                'payment_kind' => $kind,
                'method' => 'cash',
                'currency' => 'USD',
                'amount' => $amount,
                'cash_register_id' => $cashRegisterId,
            ])
            ->assertCreated()
            ->json('data');
    }

    private function approvePayment(string $token, Company $company, string $reservationId, string $paymentId): void
    {
        $this->requestFor($token, $company)
            ->postJson("/api/v1/car-rental/reservations/{$reservationId}/payments/{$paymentId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    /** @param array<string, mixed> $overrides */
    private function reservationPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'customer' => [
                'customer_type' => 'individual',
                'display_name' => 'Jean Pierre',
                'email' => 'jean.pierre@example.test',
                'phone' => '+50937000000',
                'group_contact_sharing_consent' => false,
            ],
            'pickup_at' => '2026-11-02T10:00:00-04:00',
            'due_at' => '2026-11-05T10:00:00-04:00',
            'pickup_location_type' => 'site',
            'dropoff_location_type' => 'cap_haitien_airport',
            'currency' => 'USD',
            'daily_rate' => '130.00',
            'kilometer_plan' => 'limited',
            'included_km' => 300,
            'additional_km_rate' => '0.50',
        ], $overrides);
    }

    private function requestFor(string $token, Company $company): self
    {
        return $this->withToken($token)->withHeader('X-Clientele-Company-Id', $company->id);
    }
}
