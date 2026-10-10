<?php

use Illuminate\Support\Facades\Http;

/**
 * Fake the Cloudflare Rulesets API: GET returns the custom-rules entrypoint (with the
 * given existing rule, or none — or a 404 when the zone has no custom ruleset yet),
 * and PUT/POST/PATCH/DELETE report success.
 */
function fakeWaf(?array $existingRule, bool $rulesetExists = true): void
{
    Http::fake(function ($request) use ($existingRule, $rulesetExists) {
        if ($request->method() === 'GET') {
            if (! $rulesetExists) {
                return Http::response(['success' => false, 'errors' => [['code' => 10003, 'message' => 'could not find entrypoint ruleset']], 'result' => null], 404);
            }

            return Http::response(['success' => true, 'result' => [
                'id' => 'ruleset-1',
                'rules' => $existingRule ? [$existingRule] : [],
            ]]);
        }

        return Http::response(['success' => true, 'result' => ['id' => 'ruleset-1', 'rules' => []]]);
    });
}

/**
 * Fake a GET on the entrypoint that fails for a reason other than "no ruleset", and
 * let any write through so a test can prove none was attempted.
 */
function fakeWafReadFailure(int $status, array $body): void
{
    Http::fake(fn ($request) => $request->method() === 'GET'
        ? Http::response($body, $status)
        : Http::response(['success' => true, 'result' => ['id' => 'ruleset-1', 'rules' => []]]));
}

function declareAdminChallenge(): void
{
    config()->set('cloudflare.waf.rules', [
        ['tag' => 'admin-challenge', 'paths' => ['/admin']],
    ]);
}

it('appends a declared rule scoped to the app host', function () {
    declareAdminChallenge();
    fakeWaf(existingRule: null);

    $this->artisan('cloudflare:waf')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/rulesets/ruleset-1/rules')
        && $request['action'] === 'managed_challenge'
        && str_contains($request['expression'], 'starts_with(http.request.uri.path, "/admin")')
        && str_contains($request['expression'], 'music.phattarachai.app')
        && str_contains($request['description'], '[admin-challenge]'));
});

it('uses a raw expression verbatim', function () {
    config()->set('cloudflare.waf.rules', [
        ['tag' => 'block-x', 'action' => 'block', 'expression' => '(http.request.uri.path eq "/x")'],
    ]);
    fakeWaf(existingRule: null);

    $this->artisan('cloudflare:waf')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request['action'] === 'block'
        && $request['expression'] === '(http.request.uri.path eq "/x")');
});

it('updates the existing rule carrying the tag', function () {
    declareAdminChallenge();
    fakeWaf(existingRule: ['id' => 'rule-9', 'description' => 'Old [admin-challenge]']);

    $this->artisan('cloudflare:waf')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/rulesets/ruleset-1/rules/rule-9'));
});

it('creates the entrypoint ruleset only when the zone really has none (404)', function () {
    declareAdminChallenge();
    fakeWaf(existingRule: null, rulesetExists: false);

    $this->artisan('cloudflare:waf')->assertSuccessful();

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/rulesets/phases/http_request_firewall_custom/entrypoint')
        && $request['rules'][0]['action'] === 'managed_challenge');
});

it('never replaces the entrypoint when reading the ruleset fails', function (int $status, array $body) {
    declareAdminChallenge();
    fakeWafReadFailure($status, $body);

    $this->artisan('cloudflare:waf')
        ->expectsOutputToContain('Nothing was changed')
        ->assertFailed();

    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
})->with([
    'server error' => [500, ['success' => false, 'errors' => [['code' => 10000, 'message' => 'Internal error']]]],
    'token without read scope' => [403, ['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]]],
    'non-json 502 from a proxy' => [502, []],
    'success without a ruleset id' => [200, ['success' => true, 'result' => null]],
]);

it('never replaces the entrypoint when the ruleset read cannot connect', function () {
    declareAdminChallenge();
    Http::fake(fn ($request) => $request->method() === 'GET'
        ? Http::failedConnection()
        : Http::response(['success' => true]));

    $this->artisan('cloudflare:waf')->assertFailed();

    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

it('no-ops when no rules are declared', function () {
    Http::fake();

    $this->artisan('cloudflare:waf')->assertSuccessful();

    Http::assertNothingSent();
});

it('deletes the rule carrying the tag with --remove', function () {
    fakeWaf(existingRule: ['id' => 'rule-3', 'description' => 'x [admin-challenge]']);

    $this->artisan('cloudflare:waf', ['--remove' => 'admin-challenge', '--force' => true])
        ->assertSuccessful();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/rulesets/ruleset-1/rules/rule-3'));
});

it('fails --remove without writing when reading the ruleset fails', function () {
    fakeWafReadFailure(500, ['success' => false, 'errors' => [['message' => 'Internal error']]]);

    $this->artisan('cloudflare:waf', ['--remove' => 'admin-challenge', '--force' => true])->assertFailed();

    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

it('treats --remove on a zone without a ruleset as nothing to remove', function () {
    fakeWaf(existingRule: null, rulesetExists: false);

    $this->artisan('cloudflare:waf', ['--remove' => 'admin-challenge', '--force' => true])->assertSuccessful();

    Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
});

it('fails --list instead of reporting no rules when the read fails', function () {
    fakeWafReadFailure(403, ['success' => false, 'errors' => [['message' => 'Authentication error']]]);

    $this->artisan('cloudflare:waf', ['--list' => true])->assertFailed();
});

it('no-ops when unconfigured', function () {
    Http::fake();
    config()->set('cloudflare.token', null);

    $this->artisan('cloudflare:waf', ['--list' => true])->assertSuccessful();

    Http::assertNothingSent();
});
