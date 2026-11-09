<?php

namespace App\Http\Controllers\AutoParts\Motos;

use App\Http\Controllers\CarRental\Concerns\ResolvesCompanyAccess;
use App\Http\Controllers\Controller;
use App\Models\MotoOrder;
use App\Models\StoredFile;
use App\Rules\DecimalAmount;
use App\Support\AuditLogger;
use App\Support\AutoParts\MotoPresenter;
use App\Support\AutoParts\PartsLookup;
use App\Support\CompanySiteAuthorizer;
use App\Support\DocumentNumberService;
use App\Support\ExchangeRateService;
use App\Support\FileVault;
use App\Support\Money;
use App\Support\Text;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Commande d'une motocyclette payée intégralement d'avance par dépôt
 * bancaire (Sogebank), en HTG ou en USD. Un paiement dans l'autre devise
 * est converti au taux du groupe, conservé avec la commande. La commande
 * attend la vérification du dépôt avant d'entrer dans la liste d'attente.
 */
final class StoreMotoOrder extends Controller
{
    use ResolvesCompanyAccess;

    public function __construct(
        private readonly PartsLookup $lookup,
        private readonly MotoPresenter $presenter,
        private readonly CompanySiteAuthorizer $siteAuthorizer,
        private readonly DocumentNumberService $numbers,
        private readonly ExchangeRateService $exchangeRates,
        private readonly FileVault $files,
        private readonly AuditLogger $audit,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $access = $this->access($request);
        $actor = $request->user();

        $data = $request->validate([
            'site_id' => ['required', 'uuid'],
            'model_id' => ['required', 'uuid'],
            'preferred_color' => ['nullable', 'string', 'max:60'],
            'customer_name' => ['required', 'string', 'max:160'],
            'customer_phone' => ['required', 'string', 'max:40', 'regex:/\A\+?[0-9 ()-]{7,40}\z/'],
            'customer_email' => ['nullable', 'email:rfc', 'max:254'],
            'customer_address' => ['nullable', 'string', 'max:255'],
            'payment_currency' => ['required', Rule::in(['HTG', 'USD'])],
            'payment_amount' => ['required', new DecimalAmount()],
            'bank_name' => ['nullable', 'string', 'max:60'],
            'deposit_reference' => ['required', 'string', 'max:80'],
            'deposit_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'deposit_proof_file_id' => ['nullable', 'uuid'],
        ], [
            'site_id.required' => 'Choisissez l’adresse de retrait.',
            'model_id.required' => 'Choisissez le modèle.',
            'customer_name.required' => 'Saisissez le nom complet du client.',
            'customer_phone.required' => 'Saisissez le téléphone du client : il sera appelé à l’arrivée de la moto.',
            'customer_phone.regex' => 'Saisissez un numéro de téléphone valide, par exemple +509 3700 0000.',
            'customer_email.email' => 'Saisissez une adresse courriel valide.',
            'payment_amount.required' => 'Saisissez le montant déposé.',
            'deposit_reference.required' => 'Saisissez le numéro de la fiche de dépôt.',
            'deposit_date.required' => 'Saisissez la date du dépôt.',
            'deposit_date.before_or_equal' => 'La date du dépôt ne peut pas être dans le futur.',
        ]);

        $site = $this->siteAuthorizer->siteFor($company, $access, $data['site_id']);
        $model = $this->lookup->motoModelFor($company, $data['model_id']);

        if (! $model->is_active) {
            throw ValidationException::withMessages(['model_id' => 'Ce modèle n’est plus en vente.']);
        }

        $amount = Money::normalize((string) $data['payment_amount']);
        $rate = $data['payment_currency'] !== $model->price_currency ? $this->exchangeRates->requireCurrent('payment_currency') : null;
        $converted = $rate === null
            ? $amount
            : $this->exchangeRates->convert($amount, $data['payment_currency'], $model->price_currency, (string) $rate->rate_htg_per_usd);

        // Paiement intégral d'avance : le dépôt doit couvrir le prix du modèle.
        if (Money::toCents($converted) < Money::toCents((string) $model->price)) {
            throw ValidationException::withMessages([
                'payment_amount' => sprintf(
                    'Le dépôt couvre %s %s ; le prix du modèle est de %s %s. Le paiement doit être intégral.',
                    Money::formatFr($converted),
                    $model->price_currency,
                    Money::formatFr((string) $model->price),
                    $model->price_currency,
                ),
            ]);
        }

        $proof = ($data['deposit_proof_file_id'] ?? null) === null
            ? null
            : $this->files->find($company, $data['deposit_proof_file_id'], StoredFile::PURPOSE_MOTO_DEPOSIT_PROOF, 'deposit_proof_file_id');

        $order = DB::transaction(function () use ($company, $site, $model, $data, $amount, $rate, $converted, $proof, $actor): MotoOrder {
            $order = MotoOrder::query()->create([
                'company_id' => $company->id,
                'site_id' => $site->id,
                'number' => $this->numbers->next($company->id, 'moto_order'),
                'status' => 'submitted',
                'model_id' => $model->id,
                'preferred_color' => Text::nullableTrimmed($data['preferred_color'] ?? null),
                'customer_name' => trim($data['customer_name']),
                'customer_phone' => trim($data['customer_phone']),
                'customer_email' => Text::nullableTrimmed($data['customer_email'] ?? null),
                'customer_address' => Text::nullableTrimmed($data['customer_address'] ?? null),
                'price' => (string) $model->price,
                'price_currency' => $model->price_currency,
                'payment_amount' => $amount,
                'payment_currency' => $data['payment_currency'],
                'exchange_rate_htg_per_usd' => $rate?->rate_htg_per_usd,
                'amount_in_price_currency' => $converted,
                'bank_name' => Text::nullableTrimmed($data['bank_name'] ?? null) ?? 'Sogebank',
                'deposit_reference' => trim($data['deposit_reference']),
                'deposit_date' => $data['deposit_date'],
                'deposit_proof_file_id' => $proof?->id,
                'submitted_by' => $actor?->id,
                'submitted_at' => now()->utc(),
            ]);

            $this->audit->record(
                eventType: 'parts.moto_order_submitted',
                companyId: $company->id,
                actorId: $actor?->id,
                actorType: $actor === null ? 'SYSTEM' : 'USER',
                subjectType: MotoOrder::class,
                subjectId: $order->id,
                metadata: [
                    'number' => $this->numbers->display($order->number),
                    'model' => $model->label(),
                    'payment_amount' => $amount,
                    'payment_currency' => $data['payment_currency'],
                    'deposit_reference' => $order->deposit_reference,
                ],
            );

            return $order;
        });

        return response()->json(['data' => $this->presenter->orderPayload($order, $company, $access, [], true)], 201);
    }
}
