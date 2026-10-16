<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('quickbooks_operations')) {
            Schema::create('quickbooks_operations', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->foreignId('team_id')->constrained()->cascadeOnDelete();
                $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
                $table->string('type', 20)->index();
                $table->string('status', 20)->default('queued')->index();
                $table->json('filters')->nullable();
                $table->unsignedInteger('total_count')->default(0);
                $table->unsignedInteger('processed_count')->default(0);
                $table->unsignedInteger('success_count')->default(0);
                $table->unsignedInteger('failed_count')->default(0);
                $table->unsignedTinyInteger('percentage')->default(0);
                $table->string('storage_disk')->nullable();
                $table->string('storage_path')->nullable();
                $table->string('download_filename')->nullable();
                $table->text('error_summary')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('quickbooks_operation_items')) {
            Schema::create('quickbooks_operation_items', function (Blueprint $table) {
                $table->id();
                $table->ulid('quickbooks_operation_id');
                $table->string('qbo_invoice_id')->index();
                $table->string('status', 20)->default('queued');
                $table->text('error_summary')->nullable();
                $table->timestamps();
                $table->foreign('quickbooks_operation_id')->references('id')->on('quickbooks_operations')->cascadeOnDelete();
                $table->unique(['quickbooks_operation_id', 'qbo_invoice_id'], 'qbo_op_item_invoice_unique');
            });
        } elseif (! Schema::hasIndex('quickbooks_operation_items', 'qbo_op_item_invoice_unique')) {
            Schema::table('quickbooks_operation_items', function (Blueprint $table) {
                $table->unique(['quickbooks_operation_id', 'qbo_invoice_id'], 'qbo_op_item_invoice_unique');
            });
        }

        Schema::table('invoice_records', function (Blueprint $table) {
            $table->timestamp('qbo_voided_at')->nullable()->after('qbo_synced_at');
            $table->text('qbo_void_error')->nullable()->after('qbo_voided_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_records', function (Blueprint $table) {
            $table->dropColumn(['qbo_voided_at', 'qbo_void_error']);
        });
        Schema::dropIfExists('quickbooks_operation_items');
        Schema::dropIfExists('quickbooks_operations');
    }
};
