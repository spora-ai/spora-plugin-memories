<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(): void
    {
        // Idempotent guard: hosts upgrading from spora-core ≤ 0.11.x already shipped
        // the `memories` table from core's `0049_create_memories_table.php`. Reusing
        // the same name + the same FK targets means the table structure is identical,
        // so we just skip the create when the table is already present. Uninstall+reinstall
        // cycles are also no-ops here.
        if (Capsule::schema()->hasTable('memories')) {
            return;
        }

        Capsule::schema()->create('memories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->string('name');
            $table->string('summary', 500)->nullable();
            $table->longText('content')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('agent_id')->references('id')->on('agents')->onDelete('cascade');
            $table->unique(['agent_id', 'name']);
            $table->index(['user_id', 'name']);
            $table->index(['agent_id', 'order']);
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('memories');
    }
};
