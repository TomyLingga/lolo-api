<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateRegistrationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('freight_forwarder_id')->constrained('freight_forwarders')->cascadeOnDelete();
            $table->string('container_number');
            $table->foreignId('container_size_id')->constrained();
            $table->foreignId('container_type_id')->constrained();
            $table->foreignId('cargo_status_id')->constrained();

            $table->string('no_do_jo')->nullable();
            $table->string('shipper_tenant')->nullable();

            $table->enum('record_status', ['OPEN', 'CLOSED'])->default('OPEN');
            $table->boolean('invoiced')->default(false);
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
        Schema::dropIfExists('registrations');
    }
}
