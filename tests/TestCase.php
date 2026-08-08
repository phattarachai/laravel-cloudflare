<?php

namespace Phattarachai\Cloudflare\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Phattarachai\Cloudflare\CloudflareServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [CloudflareServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.url', 'https://music.phattarachai.app');
        $app['config']->set('cloudflare.token', 'test-token');
        $app['config']->set('cloudflare.zone_id', 'zone-123');
        $app['config']->set('cloudflare.dns.tunnel_id', 'tunnel-abc');
    }
}
