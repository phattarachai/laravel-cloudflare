<?php

namespace Phattarachai\Cloudflare\Support;

class ManifestAssets
{
    /**
     * Build the exact edge URLs for a host from a Vite manifest.
     *
     * Returns the document root plus every built entry file and its css[], all under
     * https://<host>/build/. Purging by these exact files (never `hosts`/`prefixes`,
     * which are Enterprise-only, nor `purge_everything`, which nukes the shared zone)
     * is the whole point. Also evicts any transient 404 cached for a fresh hash.
     *
     * @return array<int, string>
     */
    public static function urlsFor(string $host): array
    {
        $urls = ['https://'.$host.'/'];

        foreach (self::read() as $entry) {
            if (! empty($entry['file'])) {
                $urls[] = 'https://'.$host.'/build/'.$entry['file'];
            }

            foreach ($entry['css'] ?? [] as $css) {
                $urls[] = 'https://'.$host.'/build/'.$css;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function read(): array
    {
        foreach (config('cloudflare.purge.manifests', []) as $manifest) {
            $path = is_file($manifest) ? $manifest : public_path($manifest);

            if (is_file($path)) {
                return json_decode((string) file_get_contents($path), true) ?: [];
            }
        }

        return [];
    }
}
