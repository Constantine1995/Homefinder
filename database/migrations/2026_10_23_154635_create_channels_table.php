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
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->cascadeOnDelete();
            $table->string('type', 32)->comment('telegram, later widget');
            $table->string('name')->comment('What the cabinet calls the channel: the bot\'s @username for Telegram');
            $table->string('external_id')->comment('The channel\'s own ID at its platform: the bot\'s user ID for Telegram');
            $table->text('credentials')->comment('Encrypted: the bot token and the secret Telegram sends with every webhook call');
            $table->jsonb('settings')->comment('What the channel says to buyers: the link to the privacy policy');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['type', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
