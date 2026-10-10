<?php

namespace Phattarachai\Cloudflare\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Phattarachai\Cloudflare\CloudflareClient;

class WafCommand extends Command
{
    protected $signature = 'cloudflare:waf
        {--list : List the zone'."'".'s WAF custom rules}
        {--remove= : Delete the declared rule carrying this tag}
        {--force : Skip the confirmation for --remove}';

    protected $description = 'Sync this app'."'".'s declared WAF custom rules (upsert-by-tag; never prunes the shared zone)';

    public function handle(CloudflareClient $client): int
    {
        if (! $client->configured()) {
            $this->components->warn('Cloudflare is not configured (no token or zone id) — skipping.');

            return self::SUCCESS;
        }

        if ($this->option('list')) {
            return $this->list($client);
        }

        if ($this->option('remove')) {
            return $this->remove($client, (string) $this->option('remove'));
        }

        return $this->syncDeclared($client);
    }

    private function list(CloudflareClient $client): int
    {
        $ruleset = $this->ruleset($client);

        if ($ruleset === null) {
            return self::FAILURE;
        }

        $rules = $ruleset['rules'];

        if ($rules === []) {
            $this->components->warn('No WAF custom rules on this zone.');

            return self::SUCCESS;
        }

        $rows = array_map(fn (array $r) => [
            $r['action'] ?? '',
            $r['description'] ?? '',
            $r['expression'] ?? '',
        ], $rules);

        $this->table(['Action', 'Description', 'Expression'], $rows);

        return self::SUCCESS;
    }

    private function syncDeclared(CloudflareClient $client): int
    {
        $rules = config('cloudflare.waf.rules', []);

        if ($rules === []) {
            $this->components->warn('No WAF rules declared in config — nothing to do.');

            return self::SUCCESS;
        }

        foreach ($rules as $declared) {
            if ($this->upsert($client, $declared) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function upsert(CloudflareClient $client, array $declared): int
    {
        $tag = $declared['tag'] ?? null;

        if (! $tag) {
            $this->components->error('Every declared WAF rule needs a unique "tag".');

            return self::FAILURE;
        }

        $expression = $this->expressionFor($declared, $client);

        if ($expression === null) {
            $this->components->error('WAF rule "'.$tag.'" needs an "expression" or "paths".');

            return self::FAILURE;
        }

        $rule = [
            'action' => $declared['action'] ?? 'managed_challenge',
            'expression' => $expression,
            'description' => ($declared['description'] ?? 'Managed by laravel-cloudflare').' ['.$tag.']',
            'enabled' => true,
        ];

        $ruleset = $this->ruleset($client);

        if ($ruleset === null) {
            return self::FAILURE;
        }

        $rulesetId = $ruleset['id'];

        if ($rulesetId === null) {
            return $this->report($client->createFirewallRuleset([$rule])->json('success') === true, 'Created', $tag);
        }

        $existingId = $this->findByTag($ruleset['rules'], $tag);

        $response = $existingId
            ? $client->updateFirewallRule($rulesetId, $existingId, $rule)
            : $client->addFirewallRule($rulesetId, $rule);

        return $this->report($response->json('success') === true, $existingId ? 'Updated' : 'Created', $tag);
    }

    private function remove(CloudflareClient $client, string $tag): int
    {
        $ruleset = $this->ruleset($client);

        if ($ruleset === null) {
            return self::FAILURE;
        }

        $rulesetId = $ruleset['id'];
        $ruleId = $rulesetId === null ? null : $this->findByTag($ruleset['rules'], $tag);

        if ($rulesetId === null || $ruleId === null) {
            $this->components->warn('No WAF rule tagged "'.$tag.'" — nothing to remove.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Remove WAF rule "'.$tag.'"?')) {
            $this->components->warn('Aborted.');

            return self::FAILURE;
        }

        if ($client->deleteFirewallRule($rulesetId, $ruleId)->json('success') !== true) {
            $this->components->error('Failed to remove WAF rule "'.$tag.'".');

            return self::FAILURE;
        }

        $this->components->info('Removed WAF rule "'.$tag.'".');

        return self::SUCCESS;
    }

    /**
     * Read the zone's custom-rules entrypoint. Only a real 404 from the API means the
     * zone has no ruleset yet (id null); any other failure — 5xx, a timeout, a token
     * without read scope — returns null after reporting it, so the caller writes
     * nothing. Treating an unknown ruleset as empty would PUT a fresh entrypoint and
     * wipe every other custom rule on the shared zone.
     *
     * @return array{id: ?string, rules: array<int, array<string, mixed>>}|null
     */
    private function ruleset(CloudflareClient $client): ?array
    {
        try {
            $response = $client->firewallRuleset();
        } catch (ConnectionException $e) {
            $this->components->error('Could not reach the Cloudflare API to read the WAF custom rules: '.$e->getMessage());

            return null;
        }

        if ($response->status() === 404 && $response->json('success') === false) {
            return ['id' => null, 'rules' => []];
        }

        $id = $response->json('result.id');

        if ($response->json('success') !== true || ! is_string($id) || $id === '') {
            $this->components->error('Could not read the WAF custom rules (HTTP '.$response->status().'): '.$this->errorMessage($response).'. Nothing was changed.');

            return null;
        }

        return ['id' => $id, 'rules' => $response->json('result.rules') ?? []];
    }

    private function errorMessage(Response $response): string
    {
        $messages = array_column((array) $response->json('errors', []), 'message');

        return $messages === [] ? 'no error detail' : implode('; ', $messages);
    }

    /**
     * The rule id whose description carries the `[tag]` marker, or null.
     *
     * @param  array<int, array<string, mixed>>  $rules
     */
    private function findByTag(array $rules, string $tag): ?string
    {
        foreach ($rules as $rule) {
            if (str_contains((string) ($rule['description'] ?? ''), '['.$tag.']')) {
                return $rule['id'] ?? null;
            }
        }

        return null;
    }

    /**
     * A raw `expression` wins; otherwise build one from `paths` (+ `hosts`, defaulting
     * to this app's own host so a rule on a shared zone stays scoped to it). Null when
     * neither is declared.
     */
    private function expressionFor(array $declared, CloudflareClient $client): ?string
    {
        if (! empty($declared['expression'])) {
            return $declared['expression'];
        }

        $paths = $declared['paths'] ?? [];

        if ($paths === []) {
            return null;
        }

        $pathClause = '('.implode(' or ', array_map(
            fn (string $path) => 'starts_with(http.request.uri.path, "'.$path.'")',
            $paths,
        )).')';

        $hosts = $declared['hosts'] ?? array_filter([$client->host()]);

        if ($hosts === []) {
            return $pathClause;
        }

        $hostSet = implode(' ', array_map(fn (string $host) => '"'.$host.'"', $hosts));

        return $pathClause.' and (http.host in {'.$hostSet.'})';
    }

    private function report(bool $success, string $verb, string $tag): int
    {
        if ($success) {
            $this->components->info($verb.' WAF rule "'.$tag.'".');

            return self::SUCCESS;
        }

        $this->components->error('Failed to upsert WAF rule "'.$tag.'".');

        return self::FAILURE;
    }
}
