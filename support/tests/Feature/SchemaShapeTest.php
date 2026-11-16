<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Schema\SchemaConstraints;
use Nvl\Support\Schema\SchemaIdentities;
use Nvl\Support\Schema\SchemaShape;
use Nvl\Support\Schema\SchemaUpgrade;

beforeEach(function (): void {
    config(['nvl-forms' => []]);
});

it('rejects a familiar foreign column referencing an unrelated host parent', function (): void {
    Schema::create('host_forms', fn (Blueprint $table) => $table->uuid('id')->primary());
    Schema::create('legacy_child', function (Blueprint $table): void {
        $table->foreignUuid('form_id')->constrained('host_forms');
    });

    expect(fn () => SchemaShape::assertCompatible(Schema::getConnection()->getSchemaBuilder(), 'legacy_child', ['form_id' => 'uuid'], [], nvlFormForeignIdentity()))
        ->toThrow(LogicException::class, 'released foreign key');
});

it('refuses a complete recorded legacy package before any rename when its parent target is unrelated', function (): void {
    $manifest = SchemaIdentities::package('forms');
    config(['nvl-forms.tables' => array_map(static fn (array $table): string => $table['legacy'], $manifest['tables'])]);
    $creators = [];
    foreach ($manifest['tables'] as $table) {
        foreach ($table['create_migrations'] as $old) {
            if (isset($creators[$old])) {
                continue;
            }
            $migration = require (InstalledVersions::getInstallPath('nvl/forms') ?? dirname(__DIR__, 4).'/forms').'/'.$manifest['migrations'][$old]['path'];
            $migration->up();
            $creators[$old] = true;
        }
    }
    config(['nvl-forms' => []]);
    Schema::create('migrations', function (Blueprint $table): void {
        $table->increments('id');
        $table->string('migration');
        $table->integer('batch');
    });
    foreach (array_keys($creators) as $old) {
        DB::table('migrations')->insert(['migration' => $old, 'batch' => 7]);
    }
    Schema::create('host_forms', fn (Blueprint $table) => $table->uuid('id')->primary());
    $schema = DB::connection()->getSchemaBuilder();
    $schema->table('form_entries', static function (Blueprint $table) use ($schema): void {
        SchemaConstraints::drop($schema, $table, 'foreign', ['form_id']);
    });
    $schema->table('form_entries', static fn (Blueprint $table) => $table->foreign('form_id')->references('id')->on('host_forms'));

    expect(fn () => app(SchemaUpgrade::class)->plan(['forms'], true))->toThrow(LogicException::class, 'released foreign key')
        ->and(Schema::hasTable('forms'))->toBeTrue()
        ->and(Schema::hasTable('nvl_forms_forms'))->toBeFalse()
        ->and(DB::table('migrations')->orderBy('id')->pluck('migration')->all())->toBe(array_keys($creators));

    $schema->table('form_entries', static function (Blueprint $table) use ($schema): void {
        SchemaConstraints::drop($schema, $table, 'foreign', ['form_id']);
    });
    $schema->table('form_entries', static fn (Blueprint $table) => $table->foreign('form_id')->references('id')->on('forms')->cascadeOnDelete());
    $upgrade = app(SchemaUpgrade::class);
    $plan = $upgrade->plan(['forms'], true);
    expect($plan['steps'])->toHaveCount(14);
    $upgrade->execute($plan);
    expect(Schema::hasTable('forms'))->toBeFalse()
        ->and(Schema::hasTable('nvl_forms_forms'))->toBeTrue()
        ->and($upgrade->plan(['forms'], true)['steps'])->toBe([]);
});

it('rejects the correct parent table when a foreign key references the wrong column', function (): void {
    Schema::create('forms', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->uuid('host_key')->unique();
    });
    Schema::create('legacy_child', function (Blueprint $table): void {
        $table->foreign('form_id')->references('host_key')->on('forms');
        $table->uuid('form_id');
    });

    expect(fn () => SchemaShape::assertCompatible(Schema::getConnection()->getSchemaBuilder(), 'legacy_child', ['form_id' => 'uuid'], [], nvlFormForeignIdentity()))
        ->toThrow(LogicException::class, 'released foreign key');
});

it('accepts the legacy and effective configured parent through resumed table renames', function (): void {
    config(['nvl-forms.tables.forms' => 'configured_forms']);
    Schema::create('forms', fn (Blueprint $table) => $table->uuid('id')->primary());
    Schema::create('legacy_child', function (Blueprint $table): void {
        $table->foreignUuid('form_id')->constrained('forms');
    });
    $schema = Schema::getConnection()->getSchemaBuilder();
    SchemaShape::assertCompatible($schema, 'legacy_child', ['form_id' => 'uuid'], [], nvlFormForeignIdentity());

    Schema::rename('forms', 'configured_forms');
    SchemaShape::assertCompatible($schema, 'legacy_child', ['form_id' => 'uuid'], [], nvlFormForeignIdentity());
    expect(Schema::getForeignKeys('legacy_child')[0]['foreign_table'])->toBe('configured_forms');
});

