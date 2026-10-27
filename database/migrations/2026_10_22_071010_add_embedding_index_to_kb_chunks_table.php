<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * HNSW with cosine distance, the `<=>` of search_knowledge: the nearest fragments without reading every vector
     * once a developer's knowledge base grows past a few thousand fragments.
     */
    public function up(): void
    {
        Schema::table('kb_chunks', function (Blueprint $table) {
            $table->vectorIndex('embedding');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kb_chunks', function (Blueprint $table) {
            $table->dropIndex(['embedding']);
        });
    }
};
