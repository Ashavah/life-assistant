<?php

use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ClosedConversationController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\ConversationMessageController;
use App\Http\Controllers\ExternalActionProposalController;
use App\Http\Controllers\GoogleServiceConnectionController;
use App\Http\Controllers\ServiceConnectionController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('login.store');
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('register.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::patch('/account/profile', [AccountSettingsController::class, 'updateProfile'])
        ->name('account.profile.update');
    Route::patch('/account/password', [AccountSettingsController::class, 'updatePassword'])
        ->middleware('throttle:5,1')
        ->name('account.password.update');
    Route::get('/', [ChatController::class, 'index'])->name('home');
    Route::post('/characters', [CharacterController::class, 'store'])
        ->name('characters.store');
    Route::patch('/characters/{character}', [CharacterController::class, 'update'])
        ->name('characters.update');
    Route::delete('/characters/{character}', [CharacterController::class, 'destroy'])
        ->name('characters.destroy');
    Route::post('/conversations', [ConversationController::class, 'store'])
        ->name('conversations.store');
    Route::post('/conversations/{conversation}/messages', [ConversationMessageController::class, 'store'])
        ->name('conversations.messages.store');
    Route::post('/conversations/{conversation}/closed', [ClosedConversationController::class, 'store'])
        ->name('conversations.closed.store');

    Route::get('/integrations/google/{provider}/redirect', [GoogleServiceConnectionController::class, 'redirect'])
        ->name('google-services.redirect');
    Route::delete('/integrations/google/{provider}', [GoogleServiceConnectionController::class, 'destroy'])
        ->name('google-services.destroy');
    Route::get('/auth/google/calendar/callback', [GoogleServiceConnectionController::class, 'callback'])
        ->name('google-services.callback');

    Route::get('/integrations/{provider}/redirect', [ServiceConnectionController::class, 'redirect'])
        ->middleware('throttle:10,1')
        ->name('integrations.redirect');
    Route::get('/auth/integrations/{provider}/callback', [ServiceConnectionController::class, 'callback'])
        ->middleware('throttle:20,1')
        ->name('integrations.callback');
    Route::delete('/integrations/{provider}', [ServiceConnectionController::class, 'destroy'])
        ->middleware('throttle:10,1')
        ->name('integrations.destroy');

    Route::post('/external-actions/{externalActionProposal}/confirm', [ExternalActionProposalController::class, 'confirm'])
        ->name('external-actions.confirm');
    Route::post('/external-actions/{externalActionProposal}/reject', [ExternalActionProposalController::class, 'reject'])
        ->name('external-actions.reject');
});
