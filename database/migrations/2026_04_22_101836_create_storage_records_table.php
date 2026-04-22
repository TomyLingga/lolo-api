<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStorageRecordsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('storage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cargo_status_id')->constrained();
            $table->foreignId('yard_id')->constrained();
            $table->foreignId('block_id')->constrained();
            $table->foreignId('moved_by')->constrained('users');

            $table->integer('pos_length')->nullable();
            $table->integer('pos_width')->nullable();
            $table->integer('pos_height')->nullable();

            $table->timestamp('moved_at')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            $table->decimal('storage_price_per_day', 15, 2)->default(0);
            $table->integer('total_storage_days')->default(0);
            $table->decimal('total_storage_cost', 15, 2)->default(0);

            $table->text('note')->nullable();
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
        Schema::dropIfExists('storage_records');
    }
}
