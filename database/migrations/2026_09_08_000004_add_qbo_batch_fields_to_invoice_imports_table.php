<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_imports', function (Blueprint $table) {
            $table->unsignedInteger('qbo_total_invoices')->default(0)->after('qbo_failed_count');
            $table->string('qbo_current_message', 500)->nullable()->after('qbo_total_invoices');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_imports', function (Blueprint $table) {
            $table->dropColumn(['qbo_total_invoices', 'qbo_current_message']);
        });
    }
};
