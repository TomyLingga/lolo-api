<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWarehouseInvoiceBasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('warehouse_invoice_bas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_invoice_id')->constrained('warehouse_invoices')->cascadeOnDelete();
            $table->foreignId('ba_id')->constrained('warehouse_berita_acaras');
            $table->decimal('ba_subtotal', 15, 2);
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
        Schema::dropIfExists('warehouse_invoice_bas');
    }
}
