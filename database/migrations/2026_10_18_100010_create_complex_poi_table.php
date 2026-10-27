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
        Schema::create('complex_poi', function (Blueprint $table) {
            $table->foreignId('tenant_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('complex_id')->constrained()->cascadeOnDelete();
            $table->foreignId('poi_id')->index()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('distance_m');
            $table->unsignedSmallInteger('walk_min');

            $table->primary(['complex_id', 'poi_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('complex_poi');
    }
};
