<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Nvl\Support\Auth\CanonicalActorResolver;
use Nvl\Support\Enums\SystemActor;

beforeEach(function (): void {
    Schema::create('core_canonical_actor_test_users', static function (Blueprint $table): void {
        $table->id();
        $table->boolean('can_manage')->default(false);
        $table->softDeletes();
    });
});

it('keeps explicit system abilities compatible with authorizable Gate hooks without creating a login identity', function (): void {
    $seen = [];
    Gate::before(static function (Authorizable $actor, string $ability) use (&$seen): ?bool {
        $seen[] = $actor;

        return $ability === 'core.system.blocked' ? false : null;
    });
    Gate::define('core.system.allowed', static fn (SystemActor $actor): bool => $actor === SystemActor::Background);
    Gate::define('core.system.blocked', static fn (SystemActor $actor): bool => true);
    $actor = SystemActor::Background;

    expect(Gate::forUser($actor)->allows('core.system.allowed'))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('core.system.undefined'))->toBeFalse()
        ->and(Gate::forUser($actor)->allows('core.system.blocked'))->toBeFalse()
        ->and($actor)->not->toBeInstanceOf(Authenticatable::class)
        ->and($actor->can('core.system.allowed'))->toBeTrue()
        ->and($actor->can('core.system.undefined'))->toBeFalse()
        ->and($seen)->toBe(array_fill(0, 5, $actor));
});

it('reloads Eloquent actor policy attributes and drops caller loaded relations', function (): void {
    $actor = CanonicalActorTestUser::query()->create(['can_manage' => false]);
    $actor->forceFill(['can_manage' => true])->setRelation('permissions', collect(['admin']));
    $canonical = app(CanonicalActorResolver::class)->resolve($actor);

    expect($canonical)->not->toBe($actor)
        ->and($canonical->can_manage)->toBeFalse()
        ->and($canonical->getRelations())->toBe([]);
});

it('rejects unavailable or forged Eloquent actor identity and storage', function (string $change): void {
    $actor = CanonicalActorTestUser::query()->create(['can_manage' => true]);
    match ($change) {
        'deleted' => $actor->delete(),
        'dirty key' => $actor->setAttribute('id', $actor->id + 1),
        'table' => $actor->setTable('another_table'),
        'detached' => $actor->exists = false,
    };
    expect(fn () => app(CanonicalActorResolver::class)->resolve($actor))->toThrow(AuthorizationException::class);
})->with(['deleted', 'dirty key', 'table', 'detached']);

it('retains valid external Authenticatable providers without inventing Eloquent persistence', function (): void {
    $actor = new GenericUser(['id' => 'directory-user']);
    $typedIdentity = new GenericUser(['id' => new class implements Stringable
    {
        public function __toString(): string
        {
            return 'provider-owned-identity';
        }
    }]);
    expect(app(CanonicalActorResolver::class)->resolve($actor))->toBe($actor)
        ->and(app(CanonicalActorResolver::class)->resolve($typedIdentity))->toBe($typedIdentity)
        ->and(fn () => app(CanonicalActorResolver::class)->resolve(new GenericUser(['id' => null])))->toThrow(AuthorizationException::class);
});

/** Native host actor model for the shared persistence contract. */
final class CanonicalActorTestUser extends User
{
    use SoftDeletes;

    protected $table = 'core_canonical_actor_test_users';

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['can_manage' => 'boolean'];
    }
}
