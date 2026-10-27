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
        Schema::create('kb_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('complex_id')->nullable()->index()->constrained()->nullOnDelete()
                ->comment('The complex the document is about; empty for the whole developer');
            $table->string('title');
            $table->string('source', 2048)->comment('Where the document comes from: a page URL or a file name; cited in answers');
            $table->text('content')->comment('The document as Markdown');
            $table->string('content_hash', 64)->comment('SHA-256 of the content, to skip unchanged documents on import');
            $table->unsignedSmallInteger('index_version')->comment('Version of the chunking the chunks were made with');
            $table->timestamps();

            $table->unique(['tenant_id', 'source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kb_documents');
    }
};
