<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_imports', function (Blueprint $table) {
            $table->timestamp('qbo_sync_started_at')->nullable()->after('qbo_sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_imports', function (Blueprint $table) {
            $table->dropColumn('qbo_sync_started_at');
        });
    }
};
