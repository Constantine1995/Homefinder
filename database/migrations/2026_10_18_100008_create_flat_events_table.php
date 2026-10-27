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
        Schema::create('flat_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('flat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feed_import_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->string('field');
            $table->jsonb('old_value')->nullable()->comment('Empty when the flat first appeared');
            $table->jsonb('new_value')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['flat_id', 'created_at']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flat_events');
    }
};
