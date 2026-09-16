<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('active')->default(true);
            $t->boolean('master')->default(false);
            $t->text('totp_secret')->nullable();
            $t->timestamp('totp_confirmed_at')->nullable();
            $t->unsignedBigInteger('totp_last_step')->nullable();
            $t->text('recovery_hashes')->nullable();
            $t->unsignedInteger('security_version')->default(1);
        });
        Schema::create('organizations', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('role', 20)->default('member');
            $t->boolean('active')->default(true);
            $t->unique(['organization_id', 'user_id']);
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->timestamps();
        });
        Schema::create('mail_domains', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('domain')->unique();
            $t->string('status', 25)->default('pending');
            $t->string('provider_id')->nullable();
            $t->timestamps();
        });
        Schema::create('mailboxes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mail_domain_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('address')->unique();
            $t->boolean('sensitive')->default(false);
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('mailbox_grants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->boolean('can_read')->default(false);
            $t->boolean('can_send')->default(false);
            $t->boolean('can_manage')->default(false);
            $t->boolean('view_sensitive')->default(false);
            $t->unique(['mailbox_id', 'user_id']);
            $t->timestamps();
        });
        Schema::create('mail_aliases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            $t->string('address')->unique();
            $t->timestamps();
        });
        Schema::create('messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            $t->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->uuid('thread_id')->index();
            $t->string('direction', 10);
            $t->string('folder', 20)->default('inbox');
            $t->string('status', 30)->default('received');
            $t->text('subject');
            $t->text('sender');
            $t->longText('recipients');
            $t->longText('body_text');
            $t->longText('body_html')->nullable();
            $t->text('reply_to')->nullable();
            $t->text('rfc_message_id')->nullable();
            $t->text('in_reply_to')->nullable();
            $t->uuid('provider_id')->nullable();
            $t->timestamp('provider_event_at')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('read_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
            $t->unique(['mailbox_id', 'provider_id']);
            $t->index(['mailbox_id', 'folder', 'created_at']);
        });
        Schema::create('message_search', function (Blueprint $t) {
            $t->id();
            $t->uuid('message_id');
            $t->foreign('message_id')->references('id')->on('messages')->cascadeOnDelete();
            $t->char('digest', 64);
            $t->unique(['message_id', 'digest']);
            $t->index('digest');
        });
        Schema::create('attachments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('message_id');
            $t->foreign('message_id')->references('id')->on('messages')->cascadeOnDelete();
            $t->text('filename');
            $t->string('mime');
            $t->unsignedBigInteger('size');
            $t->string('path')->nullable();
            $t->char('sha256', 64)->nullable();
            $t->string('status', 25)->default('quarantine');
            $t->string('reason', 60)->nullable();
            $t->string('provider_id')->nullable();
            $t->timestamps();
        });
        Schema::create('mail_outbox', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('message_id')->unique();
            $t->foreign('message_id')->references('id')->on('messages')->cascadeOnDelete();
            $t->foreignId('actor_id')->constrained('users');
            $t->string('status', 25)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('available_at')->nullable()->index();
            $t->timestamp('lease_until')->nullable();
            $t->timestamp('first_attempt_at')->nullable();
            $t->uuid('lease_token')->nullable();
            $t->string('last_error', 60)->nullable();
            $t->timestamps();
            $t->index(['status', 'available_at']);
        });
        Schema::create('webhook_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('event_id')->unique();
            $t->char('body_hash', 64);
            $t->string('type', 50);
            $t->uuid('email_id')->nullable();
            $t->longText('payload');
            $t->string('status', 25)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('available_at')->nullable();
            $t->timestamp('lease_until')->nullable();
            $t->string('last_error', 60)->nullable();
            $t->timestamps();
            $t->index(['status', 'available_at']);
        });
        Schema::create('mail_suppressions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            $t->char('recipient_hash', 64);
            $t->string('reason', 40);
            $t->unique(['mailbox_id', 'recipient_hash']);
            $t->timestamps();
        });
        Schema::create('mail_audits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedBigInteger('mailbox_id')->nullable()->index();
            $t->string('action', 60);
            $t->string('resource_id', 64)->nullable();
            $t->timestamps();
        });
        Schema::create('mail_invites', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('organization_id')->constrained();
            $t->string('email');
            $t->char('token_hash', 64)->unique();
            $t->string('role', 20)->default('member');
            $t->timestamp('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['mail_invites', 'mail_audits', 'mail_suppressions', 'webhook_events', 'mail_outbox', 'attachments', 'message_search', 'messages', 'mail_aliases', 'mailbox_grants', 'mailboxes', 'mail_domains', 'products', 'memberships', 'organizations'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['active', 'master', 'totp_secret', 'totp_confirmed_at', 'totp_last_step', 'recovery_hashes', 'security_version']));
    }
};
