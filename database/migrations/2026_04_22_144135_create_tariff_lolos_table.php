<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTariffLolosTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('tariff_lolos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('yard_id')->constrained()->cascadeOnDelete();
            $table->foreignId('container_size_id')->constrained()->cascadeOnDelete();
            $table->foreignId('container_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cargo_status_id')->constrained()->cascadeOnDelete();
            $table->decimal('price_lift_off', 15, 2);
            $table->decimal('price_lift_on', 15, 2);
            $table->date('effective_date');
            $table->boolean('is_active')->default(true);
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
        Schema::dropIfExists('tariff_lolos');
    }
}
