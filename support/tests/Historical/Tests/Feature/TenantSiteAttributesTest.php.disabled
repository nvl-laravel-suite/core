<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Nvl\Support\Tenancy\Services\TenantSiteAttributes;
use Nvl\Support\Tenancy\ValueObjects\TenantId;
use Nvl\Support\Tenancy\ValueObjects\TenantSiteContext;

it('reads legacy public site attributes while canonical presence takes precedence', function (): void {
    $request = Request::create('https://legacy.test');
    $legacy = new TenantSiteContext(new TenantId('10000000-0000-4000-8000-000000000001'), 'legacy', 'https://legacy.test');
    $canonical = new TenantSiteContext(new TenantId('10000000-0000-4000-8000-000000000002'), 'canonical', 'https://canonical.test');
    $request->attributes->set(TenantSiteAttributes::LegacyKey, $legacy);

    expect(TenantSiteAttributes::read($request))->toBe($legacy);
    $request->attributes->set(TenantSiteContext::class, $canonical);
    expect(TenantSiteAttributes::read($request))->toBe($canonical);

    $request->attributes->set(TenantSiteContext::class, null);
    expect(TenantSiteAttributes::read($request))->toBeNull();
    $request->attributes->set(TenantSiteContext::class, 'invalid');
    expect(TenantSiteAttributes::read($request))->toBe('invalid');
});

it('writes both public site keys and restores their individual original state', function (array $original): void {
    $request = Request::create('https://current.test');
    $request->attributes->replace($original);
    $site = new TenantSiteContext(new TenantId('10000000-0000-4000-8000-000000000001'), 'current', 'https://current.test');

    $restore = TenantSiteAttributes::store($request, $site);
    expect($request->attributes->get(TenantSiteContext::class))->toBe($site)
        ->and($request->attributes->get(TenantSiteAttributes::LegacyKey))->toBe($site);
    $restore();

    expect($request->attributes->all())->toBe($original);
})->with([
    'neither key exists' => [[]],
    'canonical null is present' => [[TenantSiteContext::class => null]],
    'legacy null is present' => [['Nvl\\Tenancy\\ValueObjects\\TenantSiteContext' => null]],
    'distinct previous values' => [[TenantSiteContext::class => 'canonical-before', 'Nvl\\Tenancy\\ValueObjects\\TenantSiteContext' => 'legacy-before']],
]);

it('restores nested site scopes in order without dropping unrelated request attributes', function (): void {
    $request = Request::create('https://outer.test');
    $request->attributes->set('unrelated', 'retained');
    $outer = new TenantSiteContext(new TenantId('10000000-0000-4000-8000-000000000001'), 'outer', 'https://outer.test');
    $inner = new TenantSiteContext(new TenantId('10000000-0000-4000-8000-000000000002'), 'inner', 'https://inner.test');
    $restoreOuter = TenantSiteAttributes::store($request, $outer);
    $restoreInner = TenantSiteAttributes::store($request, $inner);

    $restoreInner();
    expect(TenantSiteAttributes::read($request))->toBe($outer);
    $restoreOuter();
    expect($request->attributes->all())->toBe(['unrelated' => 'retained']);
});
