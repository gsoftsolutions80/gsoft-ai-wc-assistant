<?php

namespace App\Models\Concerns;

use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToSite
{
    protected static function bootBelongsToSite(): void
    {
        static::addGlobalScope('site', function (Builder $query) {
            $current = app(CurrentSite::class);

            if ($current->has()) {
                $query->where($query->getModel()->getTable().'.site_id', $current->id());
            }
        });

        static::creating(function ($model) {
            $current = app(CurrentSite::class);

            if ($model->site_id === null && $current->has()) {
                $model->site_id = $current->id();
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
