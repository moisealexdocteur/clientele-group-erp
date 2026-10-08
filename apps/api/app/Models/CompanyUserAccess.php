<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CompanyUserAccess extends Model
{
    use HasUuids;

    protected $table = 'company_user_access';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'user_id',
        'role_key',
        'site_scope',
        'permissions',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function siteGrants(): HasMany
    {
        return $this->hasMany(CompanyUserSiteAccess::class);
    }

    public function allows(string $permission): bool
    {
        $permissions = $this->permissions ?? [];
        $granted = array_is_list($permissions)
            ? $permissions
            : ($permissions['allow'] ?? []);

        return in_array('*', $granted, true)
            || in_array($permission, $granted, true);
    }

    public function hasSelectedSiteScope(): bool
    {
        return $this->site_scope === 'selected';
    }
}
