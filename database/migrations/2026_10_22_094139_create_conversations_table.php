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
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 32)->comment('Where the buyer writes from: test (the cabinet\'s test chat), later telegram and widget');
            $table->string('status', 16)->default('ai')->comment('Who answers: ai, human (a manager, the agent is silent) or closed');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete()
                ->comment('The manager who took the conversation');
            $table->string('handoff_reason')->nullable()->comment('Why the agent handed the conversation to a manager');
            $table->jsonb('dialog')->nullable()->comment('The agent\'s dialog state between turns: search filters, shown flats, the lead');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'last_message_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
