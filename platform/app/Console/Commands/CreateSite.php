<?php

namespace App\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Command;

class CreateSite extends Command
{
    protected $signature = 'gsai:site:create {name} {domain} {--plan=free}';

    protected $description = 'Registra un nuovo sito e stampa le credenziali (il segreto viene mostrato una sola volta in questo output)';

    public function handle(): int
    {
        $site = Site::register($this->argument('name'), $this->argument('domain'), $this->option('plan'));

        $this->info("Sito creato (id {$site->id}).");
        $this->line('Site key:    '.$site->site_key);
        $this->line('Site secret: '.$site->site_secret);

        return self::SUCCESS;
    }
}
