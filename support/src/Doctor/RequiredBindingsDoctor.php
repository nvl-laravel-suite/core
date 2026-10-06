<?php

declare(strict_types=1);

namespace Nvl\Support\Doctor;

use Nvl\Support\Bindings\RequiredBindings;

/** Reports selected missing adapters without booting capabilities or executing factories.
 * @api
 */
final readonly class RequiredBindingsDoctor implements DoctorContributor
{
    public function __construct(private RequiredBindings $bindings) {}

    public function package(): string
    {
        return 'nvl/core';
    }

    /** @return iterable<DoctorCheck> */
    public function inspect(): iterable
    {
        $definitions = [];
        foreach ($this->bindings->all() as $definition) {
            $definitions[$definition->package.':'.$definition->contract] = $definition;
        }
        foreach ($this->bindings->inspect() as $status) {
            $definition = $definitions[$status->package.':'.$status->contract];
            $required = $definition->enabledWhen !== null && $status->status !== 'inactive';
            yield new DoctorCheck(
                'bindings.'.$status->package.'.'.$status->capability,
                $status->status === 'missing' && $required ? 'error' : 'info',
                $status->status !== 'missing' || ! $required,
                $status->message.' See '.$status->documentation.'.',
            );
        }
    }
}
