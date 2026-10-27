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
        Schema::create('pois', function (Blueprint $table) {
            $table->id();
            $table->string('osm_type');
            $table->unsignedBigInteger('osm_id');
            $table->string('type');
            $table->string('name')->nullable();
            $table->geography('geom')->comment('Point, or area for parks');
            $table->jsonb('tags')->nullable()->comment('Original OpenStreetMap tags');
            $table->timestamps();

            $table->unique(['osm_type', 'osm_id']);
            $table->spatialIndex('geom');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pois');
    }
};
