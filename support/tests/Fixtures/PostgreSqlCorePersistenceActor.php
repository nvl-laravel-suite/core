<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

/** Native host actor stored on the disposable PostgreSQL database. */
final class PostgreSqlCorePersistenceActor extends User
{
    protected $table = 'core_postgresql_actor_users';

    protected $guarded = [];

    public $timestamps = false;
}
