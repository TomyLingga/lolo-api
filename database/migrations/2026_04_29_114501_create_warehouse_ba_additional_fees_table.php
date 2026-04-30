<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWarehouseBaAdditionalFeesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('warehouse_ba_additional_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ba_id')->constrained('warehouse_berita_acaras')->cascadeOnDelete();
            $table->text('fee_name');
            $table->decimal('fee_amount', 15, 2);
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
        Schema::dropIfExists('warehouse_ba_additional_fees');
    }
}
