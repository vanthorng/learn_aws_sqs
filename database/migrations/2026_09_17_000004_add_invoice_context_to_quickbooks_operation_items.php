<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quickbooks_operation_items', function (Blueprint $table) {
            $table->string('doc_number')->nullable()->after('qbo_invoice_id');
            $table->string('customer')->nullable()->after('doc_number');
        });
    }

    public function down(): void
    {
        Schema::table('quickbooks_operation_items', function (Blueprint $table) {
            $table->dropColumn(['doc_number', 'customer']);
        });
    }
};
