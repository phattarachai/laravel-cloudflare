<?php

namespace Phattarachai\Cloudflare\Commands;

use Illuminate\Console\Command;
use Phattarachai\Cloudflare\CloudflareClient;
use Phattarachai\Cloudflare\Support\ManifestAssets;

class PurgeCommand extends Command
{
    protected $signature = 'cloudflare:purge
        {--everything : Purge the whole zone — nukes every app on a shared zone (requires --force)}
        {--force : Skip the confirmation for --everything}
        {--url=* : Extra explicit URLs to purge (repeatable)}';

    protected $description = "Purge this app's built assets and document root from the Cloudflare edge cache";

    public function handle(CloudflareClient $client): int
    {
        if (! $client->configured()) {
            $this->components->warn('Cloudflare is not configured (no token or zone id) — skipping purge.');

            return self::SUCCESS;
        }

        return $this->option('everything')
            ? $this->purgeEverything($client)
            : $this->purgeFiles($client);
    }

    private function purgeEverything(CloudflareClient $client): int
    {
        if (! $this->option('force') && ! $this->confirm('Purge the ENTIRE zone? This evicts every app sharing it.')) {
            $this->components->warn('Aborted. Pass --force to purge everything non-interactively.');

            return self::FAILURE;
        }

        return $this->report($client->purgeEverything()->json('success') === true, 'entire zone');
    }

    private function purgeFiles(CloudflareClient $client): int
    {
        $files = array_values(array_unique([
            ...ManifestAssets::urlsFor($client->host()),
            ...$this->option('url'),
        ]));

        $batchSize = (int) config('cloudflare.purge.batch_size', 30);
        $batches = array_chunk($files, max(1, $batchSize));

        if (count($batches) > 1) {
            $this->components->info(count($files).' URLs exceed the '.$batchSize.'-per-request cap — sending '.count($batches).' batches.');
        }

        foreach ($batches as $i => $batch) {
            $this->line('Purging '.count($batch).' URL(s) on '.$client->host().'...');

            if ($client->purgeFiles($batch)->json('success') !== true) {
                return $this->report(false, 'batch '.($i + 1));
            }
        }

        return $this->report(true, count($files).' URL(s)');
    }

    private function report(bool $success, string $what): int
    {
        if ($success) {
            $this->components->info('Purged '.$what.'.');

            return self::SUCCESS;
        }

        $this->components->error('Cloudflare purge failed for '.$what.'.');

        return self::FAILURE;
    }
}
