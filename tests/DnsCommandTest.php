<?php

namespace Phattarachai\Cloudflare\Tests;

use Illuminate\Support\Facades\Http;

class DnsCommandTest extends TestCase
{
    public function test_tunnel_shortcut_creates_a_proxied_cname_to_the_tunnel(): void
    {
        $this->fakeDns(existing: null);

        $this->artisan('cloudflare:dns', ['--name' => 'new-app.phattarachai.app', '--tunnel' => true])
            ->assertSuccessful();

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/zones/zone-123/dns_records')
                && $request['type'] === 'CNAME'
                && $request['name'] === 'new-app.phattarachai.app'
                && $request['content'] === 'tunnel-abc.cfargotunnel.com'
                && $request['proxied'] === true;
        });
    }

    public function test_it_updates_an_existing_record_instead_of_duplicating(): void
    {
        $this->fakeDns(existing: ['id' => 'rec-1', 'type' => 'CNAME', 'name' => 'new-app.phattarachai.app']);

        $this->artisan('cloudflare:dns', ['--name' => 'new-app.phattarachai.app', '--tunnel' => true])
            ->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/zones/zone-123/dns_records/rec-1'));
    }

    public function test_dns_only_creates_an_unproxied_record(): void
    {
        $this->fakeDns(existing: null);

        $this->artisan('cloudflare:dns', [
            '--name' => 'txt.phattarachai.app',
            '--type' => 'TXT',
            '--content' => 'v=spf1 -all',
        ])->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['proxied'] === false);
    }

    public function test_it_syncs_declared_records(): void
    {
        config()->set('cloudflare.dns.records', [
            ['name' => 'declared.phattarachai.app', 'tunnel' => true],
        ]);
        $this->fakeDns(existing: null);

        $this->artisan('cloudflare:dns')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request['name'] === 'declared.phattarachai.app'
            && $request['content'] === 'tunnel-abc.cfargotunnel.com');
    }

    public function test_it_no_ops_when_no_records_declared(): void
    {
        Http::fake();

        $this->artisan('cloudflare:dns')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_delete_removes_the_matching_record(): void
    {
        $this->fakeDns(existing: ['id' => 'rec-9', 'type' => 'CNAME', 'name' => 'old.phattarachai.app']);

        $this->artisan('cloudflare:dns', ['--name' => 'old.phattarachai.app', '--delete' => true, '--force' => true])
            ->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/zones/zone-123/dns_records/rec-9'));
    }

    public function test_it_no_ops_when_unconfigured(): void
    {
        Http::fake();
        config()->set('cloudflare.token', null);

        $this->artisan('cloudflare:dns', ['--list' => true])->assertSuccessful();

        Http::assertNothingSent();
    }

    /**
     * Fake the Cloudflare DNS API: GET returns the given existing record (or none),
     * and POST/PUT/DELETE report success.
     */
    private function fakeDns(?array $existing): void
    {
        Http::fake(function ($request) use ($existing) {
            if ($request->method() === 'GET') {
                return Http::response(['success' => true, 'result' => $existing ? [$existing] : []]);
            }

            return Http::response(['success' => true, 'result' => ['id' => 'rec-1']]);
        });
    }
}
