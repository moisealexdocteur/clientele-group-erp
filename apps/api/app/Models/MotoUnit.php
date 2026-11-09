<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Motocyclette reçue, identifiée par son numéro de châssis et son numéro de moteur. */
final class MotoUnit extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'batch_id',
        'site_id',
        'model_id',
        'model_year',
        'frame_number',
        'engine_number',
        'color',
        'cylinders',
        'displacement_cc',
        'customs_document_available',
        'status',
        'order_id',
    ];

    protected function casts(): array
    {
        return [
            'model_year' => 'integer',
            'cylinders' => 'integer',
            'displacement_cc' => 'integer',
            'customs_document_available' => 'boolean',
        ];
    }

    public const STATUSES = ['in_stock', 'reserved', 'delivered'];

    public function motoModel(): BelongsTo
    {
        return $this->belongsTo(MotoModel::class, 'model_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MotoBatch::class, 'batch_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
