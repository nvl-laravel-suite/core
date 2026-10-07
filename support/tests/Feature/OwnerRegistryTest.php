<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Doctor\CoreDoctor;
use Nvl\Support\Doctor\OwnerIdentityDiagnostics;
use Nvl\Support\Globals\GlobalNames;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Tests\Fixtures\PackageOwner;

beforeEach(function (): void {
    $this->originalMorphMap = Relation::morphMap();
    $this->originalMorphRequirement = Relation::requiresMorphMap();
    Relation::morphMap([], false);
    Relation::requireMorphMap(false);
});

afterEach(function (): void {
    Relation::morphMap($this->originalMorphMap, false);
    Relation::requireMorphMap($this->originalMorphRequirement);
});

it('shares declared class capabilities without enforcing unrelated host models', function (): void {
    $owner = new class extends Model {};
    $other = new class extends Model {};
    Relation::morphMap(['host-model' => $other::class]);
    $registry = new OwnerRegistry(new Repository(['nvl-core' => ['owners' => [$owner::class]]]));

    expect($registry->model($owner::class))->toBe($owner::class)
        ->and($registry->aliasFor($owner))->toBe($owner::class)
        ->and($owner->getMorphClass())->toBe($owner::class)
        ->and(Relation::getMorphedModel('host-model'))->toBe($other::class)
        ->and(Relation::requiresMorphMap())->toBeFalse();
});

it('reports legacy identity conflicts without changing the host morph map', function (string $conflict): void {
    $owner = new class extends Model {};
    $other = new class extends Model {};
    Relation::morphMap($conflict === 'alias' ? ['article' => $other::class] : ['old-article' => $owner::class]);
    $original = Relation::morphMap();
    $registry = new OwnerRegistry(new Repository);
    $registry->register('article', $owner::class);

    expect($registry->errors())->toHaveCount(1)
        ->and(Relation::morphMap())->toBe($original);
})->with(['alias', 'model']);

it('validates an entire class list without adding any global aliases', function (): void {
    $owner = new class extends Model {};
    $registry = new OwnerRegistry(new Repository(['nvl-core' => ['owners' => [$owner::class, stdClass::class]]]));

    expect(fn () => $registry->all())->toThrow(InvalidArgumentException::class)
        ->and(Relation::morphMap())->toBe([]);
});

it('keeps legacy class-backed identities from silently switching write types', function (): void {
    $owner = new class extends Model {};
    $registry = new OwnerRegistry(new Repository);

    expect($registry->reference($owner::class, 'nvl-seo.owners.article', 'article'))->toBe($owner::class)
        ->and($registry->aliasFor($owner))->toBe($owner::class)
        ->and($owner->getMorphClass())->toBe($owner::class)
        ->and(Relation::getMorphedModel('article'))->toBeNull();
    $registry->reference($owner::class, 'nvl-seo.owners.article', 'article');
    expect($registry->errors())->toHaveCount(1);
});

it('retains host-mapped aliases and isolates registrations in different capabilities', function (): void {
    $owner = new class extends Model {};
    $other = new class extends Model {};
    Relation::morphMap(['article' => $owner::class]);
    $registry = new OwnerRegistry(new Repository(['nvl-core' => ['owners' => ['article' => $owner::class]]]));

    expect($registry->reference($owner::class, 'nvl-content.owners.article', 'article'))->toBe($owner::class)
        ->and($owner->getMorphClass())->toBe('article')
        ->and($registry->reference($other::class, 'nvl-seo.owners.article', 'article'))->toBe($other::class)
        ->and($registry->errors())->toHaveCount(1)
        ->and($other->getMorphClass())->toBe($other::class);
});

it('reports a second historical alias for a class-backed owner', function (): void {
    $owner = new class extends Model {};
    $registry = new OwnerRegistry(new Repository);
    $registry->reference($owner::class, 'nvl-seo.owners.article', 'article');
    $registry->register('different', $owner::class);

    expect($registry->errors())->toHaveCount(2)
        ->and($owner->getMorphClass())->toBe($owner::class);
});

it('rejects unknown references and invalid declarations', function (): void {
    $registry = new OwnerRegistry(new Repository);

    expect(fn () => $registry->model('unknown'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register('', Model::class))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->register('article', stdClass::class))->toThrow(InvalidArgumentException::class);
});

it('identifies existing non Eloquent classes as invalid model declarations', function (): void {
    $registry = new OwnerRegistry(new Repository);
    expect(fn () => $registry->reference(stdClass::class, 'nvl-seo.owners.article', 'article'))
        ->toThrow(InvalidArgumentException::class, 'must be an Eloquent model class');
});

