<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddPackageIdToTariffLolosTable extends Migration
{
    public function up()
    {
        Schema::table('tariff_lolos', function (Blueprint $table) {
            $table->foreignId('package_id')
                ->nullable()
                ->constrained('packages')
                ->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::table('tariff_lolos', function (Blueprint $table) {
            $table->dropForeign(['package_id']);
            $table->dropColumn('package_id');
        });
    }
}
