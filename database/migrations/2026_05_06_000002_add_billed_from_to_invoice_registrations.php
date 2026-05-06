<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_registrations', function (Blueprint $table) {
            // Menyimpan nilai last_invoiced_at sebelum invoice dibuat,
            // untuk keperluan rollback saat invoice dibatalkan.
            $table->timestamp('billed_from')->nullable()->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_registrations', function (Blueprint $table) {
            $table->dropColumn('billed_from');
        });
    }
};