it('resolves declared historical references before interpreting existing class names', function (): void {
    $owner = new class extends Model {};
    Relation::morphMap(['stdClass' => $owner::class]);
    $registry = new OwnerRegistry(new Repository(['nvl-core' => ['owners' => ['stdClass' => $owner::class]]]));

    expect($registry->reference('stdClass', 'nvl-seo.owners.article'))->toBe($owner::class)
        ->and($registry->errors())->toBe([]);
});

it('rejects numeric historical aliases without changing morph mappings', function (): void {
    $owner = new class extends Model {};
    $registry = new OwnerRegistry(new Repository);

    expect(fn () => $registry->register('123', $owner::class))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->reference($owner::class, 'nvl-seo.owners.123', '123'))->toThrow(InvalidArgumentException::class)
        ->and(Relation::getMorphedModel('123'))->toBeNull();
    $configured = new OwnerRegistry(new Repository(['nvl-core' => ['owners' => ['123' => $owner::class]]]));
    expect(fn () => $configured->all())->toThrow(InvalidArgumentException::class);
});

it('declares class capabilities using the existing Laravel morph identity', function (): void {
    $owner = new class extends Model {};
    Relation::morphMap(['host-product' => $owner::class]);
    $before = Relation::morphMap();
    $registry = new OwnerRegistry(new Repository(['nvl-core' => ['owners' => [$owner::class]]]));

    expect($registry->all())->toBe(['host-product' => $owner::class])
        ->and($registry->aliasFor($owner))->toBe('host-product')
        ->and($registry->model($owner::class))->toBe($owner::class)
        ->and(Relation::morphMap())->toBe($before);
});

it('reports a legacy identity mismatch without changing host mappings or write identity', function (): void {
    $owner = new class extends Model {};
    $registry = new OwnerRegistry(new Repository(['nvl-core' => ['owners' => ['article' => $owner::class]]]));

    expect($registry->aliasFor($owner))->toBe($owner::class)
        ->and($registry->errors())->toHaveCount(1)
        ->and($owner->getMorphClass())->toBe($owner::class)
        ->and(Relation::morphMap())->toBe([]);
});

it('silently accepts a historical alias that already matches the host map', function (): void {
    $owner = new class extends Model {};
    Relation::morphMap(['article' => $owner::class]);
    $registry = new OwnerRegistry(new Repository(['nvl-core' => ['owners' => ['article' => $owner::class]]]));

    expect($registry->all())->toBe(['article' => $owner::class])
        ->and($registry->errors())->toBe([])
        ->and($registry->deprecations())->toBe([]);
});

it('orders package canonical writes before legacy read aliases', function (): void {
    $registry = new OwnerRegistry(new Repository);
    Relation::morphMap(['page' => PackageOwner::class]);
    $registry->registerPackage('nvl-page', PackageOwner::class, ['page'], package: 'pages');

    expect((new PackageOwner)->getMorphClass())->toBe('nvl-page')
        ->and(Relation::getMorphedModel('page'))->toBe(PackageOwner::class)
        ->and(Relation::getMorphedModel('nvl-page'))->toBe(PackageOwner::class);
});

it('diagnoses package alias collisions and preserves host-authored identities', function (): void {
    $host = new class extends Model {};
    Relation::morphMap(['nvl-page' => $host::class]);
    $config = new Repository;
    $registry = new OwnerRegistry($config);
    $map = Relation::morphMap();
    $registry->registerPackage('nvl-page', PackageOwner::class, ['page'], package: 'pages');
    expect(Relation::morphMap())->toBe($map)
        ->and((new GlobalNames($config))->diagnostics()[0]->passed)->toBeFalse()
        ->and((new GlobalNames($config))->diagnostics()[0]->message)->toContain('[nvl-page]', 'preserved');

    Relation::morphMap(['host-page' => PackageOwner::class], false);
    $registry->registerPackage('nvl-page', PackageOwner::class, ['page'], package: 'pages');
    expect(Relation::morphMap())->toBe(['host-page' => PackageOwner::class])
        ->and((new PackageOwner)->getMorphClass())->toBe('host-page');
});

