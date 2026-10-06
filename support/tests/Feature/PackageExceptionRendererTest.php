<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Nvl\Support\Exceptions\BusinessException;
use Nvl\Support\Exceptions\SupportException;
use Nvl\Support\Http\PackageExceptionPayload;
use Nvl\Support\Http\PackageExceptionRenderer;
use Nvl\Support\Tenancy\Exceptions\TenantBoundaryViolation;

function c4Payload(string $locale = 'en'): PackageExceptionPayload
{
    $loader = new ArrayLoader;
    foreach (['en', 'bg'] as $language) {
        $loader->addMessages($language, 'responsecode', require __DIR__.'/../../lang/'.$language.'/responsecode.php', 'nvl-core');

    }

    return new PackageExceptionPayload(new Translator($loader, $locale));
}

function c4JsonRequest(): Request
{
    return Request::create('/host', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
}

it('lets the host handle non JSON and marker-only programmer failures', function (): void {
    $renderer = new PackageExceptionRenderer(c4Payload());
    expect($renderer->render(new BusinessException('secret'), Request::create('/host')))->toBeNull()
        ->and($renderer->render(new SupportException('secret'), c4JsonRequest()))->toBeNull();
});

it('keeps nested scalar context while dropping objects resources and nonfinite numbers', function (): void {
    $resource = fopen('php://memory', 'r');
    try {
        $failure = new BusinessException('diagnostic-secret', suggestedStatus: 409,
            publicContext: ['nested' => ['ok' => ['x', 3, false, null], 'object' => new stdClass, 'resource' => $resource, 'nan' => NAN]],
            diagnosticContext: ['sql' => 'private-sql'], previous: new RuntimeException('previous-secret'));
        $response = (new PackageExceptionRenderer(c4Payload()))->render($failure, c4JsonRequest());
        expect($response->getStatusCode())->toBe(409)
            ->and($response->getData(true)['context'])->toBe(['nested' => ['ok' => ['x', 3, false, null]]])
            ->and($response->getContent())->not->toContain('diagnostic-secret', 'private-sql', 'previous-secret');
    } finally {
        fclose($resource);
    }
});

it('renders neutral Core tenancy metadata in Bulgarian without the runtime tenancy provider', function (): void {
    $failure = new TenantBoundaryViolation('private-tenant');
    $response = (new PackageExceptionRenderer(c4Payload('bg')))->render($failure, c4JsonRequest());
    expect($failure->package())->toBe('core')->and($failure->translationKey())->toBe('nvl-core::responsecode.tenant_boundary_violation')
        ->and($response->getStatusCode())->toBe(404)
        ->and($response->getContent())->not->toContain('private-tenant');
});
