<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->string('storage_disk');
            $table->string('storage_path');
            $table->string('original_filename');
            $table->string('file_hash', 64);
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedTinyInteger('percentage')->default(0);
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_import_rows', function (Blueprint $table) {
            $table->id();
            $table->ulid('invoice_import_id');
            $table->unsignedInteger('source_row_number');
            $table->string('status', 20);
            $table->json('payload')->nullable();
            $table->json('errors')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
            $table->foreign('invoice_import_id')->references('id')->on('invoice_imports')->cascadeOnDelete();
            $table->unique(['invoice_import_id', 'source_row_number']);
        });

        Schema::create('invoice_records', function (Blueprint $table) {
            $table->id();
            $table->ulid('invoice_import_id');
            $table->unsignedInteger('source_row_number');
            $table->string('doc_number')->index();
            $table->string('customer')->index();
            $table->date('txn_date')->index();
            $table->string('line_item');
            $table->decimal('line_amount', 18, 4)->nullable();
            $table->json('payload');
            $table->timestamps();
            $table->foreign('invoice_import_id')->references('id')->on('invoice_imports')->cascadeOnDelete();
            $table->unique(['invoice_import_id', 'source_row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_records');
        Schema::dropIfExists('invoice_import_rows');
        Schema::dropIfExists('invoice_imports');
    }
};