it('reports historical NVL owner rows without rewriting them', function (bool $legacyAlias): void {
    $owner = new PackageOwner;
    Relation::morphMap(['host-article' => $owner::class]);
    config(['nvl-core.owners' => $legacyAlias ? ['article' => $owner::class] : [$owner::class]]);
    Schema::create('nvl_content_placements', function (Blueprint $table): void {
        $table->string('id');
        $table->string('owner_type');
        $table->string('owner_id');
    });
    DB::table('nvl_content_placements')->insert([
        'id' => 'old-placement', 'owner_type' => $legacyAlias ? 'article' : $owner::class, 'owner_id' => 'retained-owner',
    ]);
    try {
        $checks = app(CoreDoctor::class)->inspect();
        $mismatches = array_values(array_filter($checks, fn ($check): bool => str_starts_with($check->key, 'owners.rows.')));
        expect($mismatches)->toHaveCount(1)
            ->and($mismatches[0]->message)->toContain('content', 'owner_type', 'host-article')
            ->and(DB::table('nvl_content_placements')->value('owner_type'))->toBe($legacyAlias ? 'article' : $owner::class);
    } finally {
        Schema::drop('nvl_content_placements');
    }
})->with(['retained class' => false, 'declared historical alias' => true]);

it('excludes authentication principal columns from capability owner history scans', function (): void {
    $owner = new class extends Model {};
    config(['nvl-core.owners' => [$owner::class]]);
    Schema::create('nvl_auth_personal_access_tokens', function (Blueprint $table): void {
        $table->string('id');
        $table->string('tokenable_type');
        $table->string('tokenable_id');
    });
    DB::table('nvl_auth_personal_access_tokens')->insert([
        'id' => 'host-token', 'tokenable_type' => 'host-user', 'tokenable_id' => 'host-principal',
    ]);
    try {
        $checks = app(CoreDoctor::class)->inspect();
        expect(array_values(array_filter($checks, fn ($check): bool => str_starts_with($check->key, 'owners.rows.'))))->toBe([])
            ->and(DB::table('nvl_auth_personal_access_tokens')->value('tokenable_type'))->toBe('host-user');
    } finally {
        Schema::drop('nvl_auth_personal_access_tokens');
    }
});

it('preserves occupied legacy package aliases while installing the canonical identity', function (bool $compatibility): void {
    $host = new class extends Model {};
    Relation::morphMap(['page' => $host::class]);
    $config = new Repository(['nvl-core' => ['compatibility' => ['global_aliases' => $compatibility ? ['pages'] : []]]]);
    $registry = new OwnerRegistry($config);
    $registry->registerPackage('nvl-page', PackageOwner::class, ['page'], package: 'pages');
    $registry->registerPackage('nvl-page', PackageOwner::class, ['page'], package: 'pages');

    expect(Relation::getMorphedModel('page'))->toBe($host::class)
        ->and(Relation::getMorphedModel('nvl-page'))->toBe(PackageOwner::class)
        ->and((new PackageOwner)->getMorphClass())->toBe('nvl-page')
        ->and($registry->errors())->toBe([])
        ->and(collect((new GlobalNames($config))->diagnostics())->filter(static fn ($check): bool => str_contains($check->message, '[page]')))->toHaveCount($compatibility ? 1 : 0);
})->with(['default off' => false, 'explicit selection' => true]);

it('installs package legacy morph aliases only for an explicitly selected compatibility group', function (bool $compatibility): void {
    $config = new Repository(['nvl-core' => ['compatibility' => ['global_aliases' => $compatibility ? ['pages'] : []]]]);
    $registry = new OwnerRegistry($config);
    $registry->registerPackage('nvl-page', PackageOwner::class, ['page'], package: 'pages');
    expect(Relation::getMorphedModel('nvl-page'))->toBe(PackageOwner::class)
        ->and(Relation::getMorphedModel('page'))->toBe($compatibility ? PackageOwner::class : null)
        ->and((new PackageOwner)->getMorphClass())->toBe('nvl-page');
})->with(['default off' => false, 'explicit selection' => true]);

it('exposes only the capability reference and genuine legacy identity arguments', function (): void {
    expect(array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), (new ReflectionMethod(OwnerRegistry::class, 'reference'))->getParameters()))->toBe(['reference', 'source', 'legacyAlias']);
});

