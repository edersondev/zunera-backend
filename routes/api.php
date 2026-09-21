<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AuthMailDeliveryEventController;
use App\Http\Controllers\Api\V1\BudgetController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CreditCardController;
use App\Http\Controllers\Api\V1\CreditCardCreditEventController;
use App\Http\Controllers\Api\V1\CreditCardDashboardController;
use App\Http\Controllers\Api\V1\CreditCardPurchaseController;
use App\Http\Controllers\Api\V1\CreditCardStatementController;
use App\Http\Controllers\Api\V1\CreditCardStatementPaymentController;
use App\Http\Controllers\Api\V1\FinancialAccountController;
use App\Http\Controllers\Api\V1\FinancialAccountSummaryController;
use App\Http\Controllers\Api\V1\FinancialDashboardController;
use App\Http\Controllers\Api\V1\FinancialHistoryController;
use App\Http\Controllers\Api\V1\RecurringTransactionController;
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

        Route::get('/financial-dashboard/summary', [FinancialDashboardController::class, 'summary']);
        Route::get('/financial-dashboard/accounts', [FinancialDashboardController::class, 'accounts']);
        Route::get('/financial-dashboard/expense-distribution', [FinancialDashboardController::class, 'expenseDistribution']);
        Route::get('/financial-dashboard/evolution', [FinancialDashboardController::class, 'evolution']);
        Route::get('/financial-dashboard/recent-activity', [FinancialDashboardController::class, 'recentActivity']);
        Route::get('/financial-dashboard/upcoming-activity', [FinancialDashboardController::class, 'upcomingActivity']);

        Route::get('/recurring-transactions', [RecurringTransactionController::class, 'index']);
        Route::post('/recurring-transactions', [RecurringTransactionController::class, 'store']);
        Route::get('/recurring-transactions/{recurring_transaction_id}', [RecurringTransactionController::class, 'show'])->whereNumber('recurring_transaction_id');
        Route::patch('/recurring-transactions/{recurring_transaction_id}', [RecurringTransactionController::class, 'update'])->whereNumber('recurring_transaction_id');
        Route::get('/recurring-transactions/{recurring_transaction_id}/occurrences', [RecurringTransactionController::class, 'occurrences'])->whereNumber('recurring_transaction_id');
        Route::post('/recurring-transactions/{recurring_transaction_id}/pause', [RecurringTransactionController::class, 'pause'])->whereNumber('recurring_transaction_id');
        Route::post('/recurring-transactions/{recurring_transaction_id}/resume', [RecurringTransactionController::class, 'resume'])->whereNumber('recurring_transaction_id');
        Route::post('/recurring-transactions/{recurring_transaction_id}/end', [RecurringTransactionController::class, 'end'])->whereNumber('recurring_transaction_id');

        Route::get('/budgets/{year}/{month}', [BudgetController::class, 'show'])->whereNumber('year')->whereNumber('month');
        Route::post('/budgets', [BudgetController::class, 'store']);
        Route::post('/budgets/{budget_id}/plans', [BudgetController::class, 'storePlan'])->whereNumber('budget_id');
        Route::patch('/budgets/{budget_id}/plans/{plan_id}', [BudgetController::class, 'updatePlan'])->whereNumber('budget_id')->whereNumber('plan_id');
        Route::delete('/budgets/{budget_id}/plans/{plan_id}', [BudgetController::class, 'destroyPlan'])->whereNumber('budget_id')->whereNumber('plan_id');
        Route::post('/budgets/{budget_id}/copy', [BudgetController::class, 'copy'])->whereNumber('budget_id');

        Route::get('/credit-cards', [CreditCardController::class, 'index']);
        Route::post('/credit-cards', [CreditCardController::class, 'store']);
        Route::get('/credit-cards/{card_id}', [CreditCardController::class, 'show'])->whereNumber('card_id');
        Route::patch('/credit-cards/{card_id}', [CreditCardController::class, 'update'])->whereNumber('card_id');
        Route::post('/credit-cards/{card_id}/archive', [CreditCardController::class, 'archive'])->whereNumber('card_id');
        Route::get('/credit-cards/{card_id}/purchases', [CreditCardPurchaseController::class, 'index'])->whereNumber('card_id');
        Route::post('/credit-cards/{card_id}/purchases', [CreditCardPurchaseController::class, 'store'])->whereNumber('card_id');
        Route::get('/credit-card-purchases/{purchase_id}', [CreditCardPurchaseController::class, 'show'])->whereNumber('purchase_id');
        Route::patch('/credit-card-purchases/{purchase_id}', [CreditCardPurchaseController::class, 'update'])->whereNumber('purchase_id');
        Route::post('/credit-card-purchases/{purchase_id}/credit-events', [CreditCardCreditEventController::class, 'store'])->whereNumber('purchase_id');
        Route::get('/credit-cards/{card_id}/statements', [CreditCardStatementController::class, 'index'])->whereNumber('card_id');
        Route::get('/credit-card-statements/{statement_id}', [CreditCardStatementController::class, 'show'])->whereNumber('statement_id');
        Route::post('/credit-card-statements/{statement_id}/payments', [CreditCardStatementPaymentController::class, 'store'])->whereNumber('statement_id');
        Route::get('/credit-card-payments/{payment_id}', [CreditCardStatementPaymentController::class, 'show'])->whereNumber('payment_id');
        Route::patch('/credit-card-payments/{payment_id}', [CreditCardStatementPaymentController::class, 'update'])->whereNumber('payment_id');
        Route::post('/credit-card-payments/{payment_id}/remove', [CreditCardStatementPaymentController::class, 'remove'])->whereNumber('payment_id');
        Route::post('/credit-card-payments/{payment_id}/restore', [CreditCardStatementPaymentController::class, 'restore'])->whereNumber('payment_id');
        Route::get('/financial-dashboard/credit-cards', [CreditCardDashboardController::class, 'show']);
    });
});
