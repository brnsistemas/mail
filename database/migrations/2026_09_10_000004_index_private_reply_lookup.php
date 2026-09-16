<?php

use App\Models\Message;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->char('rfc_digest', 64)->nullable();
            $table->index(['mailbox_id', 'rfc_digest']);
        });
        Message::whereNotNull('rfc_message_id')->chunkById(100, function ($messages) {
            foreach ($messages as $message) {
                $message->update(['rfc_digest' => Message::rfcDigest($message->mailbox_id, $message->rfc_message_id)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['mailbox_id', 'rfc_digest']);
            $table->dropColumn('rfc_digest');
        });
    }
};
