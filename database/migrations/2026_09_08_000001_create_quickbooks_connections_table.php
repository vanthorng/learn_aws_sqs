<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quickbooks_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('realm_id')->index();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('access_token_expires_at');
            $table->timestamp('refresh_token_expires_at');
            $table->string('company_name')->nullable();
            $table->string('environment', 20)->default('sandbox');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quickbooks_connections');
    }
};
