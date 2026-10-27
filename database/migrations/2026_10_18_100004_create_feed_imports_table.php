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
        Schema::create('feed_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('feed_source_id')->index()->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->unsignedInteger('added_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('removed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->jsonb('row_errors')->nullable()->comment('Rejected feed rows: [{"row": ..., "message": ...}]');
            $table->text('error_message')->nullable()->comment('Why the whole import failed');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feed_imports');
    }
};
