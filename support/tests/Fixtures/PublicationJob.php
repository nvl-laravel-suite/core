<?php

declare(strict_types=1);

namespace Nvl\Support\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Traits\CapturesOwnership;

/** Publishes actual native captured payloads and records real sync/worker execution. */
final class PublicationJob implements OwnershipQueuedJob, ShouldQueue
{
    use CapturesOwnership;
    use Queueable;

    public function __construct(public readonly string $identity)
    {
        $this->captureOwnership();
    }

    public function handle(): void
    {
        DB::table('publication_events')->insert(['identity' => $this->identity, 'mode' => app(OwnershipContext::class)->snapshot()->mode->value]);
    }
}
