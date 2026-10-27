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
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->unique()->constrained()->nullOnDelete()
                ->comment('The message the turn wrote: the reply, or the notice that a manager takes over');
            $table->string('intent', 32)->nullable()->comment('What the agent took the buyer\'s message for');
            $table->jsonb('steps')->comment('The tools called, with their arguments, summaries and data');
            $table->jsonb('actions')->comment('What the agent asked Laravel to do: hand over, ask a manager');
            $table->boolean('verified')->comment('Whether the reply passed the verifier; false means the fallback reply');
            $table->jsonb('problems')->comment('What the verifier found in the last reply');
            $table->unsignedSmallInteger('attempts')->default(0)->comment('Replies written: the first and the rewrites after the verifier');
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->decimal('cost', 10, 4)->nullable()->comment('Cost of the model calls in rubles; empty for a local model');
            $table->unsignedInteger('duration_ms');
            $table->text('error')->nullable()->comment('Why the agent did not answer');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
