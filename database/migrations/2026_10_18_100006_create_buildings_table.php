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
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('complex_id')->constrained()->cascadeOnDelete();
            $table->string('external_id')->nullable()->comment('Building ID in the source feed');
            $table->string('name');
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->geography('geom', subtype: 'point')
                ->nullable()
                ->storedAs('ST_SetSRID(ST_MakePoint(longitude, latitude), 4326)::geography')
                ->comment('Derived from latitude and longitude');
            $table->unsignedSmallInteger('floors')->nullable();
            $table->date('deadline')->nullable()->comment('Planned completion date');
            $table->timestamps();

            $table->unique(['complex_id', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('buildings');
    }
};
