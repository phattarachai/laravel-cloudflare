<?php

use Illuminate\Support\Facades\Http;

it('turns development mode on', function () {
    Http::fake(['*/settings/development_mode' => Http::response(['success' => true, 'result' => ['value' => 'on']])]);

    $this->artisan('cloudflare:dev-mode', ['state' => 'on'])->assertSuccessful();

    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/zones/zone-123/settings/development_mode')
        && $request['value'] === 'on');
});

it('turns development mode off', function () {
    Http::fake(['*/settings/development_mode' => Http::response(['success' => true, 'result' => ['value' => 'off']])]);

    $this->artisan('cloudflare:dev-mode', ['state' => 'off'])->assertSuccessful();

    Http::assertSent(fn ($request) => $request['value'] === 'off');
});

it('reads the setting for status', function () {
    Http::fake(['*/settings/development_mode' => Http::response(['success' => true, 'result' => ['value' => 'on', 'time_remaining' => 3600]])]);

    $this->artisan('cloudflare:dev-mode')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->method() === 'GET');
});

it('rejects an unknown state', function () {
    Http::fake();

    $this->artisan('cloudflare:dev-mode', ['state' => 'maybe'])->assertExitCode(2);

    Http::assertNothingSent();
});

it('no-ops when unconfigured', function () {
    Http::fake();
    config()->set('cloudflare.zone_id', null);

    $this->artisan('cloudflare:dev-mode', ['state' => 'on'])->assertSuccessful();

    Http::assertNothingSent();
});
