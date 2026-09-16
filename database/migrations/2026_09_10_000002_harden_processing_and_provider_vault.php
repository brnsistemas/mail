<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_events', function (Blueprint $t) {
            $t->uuid('lease_token')->nullable();
        });
        Schema::table('mail_outbox', function (Blueprint $t) {
            $t->timestamp('enqueued_at')->nullable();
        });
        Schema::table('webhook_events', function (Blueprint $t) {
            $t->timestamp('enqueued_at')->nullable();
        });
        Schema::create('provider_settings', function (Blueprint $t) {
            $t->id();
            $t->text('api_key')->nullable();
            $t->text('webhook_secret')->nullable();
            $t->text('test_recipients')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_settings');
        Schema::table('webhook_events', fn (Blueprint $t) => $t->dropColumn(['lease_token', 'enqueued_at']));
        Schema::table('mail_outbox', fn (Blueprint $t) => $t->dropColumn('enqueued_at'));
    }
};