it('accepts matching tenant composites despite valid adoption lifecycle changes', function (string $partition): void {
    Schema::create('forms', function (Blueprint $table) use ($partition): void {
        $table->uuid('id');
        $table->string($partition);
        $table->unique([$partition, 'id']);
    });
    Schema::create('legacy_child', function (Blueprint $table) use ($partition): void {
        $table->uuid('form_id');
        $table->string($partition);
        $table->foreign([$partition, 'form_id'])->references([$partition, 'id'])->on('forms')->restrictOnDelete();
    });

    SchemaShape::assertCompatible(Schema::getConnection()->getSchemaBuilder(), 'legacy_child', ['form_id' => 'uuid'], [], nvlFormForeignIdentity());
    expect(Schema::getForeignKeys('legacy_child')[0]['foreign_columns'])->toBe([$partition, 'id']);
})->with(['tenant_id', 'ownership_key']);

it('rejects tenant composites whose referenced partition differs from the local partition', function (): void {
    Schema::create('forms', function (Blueprint $table): void {
        $table->uuid('id');
        $table->string('host_partition');
        $table->unique(['host_partition', 'id']);
    });
    Schema::create('legacy_child', function (Blueprint $table): void {
        $table->uuid('form_id');
        $table->string('tenant_id');
        $table->foreign(['tenant_id', 'form_id'])->references(['host_partition', 'id'])->on('forms');
    });

    expect(fn () => SchemaShape::assertCompatible(Schema::getConnection()->getSchemaBuilder(), 'legacy_child', ['form_id' => 'uuid'], [], nvlFormForeignIdentity()))
        ->toThrow(LogicException::class, 'released foreign key');
});

it('rejects a package parent configured on a different database connection', function (): void {
    config(['database.connections.other_forms' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'nvl-forms.connection' => 'other_forms']);
    Schema::create('forms', fn (Blueprint $table) => $table->uuid('id')->primary());
    Schema::create('legacy_child', function (Blueprint $table): void {
        $table->foreignUuid('form_id')->constrained('forms');
    });

    expect(fn () => SchemaShape::assertCompatible(Schema::getConnection()->getSchemaBuilder(), 'legacy_child', ['form_id' => 'uuid'], [], nvlFormForeignIdentity()))
        ->toThrow(LogicException::class, 'released foreign key');
});

it('accepts database table prefixes when verifying referenced parent identities', function (): void {
    $connection = DB::connection();
    $original = $connection->getTablePrefix();
    $connection->setTablePrefix('fixture_');
    try {
        $schema = $connection->getSchemaBuilder();
        $schema->create('forms', fn (Blueprint $table) => $table->uuid('id')->primary());
        $schema->create('legacy_child', function (Blueprint $table): void {
            $table->foreignUuid('form_id')->constrained('forms');
        });
        SchemaShape::assertCompatible($schema, 'legacy_child', ['form_id' => 'uuid'], [], nvlFormForeignIdentity());
        expect($schema->getForeignKeys('legacy_child')[0]['foreign_table'])->toBe('fixture_forms');
    } finally {
        $connection->setTablePrefix($original);
    }
});

it('allows the conditional host directory foreign key to be absent but rejects an unrelated target if present', function (): void {
    $identity = array_values(array_filter(SchemaIdentities::package('tenancy')['tables']['adoption_mappings']['foreign_keys'], static fn (array $foreign): bool => $foreign['columns'] === ['tenant_id']));
    Schema::create('legacy_mapping', fn (Blueprint $table) => $table->uuid('tenant_id'));
    SchemaShape::assertCompatible(Schema::getConnection()->getSchemaBuilder(), 'legacy_mapping', ['tenant_id' => 'uuid'], [], $identity);
    Schema::create('host_tenants', fn (Blueprint $table) => $table->uuid('id')->primary());
    Schema::create('legacy_mapping_with_parent', function (Blueprint $table): void {
        $table->foreignUuid('tenant_id')->constrained('host_tenants');
    });

    expect(fn () => SchemaShape::assertCompatible(Schema::getConnection()->getSchemaBuilder(), 'legacy_mapping_with_parent', ['tenant_id' => 'uuid'], [], $identity))
        ->toThrow(LogicException::class, 'released foreign key');
});

it('requires the released self reference added after the Auth role table is created', function (): void {
    Schema::create('legacy_roles', fn (Blueprint $table) => $table->uuid('parent_id')->nullable());
    $foreign = SchemaIdentities::package('auth')['tables']['roles']['foreign_keys'];
    expect(fn () => SchemaShape::assertCompatible(Schema::getConnection()->getSchemaBuilder(), 'legacy_roles', ['parent_id' => 'uuid'], [], $foreign))
        ->toThrow(LogicException::class, 'released foreign key');
});

