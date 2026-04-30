<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWarehouseInvoiceTaxesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('warehouse_invoice_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_invoice_id')->constrained('warehouse_invoices')->cascadeOnDelete();
            $table->foreignId('tax_id')->constrained('taxes');
            $table->string('tax_name', 100);
            $table->decimal('tax_value', 10, 2);
            $table->enum('tax_value_type', ['PERCENTAGE', 'NOMINAL']);
            $table->enum('tax_type', ['ADD', 'DEDUCT']);
            $table->decimal('calculated_amount', 15, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('warehouse_invoice_taxes');
    }
}
