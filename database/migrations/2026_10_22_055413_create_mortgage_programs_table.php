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
        Schema::create('mortgage_programs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('bank')->nullable()->comment('Partner bank; empty for the developer\'s own installment plan');
            $table->decimal('rate', 5, 2)->comment('Annual interest rate, percent');
            $table->decimal('min_down_pct', 5, 2)->comment('Smallest down payment, percent of the price');
            $table->unsignedBigInteger('max_amount')->nullable()->comment('Largest loan, rubles; empty for no limit');
            $table->unsignedSmallInteger('max_years')->default(30)->comment('Longest term, years');
            $table->text('conditions')->nullable()->comment('Who may take it and on what terms, for buyers to read');
            $table->date('valid_to')->nullable()->comment('Last day the program is offered; empty for no end date');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mortgage_programs');
    }
};
