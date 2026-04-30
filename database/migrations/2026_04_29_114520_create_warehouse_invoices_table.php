<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWarehouseInvoicesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('warehouse_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freight_forwarder_id')->constrained('freight_forwarders');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->string('invoice_number')->nullable();
            $table->string('spk_name')->nullable();
            $table->string('spk_number')->nullable();
            $table->date('spk_date')->nullable();
            $table->string('po_number')->nullable();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->string('bank_name')->nullable();
            $table->string('swift_code', 50)->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number', 50)->nullable();
            $table->string('signatory_name')->nullable();
            $table->string('signatory_position')->nullable();
            $table->enum('status', ['DRAFT', 'PAID'])->default('DRAFT');
            $table->boolean('is_active')->default(true);
            $table->foreignId('generated_by')->constrained('users');
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
        Schema::dropIfExists('warehouse_invoices');
    }
}
