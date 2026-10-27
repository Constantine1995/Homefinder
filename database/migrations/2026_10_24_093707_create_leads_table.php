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
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->unique()->constrained()->cascadeOnDelete()
                ->comment('The dialog the lead was collected in; its agent state is where the fields come from');
            $table->foreignId('contact_id')->nullable()->index()->constrained()->cascadeOnDelete()
                ->comment('The buyer; empty for a test conversation');
            $table->unsignedBigInteger('budget')->nullable()->comment('Rubles');
            $table->string('payment', 16)->nullable()->comment('mortgage, cash or installment');
            $table->jsonb('rooms')->comment('Room counts asked for: 0 a studio, 4 four and more');
            $table->unsignedSmallInteger('deadline_year')->nullable()->comment('Completion wanted no later than this year');
            $table->string('family')->nullable()->comment('Who will live in the flat, as the buyer put it');
            $table->boolean('sells_own_flat')->nullable();
            $table->boolean('wants_viewing')->default(false)->comment('The buyer asked for a viewing at least once');
            $table->string('score', 8)->comment('hot, warm or cold, by the rule in App\\Models\\Lead');
            $table->timestamps();

            $table->index(['tenant_id', 'score']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
