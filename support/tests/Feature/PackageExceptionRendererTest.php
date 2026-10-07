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

it('keeps the public context allowlist and safe retry headers authoritative', function (string $package, string $code, array $context, array $allowed): void {
    $failure = Mockery::mock(BusinessException::class);
    $failure->shouldReceive('package')->andReturn($package);
    $failure->shouldReceive('responseCode')->andReturn($code);
    $failure->shouldReceive('translationKey')->andReturn('missing::responsecode.'.$code);
    $failure->shouldReceive('translationParameters')->andReturn([]);
    $failure->shouldReceive('publicContext')->andReturn($context);
    $failure->shouldReceive('responseHeaders')->andReturn(['retry-after' => '120', 'X-Diagnostic' => 'secret', 'Retry-After' => "12\r\nX-Injection: true"]);
    $payload = new PackageExceptionPayload(new Translator(new ArrayLoader, 'en'));
    $result = $payload->for($failure);
    expect($result['message'])->toBe('The operation could not be completed.')
        ->and((array) $result['context'])->toBe($allowed)
        ->and($payload->headers($failure))->toBe(['Retry-After' => '120']);
})->with([
    'auth feature' => ['auth', 'feature_unavailable', ['feature' => 'otp', 'secret' => 'hidden'], ['feature' => 'otp']],
    'filter path' => ['filterable', 'bad_filter', ['path' => 'name', 'sql' => 'hidden'], ['path' => 'name']],
    'content revision' => ['content', 'stale_content', ['resource_id' => '1', 'secret' => 'hidden'], ['resource_id' => '1']],
    'content definition' => ['content', 'definition_migration_required', ['block_id' => '1', 'secret' => 'hidden'], ['block_id' => '1']],
    'media use' => ['media', 'media_in_use', ['media_id' => '1', 'secret' => 'hidden'], ['media_id' => '1']],
    'seo mutation' => ['seo', 'invalid_seo_mutation', ['errors' => ['path' => 'invalid'], 'secret' => 'hidden'], ['errors' => ['path' => 'invalid']]],
    'seo conflict' => ['seo', 'seo_path_conflict', ['path' => '/a', 'secret' => 'hidden'], ['path' => '/a']],
    'seo profile' => ['seo', 'stale_seo_profile', ['profileId' => '1', 'secret' => 'hidden'], ['profileId' => '1']],
    'seo redirect' => ['seo', 'stale_seo_redirect', ['redirectId' => '1', 'secret' => 'hidden'], ['redirectId' => '1']],
    'configuration suppression' => ['core', 'invalid_configuration', ['secret' => 'hidden'], []],
]);

it('bounds deeply nested public context without exposing the truncated leaf', function (): void {
    $context = ['leaf' => 'truncated'];
    for ($depth = 0; $depth < 34; $depth++) {
        $context = ['next' => $context];
    }
    $payload = c4Payload()->for(new BusinessException(publicContext: $context));
    expect(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('truncated');
    $nested = (array) $payload['context'];
    for ($depth = 0; $depth < 33; $depth++) {
        $nested = $nested['next'];
    }
    expect($nested)->toBe([]);
});
