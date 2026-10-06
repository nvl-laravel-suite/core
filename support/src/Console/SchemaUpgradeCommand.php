<?php

declare(strict_types=1);

namespace Nvl\Support\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Nvl\Support\Schema\SchemaUpgrade;
use Throwable;

/** Applies an explicit, verified schema identity upgrade without touching unrelated storage. */
final class SchemaUpgradeCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:schema:upgrade {--package=* : Explicit package slugs} {--claim-legacy : Assert ownership of verified legacy storage} {--migration-owner=vendor : vendor or published sole migration owner} {--dry-run : Validate and display without changing storage} {--format=text : text or json}';

    /** @var string */
    protected $description = 'Validate and rename released NVL table and migration identities';

    /** Validate the full plan, then apply it only outside dry-run mode. */
    public function handle(SchemaUpgrade $upgrade): int
    {
        try {
            if (! in_array($this->option('format'), ['text', 'json'], true)) {
                throw new InvalidArgumentException('The format must be text or json.');
            }
            $packages = $this->option('package');
            $selected = [];
            foreach ($packages as $package) {
                if (! is_string($package) || $package === '') {
                    throw new InvalidArgumentException('Each --package must name a package slug.');
                }
                $selected[] = $package;
            }
            $owner = $this->option('migration-owner');
            if (! is_string($owner)) {
                throw new InvalidArgumentException('The migration owner must be vendor or published.');
            }
            $plan = $upgrade->plan($selected, $this->option('claim-legacy') === true, $owner);
            if ($this->option('dry-run') !== true) {
                $upgrade->execute($plan);
            }
            if ($this->option('format') === 'json') {
                $this->line(json_encode(['dry_run' => $this->option('dry-run') === true, ...$plan], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            } else {
                $this->table(['Kind', 'Package', 'From', 'To'], array_map(static fn (array $step): array => [$step['kind'], $step['package'], $step['from'], $step['to']], $plan['steps']));
                $this->table(['File', 'Owner action'], array_map(static fn (array $file): array => [$file['path'], $file['action']], $plan['files']));
                foreach ($plan['warnings'] as $warning) {
                    $this->warn($warning);
                }
                $this->info($this->option('dry-run') === true ? 'Dry run validated; no storage changed.' : 'Schema upgrade completed.');
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('format') === 'json') {
                $this->line(json_encode(['schema_version' => 1, 'error' => $exception->getMessage()], JSON_THROW_ON_ERROR));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }
}
