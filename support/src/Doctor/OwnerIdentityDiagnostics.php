<?php

declare(strict_types=1);

namespace Nvl\Support\Doctor;

use Composer\InstalledVersions;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Schema\SchemaIdentities;
use Throwable;

/** Inventories retained NVL morph types only during an explicit diagnostic. */
final readonly class OwnerIdentityDiagnostics
{
    /** @var array<string, array<string, list<string>>> Persisted native model identities; Auth principals and non-morph scope labels use separate boundaries. */
    private const array OWNER_COLUMNS = [
        'activity' => ['log' => ['subject_type', 'causer_type']],
        'comments' => ['comments' => ['commentable_type', 'actor_type', 'moderated_by_type', 'deleted_by_type', 'restored_by_type', 'anonymized_by_type'], 'reactions' => ['actor_type'], 'reports' => ['reporter_type', 'reviewed_by_type'], 'revisions' => ['edited_by_type']],
        'content' => ['placements' => ['owner_type'], 'blocks' => ['created_by_type', 'updated_by_type', 'published_by_type'], 'revisions' => ['actor_type']],
        'mail-notifications' => ['notifications' => ['notifiable_type'], 'scheduled_messages' => ['notifiable_type']],
        'media' => ['associations' => ['associable_type'], 'media' => ['uploaded_by_type'], 'multipart_uploads' => ['uploader_type'], 'owner_slot_operations' => ['owner_type', 'actor_type']],
        'metafields' => ['metafields' => ['metafieldable_type']],
        'seo' => ['profiles' => ['seoable_type']],
        'tasks' => ['tasks' => ['creator_type'], 'assignments' => ['assignee_type', 'assigned_by_type'], 'time_entries' => ['performer_type'], 'checklist_items' => ['completed_by_type']],
        'taxonomy' => ['termables' => ['termable_type']],
        'templates' => ['assignments' => ['owner_type'], 'versions' => ['published_by_type'], 'renders' => ['requested_by_type']],
    ];

    /** @var array<string, array<string, array<string, string>>> Actor columns admitting the package's system/null sentinel and non-Eloquent authenticated principals. */
    private const array ACTOR_COLUMNS = [
        'comments' => ['comments' => ['actor_type' => 'actor_id', 'moderated_by_type' => 'moderated_by_id', 'deleted_by_type' => 'deleted_by_id', 'restored_by_type' => 'restored_by_id', 'anonymized_by_type' => 'anonymized_by_id'], 'reactions' => ['actor_type' => 'actor_id'], 'reports' => ['reporter_type' => 'reporter_id', 'reviewed_by_type' => 'reviewed_by_id'], 'revisions' => ['edited_by_type' => 'edited_by_id']],
        'content' => ['blocks' => ['created_by_type' => 'created_by_id', 'updated_by_type' => 'updated_by_id', 'published_by_type' => 'published_by_id'], 'revisions' => ['actor_type' => 'actor_id']],
        'media' => ['media' => ['uploaded_by_type' => 'uploaded_by'], 'multipart_uploads' => ['uploader_type' => 'uploader_id'], 'owner_slot_operations' => ['actor_type' => 'actor_id']],
        'templates' => ['versions' => ['published_by_type' => 'published_by'], 'renders' => ['requested_by_type' => 'requested_by']],
    ];

    /** Retain capability and database boundaries without opening connections at boot. */
    public function __construct(private OwnerRegistry $owners, private DatabaseManager $database) {}

    /** @return list<DoctorCheck> Historical identity mismatches without data mutation */
    public function inspect(): array
    {
        $this->owners->all();
        $checks = [];
        foreach (SchemaIdentities::all() as $package => $definition) {
            if (! InstalledVersions::isInstalled('nvl/'.$package)) {
                continue;
            }
            foreach ($definition['tables'] as $key => $table) {
                $ownerTypes = self::OWNER_COLUMNS[$package][$key] ?? [];
                if ($ownerTypes === []) {
                    continue;
                }
                try {
                    $connection = $this->database->connection(PackageStorage::tableConnection($package, $key));
                    $name = PackageStorage::table($package, $key, $table['default']);
                    $schema = $connection->getSchemaBuilder();
                    if (! $schema->hasTable($name)) {
                        continue;
                    }
                    foreach ($ownerTypes as $column) {
                        if (! $schema->hasColumn($name, $column)) {
                            continue;
                        }
                        $query = $connection->table($name)->whereNotNull($column);
                        $actorId = self::ACTOR_COLUMNS[$package][$key][$column] ?? null;
                        if ($actorId !== null && $schema->hasColumn($name, $actorId)) {
                            $query->where(static function (Builder $types) use ($column, $actorId): void {
                                $types->where($column, '<>', 'system')->orWhereNotNull($actorId);
                            });
                        }
                        foreach ($query->distinct()->pluck($column) as $stored) {
                            if (! is_string($stored)) {
                                continue;
                            }
                            $reference = Relation::getMorphedModel($stored) ?? $stored;
                            if ($actorId !== null && $reference === $stored
                                && ! is_a($reference, Model::class, true)
                                && is_a($reference, Authenticatable::class, true)) {
                                continue;
                            }
                            try {
                                $model = $this->owners->model($reference);
                            } catch (InvalidArgumentException) {
                                $model = is_a($reference, Model::class, true) ? $reference : null;
                            }
                            $current = $model !== null ? (new $model)->getMorphClass() : null;
                            if ($current === $stored) {
                                continue;
                            }
                            $connectionName = $connection->getName();
                            $message = "Package [{$package}] connection [{$connectionName}] table [{$name}] column [{$column}] retains owner type [{$stored}]";
                            $message .= $current === null
                                ? ' without a declared current owner. Declare its class/read bridge and reconcile retained data explicitly.'
                                : " while Laravel writes [{$current}]. Reconcile retained data explicitly; Doctor has changed no rows.";
                            $checks[] = new DoctorCheck('owners.rows.'.substr(hash('sha256', "{$package}:{$connectionName}:{$name}:{$column}:{$stored}"), 0, 20), 'error', false, $message);
                        }
                    }
                } catch (Throwable $exception) {
                    $checks[] = new DoctorCheck('owners.storage.'.$package.'.'.$key, 'error', false,
                        "Owner inventory for [{$package}.{$key}] could not be read: ".$exception->getMessage());
                }
            }
        }

        return $checks;
    }
}
