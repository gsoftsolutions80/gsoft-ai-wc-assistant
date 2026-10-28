<?php

namespace App\Support;

use App\Models\Site;

/**
 * Tenant corrente della richiesta. Valorizzato dal middleware di firma;
 * fuori da una richiesta (job, comandi) resta vuoto e i modelli NON vengono filtrati.
 */
class CurrentSite
{
    private ?Site $site = null;

    public function set(?Site $site): void
    {
        $this->site = $site;
    }

    public function get(): ?Site
    {
        return $this->site;
    }

    public function has(): bool
    {
        return $this->site !== null;
    }

    public function id(): ?int
    {
        return $this->site?->getKey();
    }
}
