<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\MailController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/mail');
Route::get('/login', fn () => view('auth.login'))->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:15,1');
Route::get('/invite/{id}', [AdminController::class, 'showInvite'])->whereUuid('id');
Route::post('/invite/{id}/open', [AdminController::class, 'openInvite'])->whereUuid('id')->middleware('throttle:10,1');
Route::post('/invite/{id}', [AdminController::class, 'accept'])->whereUuid('id')->middleware('throttle:5,1');
Route::post('/webhooks/resend', WebhookController::class)->middleware('throttle:120,1');
Route::middleware(['auth'])->group(function () {
    Route::get('/two-factor', [AuthController::class, 'factor']);
    Route::post('/two-factor', [AuthController::class, 'verify'])->middleware('throttle:6,1');
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::middleware(['mfa'])->group(function () {
        Route::get('/mail', [MailController::class, 'index']);
        Route::get('/security', fn () => view('auth.security'));
        Route::post('/mail/search', [MailController::class, 'search']);
        Route::post('/drafts', [MailController::class, 'create'])->middleware('throttle:20,1');
        Route::post('/drafts/{id}', [MailController::class, 'save'])->whereUuid('id');
        Route::post('/drafts/{id}/send', [MailController::class, 'send'])->whereUuid('id')->middleware('throttle:10,1');
        Route::post('/messages/{id}/move', [MailController::class, 'move'])->whereUuid('id');
        Route::post('/messages/{id}/read', [MailController::class, 'read'])->whereUuid('id');
        Route::post('/messages/{id}/assign', [MailController::class, 'assign'])->whereUuid('id')->middleware('throttle:20,1');
        Route::post('/drafts/{id}/attachments', [MailController::class, 'upload'])->whereUuid('id')->middleware('throttle:10,1');
        Route::get('/attachments/{id}', [MailController::class, 'download'])->whereUuid('id');
        Route::post('/attachments/{id}/remove', [MailController::class, 'removeAttachment'])->whereUuid('id');
        Route::post('/security/revoke', [AuthController::class, 'revoke'])->middleware('throttle:5,1');
        Route::get('/admin', [AdminController::class, 'index']);
        Route::post('/admin/provider', [AdminController::class, 'provider'])->middleware('throttle:5,1');
        Route::post('/admin/products', [AdminController::class, 'product']);
        Route::post('/admin/organizations', [AdminController::class, 'organization'])->middleware('throttle:5,1');
        Route::post('/admin/domain-status', [AdminController::class, 'domainStatus'])->middleware('throttle:5,1');
        Route::post('/admin/domains', [AdminController::class, 'domain']);
        Route::post('/admin/mailboxes', [AdminController::class, 'mailbox']);
        Route::post('/admin/aliases', [AdminController::class, 'alias'])->middleware('throttle:10,1');
        Route::post('/admin/grants', [AdminController::class, 'grant'])->middleware('throttle:10,1');
        Route::post('/admin/invites', [AdminController::class, 'invite'])->middleware('throttle:10,1');
        Route::post('/admin/revoke', [AdminController::class, 'revokeMember'])->middleware('throttle:10,1');
    });
});
