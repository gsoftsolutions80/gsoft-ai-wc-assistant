<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Identita' cross-sito: esiste solo per clienti con consent_cross_site. Non e' soggetta a tenant scope. */
class GlobalIdentity extends Model
{
    protected $fillable = ['email_hash'];

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class)->withoutGlobalScope('site');
    }
}
