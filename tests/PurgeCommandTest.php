<?php

use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->manifest = tempnam(sys_get_temp_dir(), 'manifest').'.json';
    file_put_contents($this->manifest, json_encode([
        'resources/js/app.js' => ['file' => 'assets/app-abc123.js', 'css' => ['assets/app-def456.css']],
    ]));

    config()->set('cloudflare.purge.manifests', [$this->manifest]);
});

afterEach(function () {
    @unlink($this->manifest);
});

it('purges the manifest files and document root', function () {
    Http::fake(['*/purge_cache' => Http::response(['success' => true, 'result' => ['id' => 'x']])]);

    $this->artisan('cloudflare:purge')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone-123/purge_cache'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer test-token')
        && $request['files'] === [
            'https://music.phattarachai.app/',
            'https://music.phattarachai.app/build/assets/app-abc123.js',
            'https://music.phattarachai.app/build/assets/app-def456.css',
        ]);
});

it('appends explicit urls', function () {
    Http::fake(['*/purge_cache' => Http::response(['success' => true])]);

    $this->artisan('cloudflare:purge', ['--url' => ['https://music.phattarachai.app/sitemap.xml']])
        ->assertSuccessful();

    Http::assertSent(fn ($request) => in_array('https://music.phattarachai.app/sitemap.xml', $request['files'], true));
});

it('batches files at the 100-url cap', function () {
    Http::fake(['*/purge_cache' => Http::response(['success' => true])]);
    $urls = array_map(fn (int $i) => 'https://music.phattarachai.app/page-'.$i, range(1, 150));

    $this->artisan('cloudflare:purge', ['--url' => $urls])->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => count($request['files']) === 100);
    Http::assertSent(fn ($request) => count($request['files']) === 53);
});

it('purges the whole app host with --host', function () {
    Http::fake(['*/purge_cache' => Http::response(['success' => true])]);

    $this->artisan('cloudflare:purge', ['--host' => true])->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->data() === ['hosts' => ['music.phattarachai.app']]);
});

it('purges the whole app host when the configured mode is host', function () {
    Http::fake(['*/purge_cache' => Http::response(['success' => true])]);
    config()->set('cloudflare.purge.mode', 'host');

    $this->artisan('cloudflare:purge')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->data() === ['hosts' => ['music.phattarachai.app']]);
});

it('also purges explicit urls in host mode', function () {
    Http::fake(['*/purge_cache' => Http::response(['success' => true])]);

    $this->artisan('cloudflare:purge', ['--host' => true, '--url' => ['https://cdn.example.com/x.js']])
        ->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => $request->data() === ['files' => ['https://cdn.example.com/x.js']]);
});

it('fails when the host purge is rejected', function () {
    Http::fake(['*/purge_cache' => Http::response(['success' => false, 'errors' => [['message' => 'rate limited']]], 429)]);

    $this->artisan('cloudflare:purge', ['--host' => true])->assertFailed();
});

it('fails without a host to purge', function () {
    Http::fake();
    config()->set('app.url', null);

    $this->artisan('cloudflare:purge')->assertFailed();

    Http::assertNothingSent();
});

it('no-ops and sends nothing when unconfigured', function () {
    Http::fake();
    config()->set('cloudflare.token', null);

    $this->artisan('cloudflare:purge')->assertSuccessful();

    Http::assertNothingSent();
});

it('fails when the api reports no success', function () {
    Http::fake(['*/purge_cache' => Http::response(['success' => false, 'errors' => [['message' => 'nope']]])]);

    $this->artisan('cloudflare:purge')->assertFailed();
});

it('sends purge_everything with --everything --force', function () {
    Http::fake(['*/purge_cache' => Http::response(['success' => true])]);

    $this->artisan('cloudflare:purge', ['--everything' => true, '--force' => true])->assertSuccessful();

    Http::assertSent(fn ($request) => $request['purge_everything'] === true);
});

it('aborts --everything without --force when not confirmed', function () {
    Http::fake();

    $this->artisan('cloudflare:purge', ['--everything' => true])
        ->expectsConfirmation('Purge the ENTIRE zone? This evicts every app sharing it.', 'no')
        ->assertFailed();

    Http::assertNothingSent();
});
