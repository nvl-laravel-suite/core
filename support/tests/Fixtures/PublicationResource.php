<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;

/** Distinct canonical resources can share a physical table on exact connection objects. */
class PublicationResource extends Model
{
    /** @var array<class-string<self>, Connection> */
    public static array $sources = [];

    /** @var array<class-string<self>, string> */
    public static array $tables = [];

    protected $table = 'publication_records';

    public $timestamps = false;

    public function getConnection(): Connection
    {
        return self::$sources[static::class] ?? parent::getConnection();
    }

    public function getTable(): string
    {
        return self::$tables[static::class] ?? parent::getTable();
    }
}
