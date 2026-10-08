<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CompanyUserSiteAccess extends Model
{
    use HasUuids;

    protected $table = 'company_user_site_access';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'company_user_access_id',
        'company_id',
        'site_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function companyAccess(): BelongsTo
    {
        return $this->belongsTo(CompanyUserAccess::class, 'company_user_access_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
