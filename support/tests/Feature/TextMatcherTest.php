<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Contracts\TextMatcher;
use Nvl\Support\Doctor\DoctorRegistry;
use Nvl\Support\Providers\DoctorServiceProvider;

it('matches Unicode text and supports literal, wildcard and negated predicates', function (): void {
    $connection = DB::connection();
    $connection->getSchemaBuilder()->create('search_examples', static function (Blueprint $table): void {
        $table->integer('id')->primary();
        $table->text('label')->nullable();
    });

    try {
        $connection->table('search_examples')->insert([
            ['id' => 1, 'label' => 'ПРИВЕТ !%_'],
            ['id' => 2, 'label' => 'Привет !xy'],
            ['id' => 3, 'label' => null],
            ['id' => 4, 'label' => 'ΆΛΦΑ'],
            ['id' => 5, 'label' => 'ДРУГОЕ'],
            ['id' => 6, 'label' => 'ΌΣ ος'],
        ]);
        $matcher = app(TextMatcher::class);
        $query = static fn () => $connection->table('search_examples')->orderBy('id');

        expect($matcher->contains($query(), 'search_examples.label', 'привет !%_')->pluck('id')->all())->toBe([1])
            ->and($matcher->contains($query(), 'label', 'привет !%_', wildcards: true)->pluck('id')->all())->toBe([1, 2])
            ->and($matcher->contains($query(), 'label', 'привет', negated: true)->pluck('id')->all())->toBe([4, 5, 6])
            ->and($matcher->contains($query()->where('id', 5), 'label', 'άλφα', boolean: 'or')->pluck('id')->all())->toBe([4, 5])
            ->and($matcher->contains($query(), 'label', 'ός οΣ')->pluck('id')->all())->toBe([6]);

        expect(fn () => $matcher->contains($query(), 'upper(label)', 'Привет'))->toThrow(InvalidArgumentException::class);
    } finally {
        $connection->getSchemaBuilder()->drop('search_examples');
    }
});

it('reports usable and missing PostgreSQL search collations through Doctor', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL collation diagnostics require a native database.');
    }

    $this->app->register(DoctorServiceProvider::class);
    $collationCheck = static fn () => collect(app(DoctorRegistry::class)->inspect()['checks'])->firstWhere('key', 'search.postgres_collation');

    expect($collationCheck())->toMatchArray(['result' => 'pass']);

    config()->set('nvl-core.search.postgres_collation', 'pg_catalog.C');
    expect($collationCheck())->toMatchArray(['result' => 'pass', 'message' => 'PostgreSQL text search uses ["pg_catalog"."C"].']);

    config()->set('nvl-core.search.postgres_collation', 'pg_catalog.nvl_missing_collation');
    expect($collationCheck())->toMatchArray(['result' => 'fail', 'severity' => 'error']);

    config()->set('nvl-core.search.postgres_collation', 'pg_catalog.C; select 1');
    expect($collationCheck())->toMatchArray(['result' => 'fail', 'severity' => 'error']);
});

it('folds Cyrillic and Greek on a C-locale PostgreSQL database', function (): void {
    $connection = DB::connection();

    if ($connection->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The C-locale case needs PostgreSQL.');
    }
    $collation = $connection->selectOne('select datcollate from pg_database where datname = current_database()')->datcollate;
    if (getenv('NVL_TEST_DB_LOCALE') !== 'C' && $collation !== 'C') {
        $this->markTestSkipped('The C-locale case runs on a C-locale database, such as postgresql-c-locale-package-tests.');
    }

    expect($collation)->toBe('C');

    $connection->getSchemaBuilder()->create('c_locale_search_examples', static function (Blueprint $table): void {
        $table->integer('id')->primary();
        $table->text('label')->nullable();
    });

    try {
        $connection->table('c_locale_search_examples')->insert([
            ['id' => 1, 'label' => 'АНА'],
            ['id' => 2, 'label' => 'ΣΟΦΙΑ'],
            ['id' => 3, 'label' => '100%_off!'],
            ['id' => 4, 'label' => '100xyoff'],
        ]);
        $matcher = app(TextMatcher::class);
        $query = static fn () => $connection->table('c_locale_search_examples')->orderBy('id');

        expect($matcher->contains($query(), 'label', 'ана')->pluck('id')->all())->toBe([1])
            ->and($matcher->contains($query(), 'label', 'σοφια')->pluck('id')->all())->toBe([2])
            ->and($matcher->contains($query(), 'label', '%_off!')->pluck('id')->all())->toBe([3]);
    } finally {
        $connection->getSchemaBuilder()->drop('c_locale_search_examples');
    }
});
