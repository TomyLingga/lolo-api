<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWarehouseBaRegistrationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('warehouse_ba_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ba_id')->constrained('warehouse_berita_acaras')->cascadeOnDelete();
            $table->foreignId('warehouse_registration_id')->constrained('warehouse_registrations');
            $table->string('chamber_name', 100);
            $table->decimal('chamber_length_m', 8, 2)->nullable();
            $table->decimal('chamber_width_m', 8, 2)->nullable();
            $table->decimal('area_m2', 10, 2);
            $table->decimal('tariff_per_m2', 15, 2);
            $table->date('rent_start');
            $table->date('rent_end');
            $table->decimal('subtotal', 15, 2);
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
        Schema::dropIfExists('warehouse_ba_registrations');
    }
}
