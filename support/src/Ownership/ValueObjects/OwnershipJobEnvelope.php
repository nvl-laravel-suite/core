<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\ValueObjects;

use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/**
 * Carries immutable scalar admission facts without granting worker authority.
 *
 * @api
 *
 * @phpstan-type Wire array{version: 1|2, mode: string, owner: array{version: int, type: string, storage: string, keyType: string, key: string}|null, principal: array{kind: 'actor'|'system', type: string, id: string}|null, operation: string|null, generations: array<string, string>}
 */
final readonly class OwnershipJobEnvelope
{
    /** @param Wire $data */
    private function __construct(public array $data) {}

    /** Capture before native scheduling, uniqueness, batch persistence or response deferral. */
    public static function capture(OwnershipContext $context): self
    {
        return self::fromSnapshot($context->snapshot());
    }

    /** Copy admitted state without retaining any actor, owner model, or context service. */
    public static function fromSnapshot(OwnershipSnapshot $snapshot): self
    {
        if (($snapshot->principal['kind'] ?? null) === 'public') {
            throw new OwnershipViolation('Public site admission cannot be promoted into queued actor or system authority.');
        }

        return self::fromArray(['version' => 1, 'mode' => $snapshot->mode->value, 'owner' => $snapshot->owner?->toArray(), 'principal' => $snapshot->principal, 'operation' => $snapshot->operation, 'generations' => $snapshot->generations]);
    }

    /**
     * Explicitly capture zero authority for global work in an owned installation.
     *
     * @internal
     *
     * @param  array<string,string>  $generations
     */
    public static function unowned(array $generations): self
    {
        return self::fromArray(['version' => 2, 'mode' => OwnershipContextMode::Unresolved->value, 'owner' => null, 'principal' => null, 'operation' => null, 'generations' => $generations]);
    }

    /** Validate a closed scalar wire contract before any native object restoration.
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (count($data) !== 6 || ! in_array($data['version'] ?? null, [1, 2], true) || ! is_string($data['mode'] ?? null)
            || ! array_key_exists('owner', $data) || ! array_key_exists('principal', $data)
            || ! array_key_exists('operation', $data) || ! is_array($data['generations'] ?? null)) {
            throw new OwnershipViolation('The ownership queue envelope is malformed or unsupported.');
        }
        $mode = OwnershipContextMode::tryFrom($data['mode']);
        if (($data['version'] === 2 && $mode !== OwnershipContextMode::Unresolved)
            || ($data['version'] === 1 && ! in_array($mode, [OwnershipContextMode::Disabled, OwnershipContextMode::Owner, OwnershipContextMode::Central], true))) {
            throw new OwnershipViolation('Unresolved authority cannot be captured into queued work.');
        }
        $owner = $data['owner'] === null ? null : (is_array($data['owner']) ? OwnerReference::fromArray($data['owner']) : throw new OwnershipViolation);
        $principal = self::principal($data['principal']);
        $operation = $data['operation'];
        if ($operation !== null && ! self::bounded($operation)) {
            throw new OwnershipViolation;
        }
        $generations = [];
        foreach ($data['generations'] as $source => $generation) {
            if (! self::bounded($source) || ! self::bounded($generation)) {
                throw new OwnershipViolation;
            }
            $generations[$source] = $generation;
        }
        ksort($generations);
        if (($mode === OwnershipContextMode::Owner) !== ($owner !== null)
            || (in_array($mode, [OwnershipContextMode::Owner, OwnershipContextMode::Central], true) !== ($principal !== null))
            || ($mode === OwnershipContextMode::Disabled && ($operation !== null || $generations !== []))
            || ($mode === OwnershipContextMode::Unresolved && $operation !== null)
            || ($mode === OwnershipContextMode::Central && ($operation === null || ($principal['kind'] ?? null) !== 'actor'))
            || (($principal['kind'] ?? null) === 'system' && ($operation === null || $principal['type'] !== 'operation' || $principal['id'] !== $operation))
            || ($mode === OwnershipContextMode::Owner && ($principal['kind'] ?? null) === 'actor' && $operation !== null)) {
            throw new OwnershipViolation('Queued classification and admission facts are inconsistent.');
        }

        return new self(['version' => $data['version'], 'mode' => $mode->value, 'owner' => $owner?->toArray(), 'principal' => $principal, 'operation' => $operation, 'generations' => $generations]);
    }

    /** @return Wire */
    public function toArray(): array
    {
        return $this->data;
    }

    /** Reconstruct only neutral value objects after scalar validation. */
    public function context(): OwnershipSnapshot
    {
        return new OwnershipSnapshot(OwnershipContextMode::from($this->data['mode']), $this->data['owner'] === null ? null : OwnerReference::fromArray($this->data['owner']), $this->data['principal'], $this->data['operation'], $this->data['generations']);
    }

    /** Namespace native uniqueness and overlap identities by the complete captured partition. */
    public function key(string $identity): string
    {
        if ($this->data['mode'] === OwnershipContextMode::Disabled->value) {
            return $identity;
        }

        return 'nvl:ownership:'.hash('sha256', json_encode([$this->data['mode'], $this->data['owner'], $this->data['operation'], $this->data['generations'], $identity], JSON_THROW_ON_ERROR));
    }

    /** @phpstan-assert-if-true non-empty-string $value */
    private static function bounded(mixed $value): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= 191 && mb_check_encoding($value, 'UTF-8') && ! str_contains($value, "\0");
    }

    /** @return array{kind: 'actor'|'system', type: string, id: string}|null */
    private static function principal(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value) || count($value) !== 3) {
            throw new OwnershipViolation;
        }
        $kind = $value['kind'] ?? null;
        $type = $value['type'] ?? null;
        $id = $value['id'] ?? null;
        if (! in_array($kind, ['actor', 'system'], true) || ! self::bounded($type) || ! self::bounded($id)) {
            throw new OwnershipViolation('Queued principals require their complete native scalar identity.');
        }

        return ['kind' => $kind, 'type' => $type, 'id' => $id];
    }
}
