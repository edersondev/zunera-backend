<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AuthMailDeliveryEventController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\FinancialAccountController;
use App\Http\Controllers\Api\V1\FinancialAccountSummaryController;
use App\Http\Controllers\Api\V1\FinancialHistoryController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\TransferController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('guest');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('guest');
    Route::post('/auth/password/recovery', [AuthController::class, 'requestRecovery']);
    Route::post('/auth/password/reset', [AuthController::class, 'resetPassword']);
    Route::post('/integrations/auth-mail/delivery-events', [AuthMailDeliveryEventController::class, 'store']);

    Route::middleware(['auth:sanctum', 'session.lifetime'])->group(function (): void {
        Route::get('/auth/session', [AuthController::class, 'session']);
        Route::post('/auth/session/continue', [AuthController::class, 'continue']);
        Route::delete('/auth/session', [AuthController::class, 'logout']);

        Route::get('/financial-accounts', [FinancialAccountController::class, 'index']);
        Route::post('/financial-accounts', [FinancialAccountController::class, 'store']);
        Route::get('/financial-accounts/summary', [FinancialAccountSummaryController::class, 'show']);
        Route::get('/financial-accounts/{account_id}', [FinancialAccountController::class, 'show'])->whereNumber('account_id');
        Route::patch('/financial-accounts/{account_id}', [FinancialAccountController::class, 'update'])->whereNumber('account_id');
        Route::post('/financial-accounts/{account_id}/archive', [FinancialAccountController::class, 'archive'])->whereNumber('account_id');
        Route::post('/financial-accounts/{account_id}/restore', [FinancialAccountController::class, 'restore'])->whereNumber('account_id');

        Route::get('/categories', [CategoryController::class, 'index']);
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::get('/categories/{category_id}', [CategoryController::class, 'show'])->whereNumber('category_id');
        Route::patch('/categories/{category_id}', [CategoryController::class, 'update'])->whereNumber('category_id');
        Route::post('/categories/{category_id}/archive', [CategoryController::class, 'archive'])->whereNumber('category_id');
        Route::post('/categories/{category_id}/restore', [CategoryController::class, 'restore'])->whereNumber('category_id');

        Route::get('/transactions', [TransactionController::class, 'index']);
        Route::post('/transactions', [TransactionController::class, 'store']);
        Route::get('/transactions/{transaction_id}', [TransactionController::class, 'show'])->whereNumber('transaction_id');
        Route::patch('/transactions/{transaction_id}', [TransactionController::class, 'update'])->whereNumber('transaction_id');
        Route::post('/transactions/{transaction_id}/remove', [TransactionController::class, 'remove'])->whereNumber('transaction_id');
        Route::post('/transactions/{transaction_id}/restore', [TransactionController::class, 'restore'])->whereNumber('transaction_id');

        Route::get('/transfers', [TransferController::class, 'index']);
        Route::post('/transfers', [TransferController::class, 'store']);
        Route::get('/transfers/{transfer_id}', [TransferController::class, 'show'])->whereNumber('transfer_id');
        Route::patch('/transfers/{transfer_id}', [TransferController::class, 'update'])->whereNumber('transfer_id');
        Route::post('/transfers/{transfer_id}/remove', [TransferController::class, 'remove'])->whereNumber('transfer_id');
        Route::post('/transfers/{transfer_id}/restore', [TransferController::class, 'restore'])->whereNumber('transfer_id');

        Route::get('/financial-history', [FinancialHistoryController::class, 'index']);
    });
});
