# Laravel Cloudflare

[![Latest Version on Packagist](https://img.shields.io/packagist/v/phattarachai/laravel-cloudflare.svg?style=flat-square)](https://packagist.org/packages/phattarachai/laravel-cloudflare)
[![Tests](https://img.shields.io/github/actions/workflow/status/phattarachai/laravel-cloudflare/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/phattarachai/laravel-cloudflare/actions/workflows/run-tests.yml?query=branch%3Amain)
[![Code Style](https://img.shields.io/github/actions/workflow/status/phattarachai/laravel-cloudflare/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/phattarachai/laravel-cloudflare/actions/workflows/fix-php-code-style-issues.yml?query=branch%3Amain)
[![PHP Version](https://img.shields.io/packagist/dependency-v/phattarachai/laravel-cloudflare/php?style=flat-square&label=php&logo=php&logoColor=white)](https://packagist.org/packages/phattarachai/laravel-cloudflare)
![Laravel Version](https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20?style=flat-square&logo=laravel&logoColor=white)
[![Total Downloads](https://img.shields.io/packagist/dt/phattarachai/laravel-cloudflare.svg?style=flat-square)](https://packagist.org/packages/phattarachai/laravel-cloudflare)

Purge the Cloudflare edge cache, toggle Development Mode, register tunnel DNS records, and sync WAF
custom rules from artisan. Built for a fleet of apps sharing one Cloudflare zone behind a
cloudflared tunnel, where `purge_everything` would evict every other app and a WAF or DNS sync must
never touch what another app declared.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- A Cloudflare API token and zone id (any plan, Free included)

## Install

```bash
composer require phattarachai/laravel-cloudflare
php artisan vendor:publish --tag=cloudflare-config
```

Set the zone id (a public identifier, safe to commit) and, if you register DNS, the tunnel id:

```dotenv
CLOUDFLARE_ZONE_ID=...
CLOUDFLARE_TUNNEL_ID=...
```

The API token is resolved at runtime and falls back to the process env, so on a self-hosted runner
you inject `CLOUDFLARE_API_TOKEN` once (in the runner's `.env`) and no repo needs a secret. A
Zone → Cache Purge scope covers `cloudflare:purge`; add Zone → Zone Settings Edit to use
`cloudflare:dev-mode`, Zone → DNS Edit to use `cloudflare:dns`, and Zone → Zone WAF Edit to use
`cloudflare:waf`.

## Purge

```bash
php artisan cloudflare:purge                 # document root + every built asset from the Vite manifest
php artisan cloudflare:purge --url=https://…/sitemap.xml   # plus explicit URLs (repeatable)
php artisan cloudflare:purge --host          # every cached URL on this app's host
php artisan cloudflare:purge --everything --force          # whole zone — nukes every app on a shared zone
```

By default it purges by exact `files`: `https://<host>/` plus every built file (and its CSS) from
`public/build/manifest.json`. **That covers only `/` and the Vite assets** — any other cached HTML
page (`/blog/…`, `/about`) stays at the edge until it expires, unless you add it with `--url` or
use the host mode. Batches go out at 100 URLs per request, Cloudflare's cap on Free, Pro and
Business.

`--host` purges `{"hosts": ["<host>"]}` instead: every cached URL on this app's host and nothing
else on the shared zone. Cloudflare has offered purge by hostname, tag and prefix on every plan
since April 2025, but on Free it is limited to 5 requests a minute per account, shared by every app
on that account. To make it the default for an app, set `CLOUDFLARE_PURGE_MODE=host` (or
`purge.mode` in the config). The host comes from `CLOUDFLARE_HOST`, else the `APP_URL` host.

Make it the last deploy step:

```yaml
- run: php artisan cloudflare:purge || true
```

With no token or zone id it exits 0 without calling the API, so dev, tests, and a
not-yet-configured repo keep CI green. With no manifest it purges only the document root.

## Development Mode

```bash
php artisan cloudflare:dev-mode          # show current state
php artisan cloudflare:dev-mode on       # bypass the edge cache for ~3 hours
php artisan cloudflare:dev-mode off
```

## DNS

Upsert-only — it never deletes a record it did not declare, so it is safe against the shared zone.
A tunnel record (`--tunnel`, or `'tunnel' => true` in config) needs `CLOUDFLARE_TUNNEL_ID`; without
it the command fails before writing anything.

```bash
php artisan cloudflare:dns --name=new-app.phattarachai.app --tunnel   # proxied CNAME → <tunnel>.cfargotunnel.com
php artisan cloudflare:dns --name=txt.example.com --type=TXT --content='v=spf1 -all' --dns-only
php artisan cloudflare:dns --list
php artisan cloudflare:dns --name=old.example.com --delete --force
```

Declare an app's own records in `config/cloudflare.php` and a bare `php artisan cloudflare:dns`
upserts them — handy as a one-off when spinning up a new subdomain:

```php
'dns' => [
    'records' => [
        ['name' => 'new-app.phattarachai.app', 'tunnel' => true],
    ],
],
```

## WAF custom rules

Upsert-only, keyed by a `tag` embedded in each rule's description — it never touches a rule it did
not declare, so it is safe against the shared zone. It creates the zone's custom-rules entrypoint
only when Cloudflare answers 404 (the zone has none yet); if reading the rules fails for any other
reason — a 5xx, a timeout, a token without read access — it exits non-zero and writes nothing. The canonical use is a **Managed Challenge on
`/admin`**: bots and brute-force hit the edge challenge, real browsers pass near-invisibly, and the
API is left alone (a challenge needs a browser + `cf_clearance` cookie, so it would break XHR,
mobile, and webhooks). Needs a **Zone → Zone WAF Edit** token.

```bash
php artisan cloudflare:waf                       # sync every rule declared in config
php artisan cloudflare:waf --list                # show the zone's WAF custom rules
php artisan cloudflare:waf --remove=admin-challenge --force
```

Declare the rules in `config/cloudflare.php`. Each carries a unique `tag`, an `action` (default
`managed_challenge`), and EITHER declarative `paths` (the command builds the expression and, absent
`hosts`, scopes it to this app's own host) OR a raw `expression` for full control:

```php
'waf' => [
    'rules' => [
        // declarative — scoped to this app's host automatically
        ['tag' => 'admin-challenge', 'paths' => ['/admin']],

        // raw — pin every env host so all deploys upsert the SAME rule and converge
        // ['tag' => 'admin-challenge', 'expression' => '(starts_with(http.request.uri.path, "/admin")) and (http.host in {"backend-prod.example.com" "backend-qas.example.com"})'],
    ],
],
```

Verify: a challenged request returns `403` with a `cf-mitigated: challenge` header (curl always lands
here — it can't solve the JS challenge); a passed request has no `cf-mitigated` header and holds a
`cf_clearance` cookie (HttpOnly — visible in DevTools → Application → Cookies).

## Testing

```bash
composer test
```