it('reports retained identity changes in every native owner and actor column without changing rows', function (string $table, string $column): void {
    $owner = new PackageOwner;
    Relation::morphMap(['current-host-owner' => $owner::class], false);
    config(['nvl-core.owners' => []]);
    Schema::create($table, function (Blueprint $schema) use ($column): void {
        $schema->string('id');
        $schema->string($column)->nullable();
    });
    DB::table($table)->insert(['id' => 'retained-row', $column => $owner::class]);
    try {
        $checks = app(OwnerIdentityDiagnostics::class)->inspect();
        $mismatches = array_values(array_filter($checks, static fn ($check): bool => str_starts_with($check->key, 'owners.rows.')));
        expect($mismatches)->toHaveCount(1)
            ->and($mismatches[0]->message)->toContain($table, $column, 'current-host-owner')
            ->and(DB::table($table)->value($column))->toBe($owner::class);
        DB::table($table)->update([$column => 'current-host-owner']);
        expect(app(OwnerIdentityDiagnostics::class)->inspect())->toBe([]);
    } finally {
        Schema::drop($table);
    }
})->with([
    ['nvl_activity_log', 'subject_type'], ['nvl_activity_log', 'causer_type'],
    ['nvl_mail_notifications_notifications', 'notifiable_type'], ['nvl_mail_notifications_scheduled_messages', 'notifiable_type'],
    ['nvl_comments_comments', 'actor_type'], ['nvl_comments_comments', 'moderated_by_type'], ['nvl_comments_comments', 'deleted_by_type'], ['nvl_comments_comments', 'restored_by_type'], ['nvl_comments_comments', 'anonymized_by_type'],
    ['nvl_comments_reactions', 'actor_type'], ['nvl_comments_reports', 'reporter_type'], ['nvl_comments_reports', 'reviewed_by_type'], ['nvl_comments_revisions', 'edited_by_type'],
    ['nvl_tasks_tasks', 'creator_type'], ['nvl_tasks_time_entries', 'performer_type'], ['nvl_tasks_assignments', 'assigned_by_type'], ['nvl_tasks_checklist_items', 'completed_by_type'],
    ['nvl_content_blocks', 'created_by_type'], ['nvl_content_blocks', 'updated_by_type'], ['nvl_content_blocks', 'published_by_type'], ['nvl_content_revisions', 'actor_type'],
    ['nvl_media_media', 'uploaded_by_type'], ['nvl_media_multipart_uploads', 'uploader_type'], ['nvl_media_owner_slot_operations', 'actor_type'],
    ['nvl_templates_versions', 'published_by_type'], ['nvl_templates_renders', 'requested_by_type'],
]);

it('admits canonical system actors only in declared actor columns with null identifiers', function (string $table, string $type, string $id): void {
    Schema::create($table, static function (Blueprint $schema) use ($type, $id): void {
        $schema->string('id');
        $schema->string($type)->nullable();
        $schema->string($id)->nullable();
    });
    try {
        DB::table($table)->insert(['id' => 'healthy-system-actor', $type => 'system', $id => null]);
        DB::table($table)->insert(['id' => 'non-eloquent-actor', $type => GenericUser::class, $id => 'host-principal']);
        expect(app(OwnerIdentityDiagnostics::class)->inspect())->toBe([]);
        DB::table($table)->insert(['id' => 'invalid-system-actor', $type => 'system', $id => 'not-a-system-identity']);
        $checks = app(OwnerIdentityDiagnostics::class)->inspect();
        expect($checks)->toHaveCount(1)->and($checks[0]->message)->toContain($type, 'system');
    } finally {
        Schema::drop($table);
    }
})->with([
    ['nvl_comments_comments', 'actor_type', 'actor_id'],
    ['nvl_comments_comments', 'moderated_by_type', 'moderated_by_id'],
    ['nvl_content_blocks', 'created_by_type', 'created_by_id'],
    ['nvl_content_revisions', 'actor_type', 'actor_id'],
    ['nvl_media_owner_slot_operations', 'actor_type', 'actor_id'],
    ['nvl_templates_versions', 'published_by_type', 'published_by'],
]);

it('reports owner identity and configuration failures without changing host identities', function (): void {
    $owners = app(OwnerRegistry::class);
    $owners->reference(PackageOwner::class, 'nvl-seo.owners.article', 'article');
    $checks = array_column(app(CoreDoctor::class)->inspect(), null, 'key');
    expect(array_filter(array_keys($checks), static fn (string $key): bool => str_starts_with($key, 'owners.identity.')))->not->toBeEmpty()
        ->and((new PackageOwner)->getMorphClass())->toBe(PackageOwner::class);
    config(['nvl-core.owners' => [stdClass::class]]);
    app()->forgetInstance(OwnerRegistry::class);
    app()->forgetInstance(CoreDoctor::class);
    $checks = array_column(app(CoreDoctor::class)->inspect(), null, 'key');
    expect($checks['owners.configuration']->severity)->toBe('error');
});
