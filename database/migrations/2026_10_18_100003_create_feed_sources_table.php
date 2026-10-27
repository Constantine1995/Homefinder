<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('feed_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->string('url', 2048)->nullable()->comment('Empty for spreadsheets uploaded through the cabinet');
            $table->string('schedule')->nullable()->comment('Cron expression; empty means manual imports only');
            $table->boolean('is_active')->default(true);
            $table->string('last_status')->nullable();
            $table->timestamp('last_imported_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feed_sources');
    }
};
