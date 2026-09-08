<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_imports', function (Blueprint $table) {
            $table->string('qbo_sync_status', 20)->default('not_synced')->after('status')->index();
            $table->unsignedInteger('qbo_synced_count')->default(0)->after('qbo_sync_status');
            $table->unsignedInteger('qbo_failed_count')->default(0)->after('qbo_synced_count');
            $table->timestamp('qbo_last_synced_at')->nullable()->after('qbo_failed_count');
            $table->text('qbo_sync_error')->nullable()->after('qbo_last_synced_at');
        });

        Schema::table('invoice_records', function (Blueprint $table) {
            $table->string('qbo_invoice_id')->nullable()->after('line_amount')->index();
            $table->timestamp('qbo_synced_at')->nullable()->after('qbo_invoice_id');
            $table->text('qbo_sync_error')->nullable()->after('qbo_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_records', function (Blueprint $table) {
            $table->dropColumn(['qbo_invoice_id', 'qbo_synced_at', 'qbo_sync_error']);
        });

        Schema::table('invoice_imports', function (Blueprint $table) {
            $table->dropColumn(['qbo_sync_status', 'qbo_synced_count', 'qbo_failed_count', 'qbo_last_synced_at', 'qbo_sync_error']);
        });
    }
};
