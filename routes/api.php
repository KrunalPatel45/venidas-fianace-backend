<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\SocietyAccountController;
use App\Http\Controllers\Api\OpeningFeeController;
use App\Http\Controllers\Api\FundController;
use App\Http\Controllers\Api\MonthlyInvestmentController;
use App\Http\Controllers\Api\LoanController;
use App\Http\Controllers\Api\ExternalBorrowerController;
use App\Http\Controllers\Api\SocietyCashController;
use App\Http\Controllers\Api\FundAuditController;
use App\Http\Controllers\Api\FinancialReconciliationController;
use App\Http\Controllers\Api\AdminDashboardController;
use App\Http\Controllers\Api\MemberDashboardController;
use App\Http\Controllers\Api\LoanBorrowerController;
use App\Http\Controllers\Api\FinancialReportController;
use App\Http\Controllers\Api\MonthlyMemberStatementController;

Route::prefix('v1')->group(function () {

    Route::post('/login', [
        AuthController::class,
        'login'
    ])->middleware('throttle:50,1');

    Route::post(
        '/whatsapp-worker/next',
        [\App\Http\Controllers\Api\WhatsAppWorkerController::class, 'claim']
    )->middleware('throttle:60,1');

    Route::post(
        '/whatsapp-worker/{notification}/sent',
        [\App\Http\Controllers\Api\WhatsAppWorkerController::class, 'sent']
    )->middleware('throttle:60,1');

    Route::post(
        '/whatsapp-worker/{notification}/failed',
        [\App\Http\Controllers\Api\WhatsAppWorkerController::class, 'failed']
    )->middleware('throttle:60,1');

    Route::middleware('auth:sanctum')->group(function () {

        Route::get('/me', [
            AuthController::class,
            'me'
        ]);

        Route::get(
            '/society/dashboard',
            [\App\Http\Controllers\Api\SocietyDashboardController::class, 'index']
        );

        Route::get(
            '/my/dashboard',
            [MemberDashboardController::class, 'index']
        );

        Route::post('/logout', [
            AuthController::class,
            'logout'
        ]);

        Route::post('/change-password', [
            AuthController::class,
            'changePassword'
        ]);

        Route::get('/my-accounts', [
            SocietyAccountController::class,
            'myAccounts'
        ]);

        Route::middleware('admin')
            ->prefix('admin')
            ->group(function () {

                Route::apiResource(
                    'members',
                    MemberController::class
                );

                Route::get('/accounts', [
                    SocietyAccountController::class,
                    'index'
                ]);

                Route::post('/accounts', [
                    SocietyAccountController::class,
                    'store'
                ]);

                Route::get('/accounts/{account}', [
                    SocietyAccountController::class,
                    'show'
                ]);

                Route::get('/opening-fees', [
                    OpeningFeeController::class,
                    'index'
                ]);

                Route::post('/opening-fees/{fee}/pay', [
                    OpeningFeeController::class,
                    'pay'
                ]);

                Route::get('/fund/summary', [
                    FundController::class,
                    'summary'
                ]);

                Route::get('/fund/entries', [
                    FundController::class,
                    'entries'
                ]);

                Route::get(
                    '/my/monthly-investments',
                    [MonthlyInvestmentController::class, 'myInvestments']
                );
                Route::post(
                    '/monthly-investments/generate',
                    [MonthlyInvestmentController::class, 'generate']
                );

                Route::get(
                    '/monthly-investments/summary',
                    [MonthlyInvestmentController::class, 'summary']
                );

                Route::get(
                    '/monthly-investments',
                    [MonthlyInvestmentController::class, 'index']
                );

                Route::post(
                    '/monthly-investments/{investment}/pay',
                    [MonthlyInvestmentController::class, 'pay']
                );

                Route::get(
                    '/monthly-investments/member-pending',
                    [MonthlyInvestmentController::class, 'pendingForMember']
                );

                Route::post(
                    '/monthly-investments/bulk-pay',
                    [MonthlyInvestmentController::class, 'bulkPay']
                );



                // Authenticated borrower
                Route::get(
                    '/my/loans',
                    [LoanController::class, 'myLoans']
                );

                Route::get(
                    '/loans/summary',
                    [LoanController::class, 'summary']
                );

                Route::post(
                    '/loans/interest/generate',
                    [LoanController::class, 'generateInterest']
                );

                Route::get(
                    '/loans/interest',
                    [LoanController::class, 'interestList']
                );

                Route::post(
                    '/loans/interest/{interest}/pay',
                    [LoanController::class, 'payInterest']
                );

                Route::get(
                    '/loans',
                    [LoanController::class, 'index']
                );

                Route::post(
                    '/loans',
                    [LoanController::class, 'store']
                );

                Route::get(
                    '/loans/{loan}',
                    [LoanController::class, 'show']
                );

                Route::post(
                    '/loans/{loan}/repay-principal',
                    [LoanController::class, 'repayPrincipal']
                );

                Route::get(
                    '/external-borrowers',
                    [ExternalBorrowerController::class, 'index']
                );

                Route::post(
                    '/external-borrowers',
                    [ExternalBorrowerController::class, 'store']
                );

                Route::get(
                    '/external-borrowers/{externalBorrower}',
                    [ExternalBorrowerController::class, 'show']
                );

                Route::put(
                    '/external-borrowers/{externalBorrower}',
                    [ExternalBorrowerController::class, 'update']
                );

                Route::patch(
                    '/external-borrowers/{externalBorrower}/activate',
                    [ExternalBorrowerController::class, 'activate']
                );

                Route::patch(
                    '/external-borrowers/{externalBorrower}/deactivate',
                    [ExternalBorrowerController::class, 'deactivate']
                );

                Route::get(
                    '/cash/balance',
                    [SocietyCashController::class, 'balance']
                );

                Route::get(
                    '/fund/audit',
                    [FundAuditController::class, 'index']
                );

                Route::get(
                    '/finance/reconciliation',
                    [FinancialReconciliationController::class, 'index']
                );
                Route::get(
                    '/dashboard',
                    [AdminDashboardController::class, 'index']
                );

                Route::get(
                    '/reports/monthly',
                    [AdminDashboardController::class, 'monthly']
                );

                Route::get(
                    '/loan-borrowers',
                    [LoanBorrowerController::class, 'index']
                );

                Route::get(
                    '/reports/financial',
                    [FinancialReportController::class, 'index']
                );

                Route::get(
                    '/reports/financial/export',
                    [FinancialReportController::class, 'export']
                );

                Route::get(
                    '/reports/monthly-member-statement',
                    [
                        \App\Http\Controllers\Api\MonthlyMemberStatementController::class,
                        'index'
                    ]
                );

                Route::get(
                    '/reports/monthly-member-statement/export',
                    [
                        \App\Http\Controllers\Api\MonthlyMemberStatementController::class,
                        'export'
                    ]
                );

                Route::get(
                    '/reports/monthly-member-statement/excel',
                    [
                        \App\Http\Controllers\Api\MonthlyMemberStatementExcelController::class,
                        'export'
                    ]
                );
            });
    });
});
