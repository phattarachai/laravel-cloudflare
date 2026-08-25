<?php

namespace Phattarachai\Cloudflare\Tests;

use Illuminate\Support\Facades\Http;

class WafCommandTest extends TestCase
{
    public function test_it_appends_a_declared_rule_scoped_to_the_app_host(): void
    {
        config()->set('cloudflare.waf.rules', [
            ['tag' => 'admin-challenge', 'paths' => ['/admin']],
        ]);
        $this->fakeWaf(existingRule: null);

        $this->artisan('cloudflare:waf')->assertSuccessful();

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_ends_with($request->url(), '/rulesets/ruleset-1/rules')
                && $request['action'] === 'managed_challenge'
                && str_contains($request['expression'], 'starts_with(http.request.uri.path, "/admin")')
                && str_contains($request['expression'], 'music.phattarachai.app')
                && str_contains($request['description'], '[admin-challenge]');
        });
    }

    public function test_a_raw_expression_is_used_verbatim(): void
    {
        config()->set('cloudflare.waf.rules', [
            ['tag' => 'block-x', 'action' => 'block', 'expression' => '(http.request.uri.path eq "/x")'],
        ]);
        $this->fakeWaf(existingRule: null);

        $this->artisan('cloudflare:waf')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request['action'] === 'block'
            && $request['expression'] === '(http.request.uri.path eq "/x")');
    }

    public function test_it_updates_the_existing_rule_carrying_the_tag(): void
    {
        config()->set('cloudflare.waf.rules', [
            ['tag' => 'admin-challenge', 'paths' => ['/admin']],
        ]);
        $this->fakeWaf(existingRule: ['id' => 'rule-9', 'description' => 'Old [admin-challenge]']);

        $this->artisan('cloudflare:waf')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_ends_with($request->url(), '/rulesets/ruleset-1/rules/rule-9'));
    }

    public function test_it_creates_the_entrypoint_ruleset_when_the_zone_has_none(): void
    {
        config()->set('cloudflare.waf.rules', [
            ['tag' => 'admin-challenge', 'paths' => ['/admin']],
        ]);
        $this->fakeWaf(existingRule: null, rulesetExists: false);

        $this->artisan('cloudflare:waf')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/rulesets/phases/http_request_firewall_custom/entrypoint')
            && $request['rules'][0]['action'] === 'managed_challenge');
    }

    public function test_it_no_ops_when_no_rules_declared(): void
    {
        Http::fake();

        $this->artisan('cloudflare:waf')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_remove_deletes_the_rule_carrying_the_tag(): void
    {
        $this->fakeWaf(existingRule: ['id' => 'rule-3', 'description' => 'x [admin-challenge]']);

        $this->artisan('cloudflare:waf', ['--remove' => 'admin-challenge', '--force' => true])
            ->assertSuccessful();

        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/rulesets/ruleset-1/rules/rule-3'));
    }

    public function test_it_no_ops_when_unconfigured(): void
    {
        Http::fake();
        config()->set('cloudflare.token', null);

        $this->artisan('cloudflare:waf', ['--list' => true])->assertSuccessful();

        Http::assertNothingSent();
    }

    /**
     * Fake the Cloudflare Rulesets API: GET returns the custom-rules entrypoint (with the
     * given existing rule, or none — or a 404 when the zone has no custom ruleset yet),
     * and PUT/POST/PATCH/DELETE report success.
     */
    private function fakeWaf(?array $existingRule, bool $rulesetExists = true): void
    {
        Http::fake(function ($request) use ($existingRule, $rulesetExists) {
            if ($request->method() === 'GET') {
                if (! $rulesetExists) {
                    return Http::response(['success' => false, 'result' => null], 404);
                }

                return Http::response(['success' => true, 'result' => [
                    'id' => 'ruleset-1',
                    'rules' => $existingRule ? [$existingRule] : [],
                ]]);
            }

            return Http::response(['success' => true, 'result' => ['id' => 'ruleset-1', 'rules' => []]]);
        });
    }
}
