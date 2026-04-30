<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWarehouseBeritaAcarasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('warehouse_berita_acaras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freight_forwarder_id')->constrained('freight_forwarders');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->string('ba_number')->nullable();
            $table->date('ba_date');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->string('bank_name')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number', 50)->nullable();
            $table->string('signer_smnt_name')->nullable();
            $table->string('signer_smnt_position')->nullable();
            $table->string('signer_ff_name')->nullable();
            $table->string('signer_ff_position')->nullable();
            $table->string('approver_ff_name')->nullable();
            $table->string('approver_ff_position')->nullable();
            $table->boolean('invoiced')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users');
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
        Schema::dropIfExists('warehouse_berita_acaras');
    }
}
