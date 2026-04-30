<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWarehouseRegistrationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('warehouse_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freight_forwarder_id')->constrained('freight_forwarders');
            $table->foreignId('chamber_id')->constrained('warehouse_chambers');
            $table->decimal('tariff_per_m2', 15, 2);
            $table->decimal('area_m2', 10, 2);
            $table->date('rent_start');
            $table->date('rent_end');
            $table->enum('record_status', ['ACTIVE', 'CLOSED'])->default('ACTIVE');
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
        Schema::dropIfExists('warehouse_registrations');
    }
}
