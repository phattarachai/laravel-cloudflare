<?php

namespace Phattarachai\Cloudflare;

use Phattarachai\Cloudflare\Commands\DevModeCommand;
use Phattarachai\Cloudflare\Commands\DnsCommand;
use Phattarachai\Cloudflare\Commands\PurgeCommand;
use Phattarachai\Cloudflare\Commands\WafCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class CloudflareServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('cloudflare')
            ->hasConfigFile()
            ->hasCommands([
                PurgeCommand::class,
                DevModeCommand::class,
                DnsCommand::class,
                WafCommand::class,
            ]);
    }
}
