<?php

namespace Phattarachai\Cloudflare\Commands;

use Illuminate\Console\Command;
use Phattarachai\Cloudflare\CloudflareClient;
use Phattarachai\Cloudflare\Support\ManifestAssets;

class PurgeCommand extends Command
{
    protected $signature = 'cloudflare:purge
        {--host : Purge every cached URL on the app host instead of only / and the Vite assets}
        {--everything : Purge the whole zone — nukes every app on a shared zone (requires --force)}
        {--force : Skip the confirmation for --everything}
        {--url=* : Extra explicit URLs to purge (repeatable)}';

    protected $description = "Purge this app's document root and built assets (or its whole host) from the Cloudflare edge cache";

    public function handle(CloudflareClient $client): int
    {
        if (! $client->configured()) {
            $this->components->warn('Cloudflare is not configured (no token or zone id) — skipping purge.');

            return self::SUCCESS;
        }

        if ($this->option('everything')) {
            return $this->purgeEverything($client);
        }

        $host = $client->host();

        if (blank($host)) {
            $this->components->error('No host to purge — set APP_URL or CLOUDFLARE_HOST.');

            return self::FAILURE;
        }

        return $this->option('host') || config('cloudflare.purge.mode') === 'host'
            ? $this->purgeHost($client, $host)
            : $this->purgeFiles($client, [...ManifestAssets::urlsFor($host), ...$this->option('url')]);
    }

    private function purgeEverything(CloudflareClient $client): int
    {
        if (! $this->option('force') && ! $this->confirm('Purge the ENTIRE zone? This evicts every app sharing it.')) {
            $this->components->warn('Aborted. Pass --force to purge everything non-interactively.');

            return self::FAILURE;
        }

        return $this->report($client->purgeEverything()->json('success') === true, 'entire zone');
    }

    /**
     * Purge every cached URL on this app's host — every page, not only / and the Vite
     * assets — plus any explicit --url (which may live on another host).
     */
    private function purgeHost(CloudflareClient $client, string $host): int
    {
        $this->line('Purging every cached URL on '.$host.'...');

        if ($client->purgeHosts([$host])->json('success') !== true) {
            return $this->report(false, 'host '.$host);
        }

        $urls = $this->option('url');

        return $urls === []
            ? $this->report(true, 'host '.$host)
            : $this->purgeFiles($client, $urls);
    }

    /**
     * @param  array<int, string>  $urls
     */
    private function purgeFiles(CloudflareClient $client, array $urls): int
    {
        $files = array_values(array_unique($urls));

        $batchSize = (int) config('cloudflare.purge.batch_size', 100);
        $batches = array_chunk($files, max(1, $batchSize));

        if (count($batches) > 1) {
            $this->components->info(count($files).' URLs exceed the '.$batchSize.'-per-request cap — sending '.count($batches).' batches.');
        }

        foreach ($batches as $i => $batch) {
            $this->line('Purging '.count($batch).' URL(s)...');

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
