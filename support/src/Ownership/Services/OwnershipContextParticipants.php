<?php

declare(strict_types=1);

namespace Nvl\Support\Ownership\Services;

use Nvl\Support\Ownership\Contracts\OwnershipContextParticipant;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use ReflectionClass;

/**
 * Registers immutable participant class names without retaining scoped instances.
 *
 * @api
 */
final class OwnershipContextParticipants
{
    /** @var list<class-string<OwnershipContextParticipant>> */
    private array $participants = [];

    /**
     * Register an integration once in deterministic entry order.
     *
     * @param  class-string<OwnershipContextParticipant>  $participant
     */
    public function register(string $participant): void
    {
        $reflection = new ReflectionClass($participant);
        if (! $reflection->implementsInterface(OwnershipContextParticipant::class) || ! $reflection->isInstantiable()) {
            throw new OwnershipConfigurationInvalid('Context participants must implement OwnershipContextParticipant.');
        }
        if (! in_array($participant, $this->participants, true)) {
            $this->participants[] = $participant;
        }
    }

    /**
     * Return registered integration classes for resolution in the current scope.
     *
     * @internal
     *
     * @return list<class-string<OwnershipContextParticipant>>
     */
    public function all(): array
    {
        return $this->participants;
    }
}
