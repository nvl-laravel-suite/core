<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Schema\SchemaConstraints;

it('uses existing constraint names after renaming a legacy table', function (): void {
    Schema::create('legacy_parent', fn (Blueprint $table) => $table->uuid('id')->primary());
    Schema::create('legacy_child', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->foreignUuid('parent_id')->constrained('legacy_parent');
        $table->string('key')->unique();
    });
    Schema::rename('legacy_child', 'renamed_child');
    $schema = Schema::getFacadeRoot()->getConnection()->getSchemaBuilder();
    $schema->table('renamed_child', function (Blueprint $table) use ($schema): void {
        SchemaConstraints::drop($schema, $table, 'foreign', ['parent_id']);
        SchemaConstraints::drop($schema, $table, 'unique', ['key']);
    });
    expect(Schema::getForeignKeys('renamed_child'))->toBe([])
        ->and(array_filter(Schema::getIndexes('renamed_child'), fn (array $index): bool => $index['unique'] && ! $index['primary']))->toBe([]);
});
