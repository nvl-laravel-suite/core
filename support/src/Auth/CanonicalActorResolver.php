<?php

declare(strict_types=1);

namespace Nvl\Support\Auth;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Supplies fresh canonical Eloquent actor facts before a host policy decides access.
 *
 * Non-Eloquent identity providers retain their own freshness contract. This resolver
 * retains their provider-owned identity without claiming to reload external persistence.
 * It does not infer privileges, active columns, or authorization from an identity.
 *
 * @api
 */
final readonly class CanonicalActorResolver
{
    /** Reload declared Eloquent storage, retaining normal scopes and discarding caller relations/attributes. */
    public function resolve(Authenticatable $actor, bool $lock = false): Authenticatable
    {
        $identifier = $actor->getAuthIdentifier();
        if ($identifier === null) {
            throw new AuthorizationException('An actor identity is required.');
        }
        if (! $actor instanceof Model) {
            return $actor;
        }
        $class = $actor::class;
        $prototype = new $class;
        $key = $actor->getRawOriginal($prototype->getKeyName());
        $currentKey = $actor->getKey();
        if (! $actor->exists || (! is_string($key) && ! is_int($key)) || (string) $key === ''
            || (! is_string($currentKey) && ! is_int($currentKey))
            || $actor->getTable() !== $prototype->getTable()
            || $actor->getConnection() !== $prototype->getConnection()
            || $actor->getKeyName() !== $prototype->getKeyName()
            || $actor->getKeyType() !== $prototype->getKeyType()
            || $actor->isDirty($prototype->getKeyName())
            || (string) $currentKey !== (string) $key) {
            throw new AuthorizationException('A persisted canonical actor is required.');
        }
        $query = $prototype->newQuery()->useWritePdo()->whereKey($key);
        if ($lock) {
            $query->lockForUpdate();
        }
        $canonical = $query->first();
        $canonicalKey = $canonical?->getKey();
        $canonicalIdentifier = $canonical instanceof Authenticatable ? $canonical->getAuthIdentifier() : null;
        if (! $canonical instanceof Authenticatable || $canonical::class !== $class
            || (! is_string($canonicalKey) && ! is_int($canonicalKey))
            || (string) $canonicalKey !== (string) $key
            || $canonicalIdentifier === null) {
            throw new AuthorizationException('The actor is unavailable.');
        }

        return $canonical;
    }
}
