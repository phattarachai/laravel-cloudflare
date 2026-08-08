<?php

namespace Phattarachai\Cloudflare\Tests;

use Illuminate\Support\Facades\Http;

class DevModeCommandTest extends TestCase
{
    public function test_it_turns_development_mode_on(): void
    {
        Http::fake(['*/settings/development_mode' => Http::response(['success' => true, 'result' => ['value' => 'on']])]);

        $this->artisan('cloudflare:dev-mode', ['state' => 'on'])->assertSuccessful();

        Http::assertSent(function ($request) {
            return $request->method() === 'PATCH'
                && str_ends_with($request->url(), '/zones/zone-123/settings/development_mode')
                && $request['value'] === 'on';
        });
    }

    public function test_it_turns_development_mode_off(): void
    {
        Http::fake(['*/settings/development_mode' => Http::response(['success' => true, 'result' => ['value' => 'off']])]);

        $this->artisan('cloudflare:dev-mode', ['state' => 'off'])->assertSuccessful();

        Http::assertSent(fn ($request) => $request['value'] === 'off');
    }

    public function test_status_reads_the_setting(): void
    {
        Http::fake(['*/settings/development_mode' => Http::response(['success' => true, 'result' => ['value' => 'on', 'time_remaining' => 3600]])]);

        $this->artisan('cloudflare:dev-mode')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'GET');
    }

    public function test_it_rejects_an_unknown_state(): void
    {
        Http::fake();

        $this->artisan('cloudflare:dev-mode', ['state' => 'maybe'])->assertExitCode(2);

        Http::assertNothingSent();
    }

    public function test_it_no_ops_when_unconfigured(): void
    {
        Http::fake();
        config()->set('cloudflare.zone_id', null);

        $this->artisan('cloudflare:dev-mode', ['state' => 'on'])->assertSuccessful();

        Http::assertNothingSent();
    }
}
