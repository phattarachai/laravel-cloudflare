<?php

return [
    /*
     * API token scoped to Zone → Cache Purge (+ DNS Edit if you use cloudflare:dns).
     *
     * Resolved at runtime by CloudflareClient with a getenv() fallback, because
     * `php artisan optimize` bakes this config before the deploy purge step runs and
     * the CI token is injected into the process env by the runner's .env — a plain
     * cached config() read comes back null. Leave this as env() and let the client's
     * getenv() fallback do the work; do not read it directly.
     */
    'token' => env('CLOUDFLARE_API_TOKEN'),

    /*
     * Zone id — a public identifier, not a secret. Safe to commit.
     */
    'zone_id' => env('CLOUDFLARE_ZONE_ID'),

    /*
     * Host these commands operate on. Defaults to the APP_URL host.
     */
    'host' => env('CLOUDFLARE_HOST'),

    'purge' => [
        /*
         * Vite manifest locations, tried in order. Each entry's built file plus its
         * css[] become the exact URLs purged, alongside the document root.
         */
        'manifests' => [
            'build/manifest.json',
            'build/.vite/manifest.json',
        ],

        /*
         * Non-Enterprise plans cap a `files` purge at 30 URLs per request. Larger
         * builds are batched and each batch is logged so nothing is silently dropped.
         */
        'batch_size' => 30,
    ],

    'waf' => [
        /*
         * WAF custom rules this app owns, applied to the zone's
         * http_request_firewall_custom phase. `cloudflare:waf` (no --name) upserts each
         * one by its `tag` (a marker embedded in the rule description) and never touches
         * rules it did not declare, so it is safe against the shared zone. Empty = no-op.
         *
         * `cloudflare:waf` needs a token scoped Zone → Zone WAF → Edit (broader than the
         * Cache Purge scope purge/dev-mode use).
         *
         * Each rule carries a unique `tag`, an `action` (default 'managed_challenge'),
         * and EITHER a raw `expression` OR declarative `paths` (+ optional `hosts`):
         *
         *   // declarative — the command builds the expression and, when no `hosts` are
         *   // given, scopes it to this app's own host (cloudflare.host / APP_URL):
         *   ['tag' => 'admin-challenge', 'paths' => ['/admin']],
         *
         *   // raw — full control; pin every env host so each deploy upserts the SAME rule
         *   // and they converge instead of fighting over one tag on a shared zone:
         *   ['tag' => 'admin-challenge', 'expression' => '(starts_with(http.request.uri.path, "/admin")) and (http.host in {"a.example.com" "b.example.com"})'],
         */
        'rules' => [
            //
        ],
    ],

    'dns' => [
        /*
         * cloudflared tunnel UUID. A --tunnel record (or a declared record with
         * 'tunnel' => true) becomes a proxied CNAME to <tunnel_id>.cfargotunnel.com.
         * Public identifier, not a secret.
         */
        'tunnel_id' => env('CLOUDFLARE_TUNNEL_ID'),

        /*
         * Records this app owns. `cloudflare:dns` (no --name) upserts each one; it
         * never deletes records it did not declare, so it is safe to run against the
         * shared zone. Empty = the command no-ops.
         *
         * Each record is one of:
         *   ['name' => 'app.phattarachai.app', 'tunnel' => true]
         *   ['type' => 'CNAME', 'name' => '…', 'content' => '…', 'proxied' => true]
         */
        'records' => [
            //
        ],
    ],
];
