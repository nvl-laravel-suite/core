<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the shared adoption run and checkpoint tables on the migrated destination connection.
     */
    public function up(): void
    {
        Schema::create('nvl_core_adoption_runs', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->char('scope_hash', 64)->unique();
            $table->string('package', 64);
            $table->char('fingerprint', 64);
            $table->string('adapter_version', 64);
            $table->string('status', 16);
            $table->json('plan');
            $table->char('verification_digest', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->string('actor_type', 255);
            $table->string('actor_id', 255);
            $table->timestamps();

            $table->index(['package', 'status']);
        });

        Schema::create('nvl_core_adoption_checkpoints', static function (Blueprint $table): void {
            $table->uuid('run_id');
            $table->string('step', 100);
            $table->json('cursor');
            $table->unsignedBigInteger('processed')->default(0);
            $table->char('batch_digest', 64);
            $table->string('output_revision', 128)->nullable();

            $table->primary(['run_id', 'step']);
            $table->foreign('run_id')->references('id')->on('nvl_core_adoption_runs')->cascadeOnDelete();
        });
    }

    /**
     * Remove the shared adoption tables.
     */
    public function down(): void
    {
        Schema::dropIfExists('nvl_core_adoption_checkpoints');
        Schema::dropIfExists('nvl_core_adoption_runs');
    }
};
