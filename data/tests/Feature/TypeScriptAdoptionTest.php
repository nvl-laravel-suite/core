<?php

declare(strict_types=1);

use Nvl\Data\Providers\DataServiceProvider;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;

it('preserves the host transformer configuration and binding by default', function (): void {
    $host = TypeScriptTransformerConfigFactory::create()->outputDirectory(__DIR__)->get();
    app()->instance(TypeScriptTransformerConfig::class, $host);
    config(['typescript-transformer.host_marker' => 'preserve']);
    $configuration = config('typescript-transformer');
    (new DataServiceProvider(app()))->register();
    expect(app(TypeScriptTransformerConfig::class))->toBe($host)
        ->and(config('typescript-transformer'))->toBe($configuration);
});

it('replaces the global transformer binding only through explicit adoption', function (): void {
    $host = TypeScriptTransformerConfigFactory::create()->outputDirectory(__DIR__)->get();
    app()->instance(TypeScriptTransformerConfig::class, $host);
    config(['nvl-data.typescript.adopt_global_config' => true]);
    (new DataServiceProvider(app()))->register();
    expect(app(TypeScriptTransformerConfig::class))->not->toBe($host);
});
