<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_invites', function (Blueprint $table) {
            $table->foreignId('mailbox_id')->nullable()->constrained('mailboxes')->restrictOnDelete();
            $table->string('mailbox_address', 254)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mail_invites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mailbox_id');
            $table->dropColumn('mailbox_address');
        });
    }
};
