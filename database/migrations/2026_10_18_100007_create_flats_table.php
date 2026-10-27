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
        Schema::create('flats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('building_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('feed_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id')->nullable()->comment('Flat ID in the source feed');
            $table->string('number')->nullable();
            $table->smallInteger('floor');
            $table->unsignedSmallInteger('rooms')->comment('0 for studios');
            $table->boolean('is_euro')->default(false)->comment('Euro layout: the kitchen-living room is counted in rooms');
            $table->decimal('area', 8, 2);
            $table->decimal('kitchen_area', 8, 2)->nullable();
            $table->string('finishing')->nullable();
            $table->unsignedBigInteger('price')->comment('Rubles');
            $table->string('status');
            $table->string('plan_url', 2048)->nullable();
            $table->text('description')->nullable();
            $table->jsonb('features')->nullable()->comment('Window orientation, view and other attributes for soft-wish search');
            $table->vector('embedding', dimensions: 1024)->nullable()->comment('bge-m3 embedding of the description and features');
            $table->timestamps();

            $table->unique(['feed_source_id', 'external_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flats');
    }
};
