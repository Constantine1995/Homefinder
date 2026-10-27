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
        Schema::table('feed_imports', function (Blueprint $table) {
            $table->string('file_name')->nullable()->after('status')->comment('Name of the uploaded file; empty for feeds downloaded from the source URL');
            $table->string('file_path')->nullable()->after('file_name')->comment('Copy of the uploaded file on the local disk; emptied when the copy is deleted as an old one');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('feed_imports', function (Blueprint $table) {
            $table->dropColumn(['file_name', 'file_path']);
        });
    }
};
