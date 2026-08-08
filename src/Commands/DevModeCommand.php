<?php

namespace Phattarachai\Cloudflare\Commands;

use Illuminate\Console\Command;
use Phattarachai\Cloudflare\CloudflareClient;

class DevModeCommand extends Command
{
    protected $signature = 'cloudflare:dev-mode {state? : on, off, or status (default)}';

    protected $description = 'Toggle Cloudflare Development Mode, which bypasses the edge cache for 3 hours';

    public function handle(CloudflareClient $client): int
    {
        if (! $client->configured()) {
            $this->components->warn('Cloudflare is not configured (no token or zone id) — skipping.');

            return self::SUCCESS;
        }

        $state = strtolower((string) ($this->argument('state') ?? 'status'));

        return match ($state) {
            'on' => $this->set($client, true),
            'off' => $this->set($client, false),
            'status' => $this->status($client),
            default => $this->invalidState($state),
        };
    }

    private function set(CloudflareClient $client, bool $on): int
    {
        $response = $client->setDevelopmentMode($on);

        if ($response->json('success') !== true) {
            $this->components->error('Failed to turn Development Mode '.($on ? 'on' : 'off').'.');

            return self::FAILURE;
        }

        $this->components->info('Development Mode is '.($on ? 'on (edge cache bypassed for ~3h)' : 'off').'.');

        return self::SUCCESS;
    }

    private function status(CloudflareClient $client): int
    {
        $response = $client->developmentMode();

        if ($response->json('success') !== true) {
            $this->components->error('Could not read Development Mode.');

            return self::FAILURE;
        }

        $value = $response->json('result.value');
        $remaining = (int) $response->json('result.time_remaining');

        $this->components->info('Development Mode is '.$value.($value === 'on' && $remaining > 0 ? ' ('.$remaining.'s remaining)' : '').'.');

        return self::SUCCESS;
    }

    private function invalidState(string $state): int
    {
        $this->components->error('Unknown state "'.$state.'". Use on, off, or status.');

        return self::INVALID;
    }
}
