<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('credential_version')->default(0);
        });
        Schema::table('mail_invites', function (Blueprint $table) {
            $table->foreignId('reactivation_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('reactivation_version')->nullable();
        });
    }

    public function down(): void
    {
        // Credential generations must survive an application rollback: otherwise
        // sessions revoked by a reactivation could become valid again.
        throw new RuntimeException('Preserve account reactivation state; roll back application code only.');
    }
};
