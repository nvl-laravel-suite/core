<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\ValueObjects;

use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;

/**
 * Preserves native owner identity independently from model subclasses and UUID tenancy.
 *
 * @api
 */
final readonly class OwnerReference
{
    /** Create a canonical scalar identity with an explicit native key kind. */
    public function __construct(
        public string $type,
        public string $storage,
        public string $keyType,
        public string $key,
        public int $version = 1,
    ) {
        foreach ([$type, $storage, $key] as $value) {
            if ($value === '' || strlen($value) > 191 || preg_match('//u', $value) !== 1 || str_contains($value, "\0")) {
                throw new OwnershipConfigurationInvalid('Owner identities require bounded nonempty UTF-8 fields.');
            }
        }
        if ($version !== 1 || ! in_array($keyType, ['int', 'string'], true)
            || ($keyType === 'int' && preg_match('/^(?:0|-?[1-9][0-9]*)$/D', $key) !== 1)) {
            throw new OwnershipConfigurationInvalid('Owner references require a supported version and canonical native key.');
        }
    }

    /** Derive an opaque partition identity without treating it as authorization. */
    public function scopeKey(): string
    {
        return 'owner:'.hash('sha256', json_encode(
            [(string) $this->version, $this->type, $this->storage, $this->keyType, $this->key],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /** @return array{version: int, type: string, storage: string, keyType: string, key: string} */
    public function toArray(): array
    {
        return ['version' => $this->version, 'type' => $this->type, 'storage' => $this->storage, 'keyType' => $this->keyType, 'key' => $this->key];
    }

    /** Decode the bounded closed JSON shape persisted by captured resources. */
    public static function fromJson(string $payload): self
    {
        if (strlen($payload) > 4096) {
            throw new OwnershipConfigurationInvalid('Owner reference JSON exceeds its bounded scalar shape.');
        }
        try {
            $decoded = json_decode($payload, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new OwnershipConfigurationInvalid('Owner reference JSON is malformed.');
        }
        if (! is_array($decoded)) {
            throw new OwnershipConfigurationInvalid('Owner reference JSON must contain a complete object.');
        }

        return self::fromArray($decoded);
    }

    /**
     * Decode only the complete supported scalar reference shape.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== ['key', 'keyType', 'storage', 'type', 'version']
            || ! is_int($payload['version']) || ! is_string($payload['type'])
            || ! is_string($payload['storage']) || ! is_string($payload['keyType']) || ! is_string($payload['key'])) {
            throw new OwnershipConfigurationInvalid('Owner reference payload is not a supported scalar shape.');
        }

        return new self($payload['type'], $payload['storage'], $payload['keyType'], $payload['key'], $payload['version']);
    }
}
