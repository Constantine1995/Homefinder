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
        Schema::table('buildings', function (Blueprint $table) {
            $table->timestamp('geocoded_at')->nullable()->after('longitude')
                ->comment('When the address was geocoded, found or not; emptied when the address changes');
        });

        Schema::table('complexes', function (Blueprint $table) {
            $table->timestamp('pois_computed_at')->nullable()->after('longitude')
                ->comment('When the distances to nearby points of interest were computed; emptied when the complex moves');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->dropColumn('geocoded_at');
        });

        Schema::table('complexes', function (Blueprint $table) {
            $table->dropColumn('pois_computed_at');
        });
    }
};
