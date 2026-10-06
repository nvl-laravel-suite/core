<?php

declare(strict_types=1);

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
            $migration = require dirname(__DIR__, 4).'/forms/'.$manifest['migrations'][$old]['path'];
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
