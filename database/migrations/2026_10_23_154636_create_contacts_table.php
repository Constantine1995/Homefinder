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
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('channel_user_id')->comment('Who the buyer is in the channel: the chat ID for Telegram');
            $table->string('name')->nullable()->comment('The name the buyer shows in the channel');
            $table->string('username')->nullable();
            $table->string('phone', 32)->nullable()->comment('Asked for only to book a viewing or hand the dialog to a manager');
            $table->timestamp('consent_at')->nullable()->comment('When the buyer agreed to the processing of personal data');
            $table->timestamps();

            $table->unique(['channel_id', 'channel_user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
