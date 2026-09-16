<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->unsignedInteger('scan_attempts')->default(0);
            $table->timestamp('scan_attempted_at')->nullable();
            $table->timestamp('scan_available_at')->nullable();
            $table->timestamp('scan_lease_until')->nullable();
            $table->uuid('scan_lease_token')->nullable();
            $table->index(['status', 'scan_available_at'], 'attachments_scan_due');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropIndex('attachments_scan_due');
            $table->dropColumn(['scan_attempts', 'scan_attempted_at', 'scan_available_at', 'scan_lease_until', 'scan_lease_token']);
        });
    }
};
