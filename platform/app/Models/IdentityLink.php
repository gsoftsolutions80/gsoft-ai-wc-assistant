<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdentityLink extends Model
{
    use BelongsToSite;

    public const SOURCE_LOGIN = 'login';

    public const SOURCE_CHECKOUT = 'checkout';

    public const SOURCE_NEWSLETTER = 'newsletter';

    public const SOURCE_COOKIE = 'cookie';

    protected $fillable = ['site_id', 'visitor_id', 'customer_id', 'source', 'confidence'];

    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
