<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_imports', function (Blueprint $table) {
            $table->boolean('auto_sync_qbo')->default(false)->after('qbo_sync_status');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_imports', function (Blueprint $table) {
            $table->dropColumn('auto_sync_qbo');
        });
    }
};
