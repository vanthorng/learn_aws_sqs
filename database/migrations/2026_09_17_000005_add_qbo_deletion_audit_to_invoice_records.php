<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_records', function (Blueprint $table) {
            $table->timestamp('qbo_deleted_at')->nullable()->after('qbo_voided_at');
            $table->text('qbo_delete_error')->nullable()->after('qbo_deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_records', function (Blueprint $table) {
            $table->dropColumn(['qbo_deleted_at', 'qbo_delete_error']);
        });
    }
};
