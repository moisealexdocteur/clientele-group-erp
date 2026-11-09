<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Commande de motocyclette payée d'avance, de la vérification du dépôt à la remise. */
final class MotoOrder extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'customer_profile_id',
        'company_id',
        'site_id',
        'number',
        'status',
        'model_id',
        'preferred_color',
        'customer_name',
        'customer_phone',
        'customer_email',
        'customer_address',
        'price',
        'price_currency',
        'payment_amount',
        'payment_currency',
        'exchange_rate_htg_per_usd',
        'amount_in_price_currency',
        'bank_name',
        'deposit_reference',
        'deposit_date',
        'deposit_proof_file_id',
        'submitted_by',
        'submitted_at',
        'verified_by',
        'verified_at',
        'unit_id',
        'assigned_by',
        'assigned_at',
        'assignment_note',
        'called_by',
        'called_at',
        'id_document_type',
        'id_document_number',
        'checklist',
        'accessories',
        'customs_document_delivered',
        'customs_document_delivered_by',
        'customs_document_delivered_at',
        'receipt_number',
        'receipt_print_count',
        'certificate_print_count',
        'delivered_by',
        'delivered_at',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'payment_amount' => 'decimal:2',
            'exchange_rate_htg_per_usd' => 'decimal:4',
            'amount_in_price_currency' => 'decimal:2',
            'deposit_date' => 'immutable_date',
            'submitted_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'assigned_at' => 'immutable_datetime',
            'called_at' => 'immutable_datetime',
            'checklist' => 'array',
            'accessories' => 'array',
            'customs_document_delivered' => 'boolean',
            'customs_document_delivered_at' => 'immutable_datetime',
            'receipt_print_count' => 'integer',
            'certificate_print_count' => 'integer',
            'delivered_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public const STATUSES = ['submitted', 'paid', 'assigned', 'delivered', 'cancelled'];

    public function motoModel(): BelongsTo
    {
        return $this->belongsTo(MotoModel::class, 'model_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(MotoUnit::class, 'unit_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function deliverer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }
}
