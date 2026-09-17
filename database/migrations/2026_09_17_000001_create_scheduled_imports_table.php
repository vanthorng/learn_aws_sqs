<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->ulid('invoice_import_id')->nullable();
            $table->string('status', 20)->default('scheduled')->index();
            $table->timestamp('scheduled_for')->index();
            $table->string('storage_disk');
            $table->string('storage_path');
            $table->string('original_filename');
            $table->string('file_hash', 64);
            $table->unsignedBigInteger('file_size');
            $table->boolean('auto_sync_qbo')->default(false);
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->foreign('invoice_import_id')
                ->references('id')
                ->on('invoice_imports')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_imports');
    }
};
