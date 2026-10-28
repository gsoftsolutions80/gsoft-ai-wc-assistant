<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Site extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = ['name', 'domain', 'site_key', 'site_secret', 'status', 'plan', 'settings'];

    protected $hidden = ['site_secret'];

    protected function casts(): array
    {
        return [
            'site_secret' => 'encrypted',
            'settings' => 'array',
        ];
    }

    /** Crea un sito con credenziali nuove; il segreto in chiaro e' leggibile solo qui. */
    public static function register(string $name, string $domain, string $plan = 'free'): self
    {
        return static::create([
            'name' => $name,
            'domain' => $domain,
            'site_key' => 'gsk_'.Str::random(32),
            'site_secret' => Str::random(64),
            'status' => self::STATUS_ACTIVE,
            'plan' => $plan,
        ]);
    }
}
