<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPackageIdToRegistrationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->foreignId('package_id')
                ->nullable()
                ->constrained('packages')
                ->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropForeign(['package_id']);
            $table->dropColumn('package_id');
        });
    }
}
