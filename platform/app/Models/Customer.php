<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use BelongsToSite;

    protected $fillable = [
        'site_id', 'wc_user_id', 'email_hash', 'consent_tracking', 'consent_cross_site',
        'global_identity_id', 'merged_into_id', 'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'consent_tracking' => 'boolean',
            'consent_cross_site' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function visitors(): HasMany
    {
        return $this->hasMany(Visitor::class);
    }

    public function identityLinks(): HasMany
    {
        return $this->hasMany(IdentityLink::class);
    }

    public function globalIdentity(): BelongsTo
    {
        return $this->belongsTo(GlobalIdentity::class);
    }
}
