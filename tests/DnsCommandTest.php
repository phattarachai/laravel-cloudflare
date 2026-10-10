<?php

use Illuminate\Support\Facades\Http;

/**
 * Fake the Cloudflare DNS API: GET returns the given existing record (or none),
 * and POST/PUT/DELETE report success.
 */
function fakeDns(?array $existing): void
{
    Http::fake(function ($request) use ($existing) {
        if ($request->method() === 'GET') {
            return Http::response(['success' => true, 'result' => $existing ? [$existing] : []]);
        }

        return Http::response(['success' => true, 'result' => ['id' => 'rec-1']]);
    });
}

it('creates a proxied CNAME to the tunnel with --tunnel', function () {
    fakeDns(existing: null);

    $this->artisan('cloudflare:dns', ['--name' => 'new-app.phattarachai.app', '--tunnel' => true])
        ->assertSuccessful();

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/zones/zone-123/dns_records')
        && $request['type'] === 'CNAME'
        && $request['name'] === 'new-app.phattarachai.app'
        && $request['content'] === 'tunnel-abc.cfargotunnel.com'
        && $request['proxied'] === true);
});

it('updates an existing record instead of duplicating it', function () {
    fakeDns(existing: ['id' => 'rec-1', 'type' => 'CNAME', 'name' => 'new-app.phattarachai.app']);

    $this->artisan('cloudflare:dns', ['--name' => 'new-app.phattarachai.app', '--tunnel' => true])
        ->assertSuccessful();

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/zones/zone-123/dns_records/rec-1'));
});

it('creates a non-proxyable record unproxied', function () {
    fakeDns(existing: null);

    $this->artisan('cloudflare:dns', [
        '--name' => 'txt.phattarachai.app',
        '--type' => 'TXT',
        '--content' => 'v=spf1 -all',
    ])->assertSuccessful();

    Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['proxied'] === false);
});

it('syncs declared records', function () {
    config()->set('cloudflare.dns.records', [
        ['name' => 'declared.phattarachai.app', 'tunnel' => true],
    ]);
    fakeDns(existing: null);

    $this->artisan('cloudflare:dns')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request['name'] === 'declared.phattarachai.app'
        && $request['content'] === 'tunnel-abc.cfargotunnel.com');
});

it('fails before any request when --tunnel has no tunnel id', function () {
    Http::fake();
    config()->set('cloudflare.dns.tunnel_id', null);

    $this->artisan('cloudflare:dns', ['--name' => 'new-app.phattarachai.app', '--tunnel' => true])
        ->expectsOutputToContain('CLOUDFLARE_TUNNEL_ID')
        ->assertFailed();

    Http::assertNothingSent();
});

it('fails before any request when a declared tunnel record has no tunnel id', function () {
    Http::fake();
    config()->set('cloudflare.dns.tunnel_id', null);
    config()->set('cloudflare.dns.records', [
        ['type' => 'TXT', 'name' => 'txt.phattarachai.app', 'content' => 'x'],
        ['name' => 'declared.phattarachai.app', 'tunnel' => true],
    ]);

    $this->artisan('cloudflare:dns')->assertFailed();

    Http::assertNothingSent();
});

it('no-ops when no records are declared', function () {
    Http::fake();

    $this->artisan('cloudflare:dns')->assertSuccessful();

    Http::assertNothingSent();
});

it('deletes the matching record with --delete', function () {
    fakeDns(existing: ['id' => 'rec-9', 'type' => 'CNAME', 'name' => 'old.phattarachai.app']);

    $this->artisan('cloudflare:dns', ['--name' => 'old.phattarachai.app', '--delete' => true, '--force' => true])
        ->assertSuccessful();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/zones/zone-123/dns_records/rec-9'));
});

it('no-ops when unconfigured', function () {
    Http::fake();
    config()->set('cloudflare.token', null);

    $this->artisan('cloudflare:dns', ['--list' => true])->assertSuccessful();

    Http::assertNothingSent();
});