it('rejects a matching parent name in another schema unless that schema is explicitly configured', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL schema namespaces are required for this target identity proof.');
    }
    $schema = DB::connection()->getSchemaBuilder();
    DB::statement('CREATE SCHEMA nvl_foreign_target_fixture');
    try {
        $schema->create('forms', fn (Blueprint $table) => $table->uuid('id')->primary());
        $schema->create('nvl_foreign_target_fixture.forms', fn (Blueprint $table) => $table->uuid('id')->primary());
        $schema->create('legacy_child', function (Blueprint $table): void {
            $table->foreignUuid('form_id')->constrained('nvl_foreign_target_fixture.forms');
        });
        expect(fn () => SchemaShape::assertCompatible($schema, 'legacy_child', ['form_id' => 'uuid'], [], nvlFormForeignIdentity()))
            ->toThrow(LogicException::class, 'released foreign key');

        config(['nvl-forms.tables.forms' => 'nvl_foreign_target_fixture.forms']);
        SchemaShape::assertCompatible($schema, 'legacy_child', ['form_id' => 'uuid'], [], nvlFormForeignIdentity());
        expect($schema->getForeignKeys('legacy_child')[0]['foreign_schema'])->toBe('nvl_foreign_target_fixture');
    } finally {
        $schema->dropIfExists('legacy_child');
        $schema->dropIfExists('nvl_foreign_target_fixture.forms');
        DB::statement('DROP SCHEMA nvl_foreign_target_fixture');
    }
});

/**
 * Return the released Form translation foreign identity for isolated ownership regressions.
 *
 * @return list<array{columns: list<string>, package: string, table: string, references: list<string>, required: bool}>
 */
function nvlFormForeignIdentity(): array
{
    return SchemaIdentities::package('forms')['tables']['i18n']['foreign_keys'];
}

it('accepts released JSON columns and rejects unrelated native text storage', function (): void {
    $schema = DB::connection()->getSchemaBuilder();
    $schema->create('native_json_shape', static function (Blueprint $table): void {
        $table->json('payload')->nullable();
        $table->longText('unconstrained_payload')->nullable();
    });
    SchemaShape::assertCompatible($schema, 'native_json_shape', ['payload' => 'json']);
    expect($schema->hasColumn('native_json_shape', 'payload'))->toBeTrue();
    if ($schema->getConnection()->getDriverName() !== 'sqlite') {
        expect(fn () => SchemaShape::assertCompatible($schema, 'native_json_shape', ['unconstrained_payload' => 'json']))
            ->toThrow(LogicException::class, 'unconstrained_payload');
    }
});

/** Create a table whose released unique key on [code] the given statements try to satisfy. */
function schemaShapeUniqueCodes(string ...$statements): void
{
    Schema::create('shape_codes', static function (Blueprint $table): void {
        $table->string('code');
        $table->string('name');
        $table->integer('retired')->default(0);
    });
    foreach ($statements as $statement) {
        DB::statement($statement);
    }
}

it('rejects partial uniqueness', function (string $variant): void {
    if ($variant === 'invalid' && DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Only PostgreSQL keeps an invalid index after a failed concurrent build.');
    }
    match ($variant) {
        'partial' => schemaShapeUniqueCodes('create unique index shape_codes_code on shape_codes (code) where retired = 0'),
        'expression' => schemaShapeUniqueCodes('create unique index shape_codes_code on shape_codes (code, lower(name))'),
        'invalid' => schemaShapeUniqueCodes("insert into shape_codes (code, name) values ('a', 'x'), ('a', 'y')"),
    };
    if ($variant === 'invalid') {
        expect(fn () => DB::statement('create unique index concurrently shape_codes_code on shape_codes (code)'))->toThrow(QueryException::class)
            ->and(DB::scalar("select indisvalid from pg_index where indexrelid = 'shape_codes_code'::regclass"))->toBeFalse();
    }

    expect(collect(Schema::getIndexes('shape_codes'))->firstWhere('name', 'shape_codes_code')['columns'] ?? null)->toBe(['code'])
        ->and(fn () => SchemaShape::assertCompatible(DB::connection()->getSchemaBuilder(), 'shape_codes', ['code' => 'string'], [['type' => 'unique', 'columns' => ['code']]]))
        ->toThrow(LogicException::class, 'missing its released unique key on [code]');
})->with(['partial', 'expression', 'invalid']);

it('accepts unconditional replacement uniqueness', function (): void {
    $pgsql = DB::connection()->getDriverName() === 'pgsql';
    schemaShapeUniqueCodes($pgsql
        ? 'create unique index host_replacement_code on shape_codes (code) include (name)'
        : 'create unique index host_replacement_code on shape_codes (code)');

    SchemaShape::assertCompatible(DB::connection()->getSchemaBuilder(), 'shape_codes', ['code' => 'string'], [['type' => 'unique', 'columns' => ['code']]]);
    if ($pgsql) {
        expect(collect(Schema::getIndexes('shape_codes'))->firstWhere('name', 'host_replacement_code')['columns'])->toBe(['code', 'name']);
    }

    expect(fn () => SchemaShape::assertCompatible(DB::connection()->getSchemaBuilder(), 'shape_codes', ['code' => 'string'], [['type' => 'unique', 'columns' => ['code', 'name']]]))
        ->toThrow(LogicException::class, 'missing its released unique key on [code, name]');
});
