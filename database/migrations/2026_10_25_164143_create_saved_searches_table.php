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
        Schema::create('saved_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete()
                ->comment('The dialog the buyer subscribed in; a test dialog gets the news in it');
            $table->foreignId('contact_id')->nullable()->index()->constrained()->cascadeOnDelete()
                ->comment('The buyer; empty for a test conversation');
            $table->string('description')->comment('The conditions in the buyer\'s words, as the agent put them');
            $table->jsonb('filters')->comment('The agent\'s search conditions');
            $table->boolean('active')->default(true)->comment('False once the buyer unsubscribed');
            $table->timestamp('last_notified_at')->nullable()->comment('Flats changed after it, or after the subscription, are news');
            $table->timestamps();

            $table->index(['tenant_id', 'active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saved_searches');
    }
};
