<?php

namespace Phattarachai\Cloudflare;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class CloudflareClient
{
    private const BASE_URL = 'https://api.cloudflare.com/client/v4';

    /**
     * The phase whose entrypoint ruleset holds a zone's WAF custom rules.
     */
    private const FIREWALL_PHASE = 'http_request_firewall_custom';

    /**
     * Resolve the API token at runtime, falling back to the process env.
     *
     * `php artisan optimize` bakes config before the deploy purge step, and the CI
     * token is injected into the process env by the runner's .env — so a cached
     * config() read is null and getenv() is what actually finds the token.
     */
    public function token(): ?string
    {
        return config('cloudflare.token') ?: (getenv('CLOUDFLARE_API_TOKEN') ?: null);
    }

    public function zoneId(): ?string
    {
        return config('cloudflare.zone_id') ?: (getenv('CLOUDFLARE_ZONE_ID') ?: null);
    }

    /**
     * The host these commands operate on — config override, else the APP_URL host.
     */
    public function host(): ?string
    {
        return config('cloudflare.host') ?: parse_url((string) config('app.url'), PHP_URL_HOST);
    }

    /**
     * True when there is enough to talk to the API. Commands no-op cleanly otherwise.
     */
    public function configured(): bool
    {
        return filled($this->token()) && filled($this->zoneId());
    }

    /**
     * Purge specific URLs from the edge cache.
     *
     * @param  array<int, string>  $files
     */
    public function purgeFiles(array $files): Response
    {
        return $this->request()->post($this->zoneUrl('purge_cache'), ['files' => array_values($files)]);
    }

    /**
     * Purge the entire zone. Nukes every other app on a shared zone — guard the call site.
     */
    public function purgeEverything(): Response
    {
        return $this->request()->post($this->zoneUrl('purge_cache'), ['purge_everything' => true]);
    }

    /**
     * Read the Development Mode setting (bypasses the edge cache for 3 hours when on).
     */
    public function developmentMode(): Response
    {
        return $this->request()->get($this->zoneUrl('settings/development_mode'));
    }

    public function setDevelopmentMode(bool $on): Response
    {
        return $this->request()->patch($this->zoneUrl('settings/development_mode'), [
            'value' => $on ? 'on' : 'off',
        ]);
    }

    /**
     * List DNS records, optionally filtered by name and/or type.
     */
    public function listDnsRecords(array $query = []): Response
    {
        return $this->request()->get($this->zoneUrl('dns_records'), $query);
    }

    public function createDnsRecord(array $record): Response
    {
        return $this->request()->post($this->zoneUrl('dns_records'), $record);
    }

    public function updateDnsRecord(string $id, array $record): Response
    {
        return $this->request()->put($this->zoneUrl('dns_records/'.$id), $record);
    }

    public function deleteDnsRecord(string $id): Response
    {
        return $this->request()->delete($this->zoneUrl('dns_records/'.$id));
    }

    /**
     * Read the entrypoint ruleset for the WAF custom-rules phase (holds the zone's
     * custom rules). 404s with success=false when the zone has no custom ruleset yet.
     */
    public function firewallRuleset(): Response
    {
        return $this->request()->get($this->zoneUrl('rulesets/phases/'.self::FIREWALL_PHASE.'/entrypoint'));
    }

    /**
     * Create the custom-rules entrypoint ruleset with the given rules. Used only when
     * the zone has no custom ruleset yet; afterwards rules are added/patched individually.
     *
     * @param  array<int, array<string, mixed>>  $rules
     */
    public function createFirewallRuleset(array $rules): Response
    {
        return $this->request()->put($this->zoneUrl('rulesets/phases/'.self::FIREWALL_PHASE.'/entrypoint'), [
            'rules' => array_values($rules),
        ]);
    }

    /**
     * Append one rule to an existing custom ruleset (never touches the other rules).
     *
     * @param  array<string, mixed>  $rule
     */
    public function addFirewallRule(string $rulesetId, array $rule): Response
    {
        return $this->request()->post($this->zoneUrl('rulesets/'.$rulesetId.'/rules'), $rule);
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    public function updateFirewallRule(string $rulesetId, string $ruleId, array $rule): Response
    {
        return $this->request()->patch($this->zoneUrl('rulesets/'.$rulesetId.'/rules/'.$ruleId), $rule);
    }

    public function deleteFirewallRule(string $rulesetId, string $ruleId): Response
    {
        return $this->request()->delete($this->zoneUrl('rulesets/'.$rulesetId.'/rules/'.$ruleId));
    }

    private function request(): PendingRequest
    {
        return Http::withToken((string) $this->token())
            ->acceptJson()
            ->asJson();
    }

    private function zoneUrl(string $path): string
    {
        return self::BASE_URL.'/zones/'.$this->zoneId().'/'.$path;
    }
}
