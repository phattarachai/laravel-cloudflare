<?php

namespace Phattarachai\Cloudflare\Commands;

use Illuminate\Console\Command;
use Phattarachai\Cloudflare\CloudflareClient;

class DnsCommand extends Command
{
    protected $signature = 'cloudflare:dns
        {--list : List the zone'."'".'s DNS records}
        {--name= : Record name for an ad-hoc upsert or --delete}
        {--type=CNAME : Record type}
        {--content= : Record content (the target)}
        {--tunnel : Shortcut for a proxied CNAME to <tunnel_id>.cfargotunnel.com}
        {--dns-only : Create the record unproxied (grey cloud)}
        {--ttl=1 : TTL in seconds; 1 means automatic}
        {--delete : Delete the record matching --name and --type instead of upserting}
        {--force : Skip the confirmation for --delete}';

    protected $description = 'Register or manage this app'."'".'s Cloudflare DNS records (upsert-only; never prunes the shared zone)';

    public function handle(CloudflareClient $client): int
    {
        if (! $client->configured()) {
            $this->components->warn('Cloudflare is not configured (no token or zone id) — skipping.');

            return self::SUCCESS;
        }

        if ($this->option('list')) {
            return $this->list($client);
        }

        if ($this->option('name')) {
            return $this->option('delete')
                ? $this->delete($client)
                : $this->upsert($client, $this->recordFromOptions());
        }

        return $this->syncDeclared($client);
    }

    private function list(CloudflareClient $client): int
    {
        $response = $client->listDnsRecords();

        if ($response->json('success') !== true) {
            $this->components->error('Could not list DNS records.');

            return self::FAILURE;
        }

        $rows = array_map(fn (array $r) => [
            $r['type'],
            $r['name'],
            $r['content'],
            ($r['proxied'] ?? false) ? 'proxied' : 'dns-only',
        ], $response->json('result', []));

        $this->table(['Type', 'Name', 'Content', 'Proxy'], $rows);

        return self::SUCCESS;
    }

    private function syncDeclared(CloudflareClient $client): int
    {
        $records = config('cloudflare.dns.records', []);

        if ($records === []) {
            $this->components->warn('No DNS records declared in config — nothing to do.');

            return self::SUCCESS;
        }

        foreach ($records as $declared) {
            if ($this->upsert($client, $this->normalize($declared)) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function upsert(CloudflareClient $client, array $record): int
    {
        $existing = $client->listDnsRecords(['name' => $record['name'], 'type' => $record['type']])
            ->json('result.0');

        $response = $existing
            ? $client->updateDnsRecord($existing['id'], $record)
            : $client->createDnsRecord($record);

        if ($response->json('success') !== true) {
            $this->components->error('Failed to upsert '.$record['type'].' '.$record['name'].'.');

            return self::FAILURE;
        }

        $this->components->info(($existing ? 'Updated ' : 'Created ').$record['type'].' '.$record['name'].' → '.$record['content'].'.');

        return self::SUCCESS;
    }

    private function delete(CloudflareClient $client): int
    {
        $type = (string) $this->option('type');
        $name = (string) $this->option('name');

        $existing = $client->listDnsRecords(['name' => $name, 'type' => $type])->json('result.0');

        if (! $existing) {
            $this->components->warn('No '.$type.' record named '.$name.' — nothing to delete.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Delete '.$type.' '.$name.'?')) {
            $this->components->warn('Aborted.');

            return self::FAILURE;
        }

        if ($client->deleteDnsRecord($existing['id'])->json('success') !== true) {
            $this->components->error('Failed to delete '.$type.' '.$name.'.');

            return self::FAILURE;
        }

        $this->components->info('Deleted '.$type.' '.$name.'.');

        return self::SUCCESS;
    }

    private function recordFromOptions(): array
    {
        return $this->normalize([
            'type' => (string) $this->option('type'),
            'name' => (string) $this->option('name'),
            'content' => $this->option('content'),
            'tunnel' => (bool) $this->option('tunnel'),
            'proxied' => $this->option('dns-only') ? false : null,
            'ttl' => (int) $this->option('ttl'),
        ]);
    }

    /**
     * Resolve a declared or flag-built record into a Cloudflare API payload.
     */
    private function normalize(array $record): array
    {
        $type = $record['type'] ?? 'CNAME';

        if ($record['tunnel'] ?? false) {
            $type = 'CNAME';
            $record['content'] = config('cloudflare.dns.tunnel_id').'.cfargotunnel.com';
        }

        $proxyable = in_array($type, ['A', 'AAAA', 'CNAME'], true);
        $proxied = $record['proxied'] ?? null;

        return [
            'type' => $type,
            'name' => $record['name'],
            'content' => $record['content'],
            'proxied' => $proxyable ? ($proxied ?? true) : false,
            'ttl' => (int) ($record['ttl'] ?? 1),
        ];
    }
}
