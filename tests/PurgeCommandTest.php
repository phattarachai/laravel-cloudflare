<?php

namespace Phattarachai\Cloudflare\Tests;

use Illuminate\Support\Facades\Http;

class PurgeCommandTest extends TestCase
{
    private string $manifest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manifest = tempnam(sys_get_temp_dir(), 'manifest').'.json';
        file_put_contents($this->manifest, json_encode([
            'resources/js/app.js' => ['file' => 'assets/app-abc123.js', 'css' => ['assets/app-def456.css']],
        ]));

        config()->set('cloudflare.purge.manifests', [$this->manifest]);
    }

    protected function tearDown(): void
    {
        @unlink($this->manifest);

        parent::tearDown();
    }

    public function test_it_purges_the_manifest_files_and_document_root(): void
    {
        Http::fake([
            '*/purge_cache' => Http::response(['success' => true, 'result' => ['id' => 'x']]),
        ]);

        $this->artisan('cloudflare:purge')->assertSuccessful();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.cloudflare.com/client/v4/zones/zone-123/purge_cache'
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Bearer test-token')
                && $request['files'] === [
                    'https://music.phattarachai.app/',
                    'https://music.phattarachai.app/build/assets/app-abc123.js',
                    'https://music.phattarachai.app/build/assets/app-def456.css',
                ];
        });
    }

    public function test_it_appends_explicit_urls(): void
    {
        Http::fake(['*/purge_cache' => Http::response(['success' => true])]);

        $this->artisan('cloudflare:purge', ['--url' => ['https://music.phattarachai.app/sitemap.xml']])
            ->assertSuccessful();

        Http::assertSent(fn ($request) => in_array('https://music.phattarachai.app/sitemap.xml', $request['files'], true));
    }

    public function test_it_no_ops_and_sends_nothing_when_unconfigured(): void
    {
        Http::fake();
        config()->set('cloudflare.token', null);

        $this->artisan('cloudflare:purge')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_it_fails_when_the_api_reports_no_success(): void
    {
        Http::fake(['*/purge_cache' => Http::response(['success' => false, 'errors' => [['message' => 'nope']]])]);

        $this->artisan('cloudflare:purge')->assertFailed();
    }

    public function test_everything_requires_force_and_sends_purge_everything(): void
    {
        Http::fake(['*/purge_cache' => Http::response(['success' => true])]);

        $this->artisan('cloudflare:purge', ['--everything' => true, '--force' => true])->assertSuccessful();

        Http::assertSent(fn ($request) => $request['purge_everything'] === true);
    }

    public function test_everything_without_force_aborts_non_interactively(): void
    {
        Http::fake();

        $this->artisan('cloudflare:purge', ['--everything' => true])
            ->expectsConfirmation('Purge the ENTIRE zone? This evicts every app sharing it.', 'no')
            ->assertFailed();

        Http::assertNothingSent();
    }
}
