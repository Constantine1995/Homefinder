<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('kb_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('kb_document_id')->index()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->comment('Order of the chunk within its document');
            $table->string('section')->comment('Path of headings the chunk is under: «ЖК «Отрадный квартал» › Паркинг»');
            $table->text('text');
            $table->vector('embedding', dimensions: 1024)->nullable()->comment('bge-m3 embedding of the section and the text');
            $table->timestamps();
        });

        // Russian morphology: «паркинга» finds «паркинг». The section is searched too, as its headings name the topic.
        DB::statement("alter table kb_chunks add column search tsvector generated always as (to_tsvector('russian', section || ' ' || text)) stored");
        DB::statement('create index kb_chunks_search_index on kb_chunks using gin (search)');
        DB::statement("comment on column kb_chunks.search is 'Full-text search vector of the section and the text, Russian morphology'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kb_chunks');
    }
};
