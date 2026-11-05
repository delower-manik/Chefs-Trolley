<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AccountingController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\CashShiftController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CmsPageController;
use App\Http\Controllers\Api\ComboController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerSegmentController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\DeliveryZoneController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\DiscountController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\ExpenseCategoryController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\FloorPlanController;
use App\Http\Controllers\Api\FoodItemController;
use App\Http\Controllers\Api\GatewayCallbackController;
use App\Http\Controllers\Api\GatewayPaymentController;
use App\Http\Controllers\Api\GiftCardController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\IngredientController;
use App\Http\Controllers\Api\KitchenStationController;
use App\Http\Controllers\Api\KitchenTicketController;
use App\Http\Controllers\Api\MarketingConsentController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationDeliveryController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentGatewayController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\PosCatalogController;
use App\Http\Controllers\Api\PrinterController;
use App\Http\Controllers\Api\PromotionController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\RealtimeController;
use App\Http\Controllers\Api\ReceiptController;
use App\Http\Controllers\Api\RecipeController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReservationController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\Rider\AppController as RiderApp;
use App\Http\Controllers\Api\Rider\CashController as RiderCash;
use App\Http\Controllers\Api\RiderController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\SiteSettingController;
use App\Http\Controllers\Api\Staff\AppController as StaffApp;
use App\Http\Controllers\Api\StockAdjustmentController;
use App\Http\Controllers\Api\StockTransferController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\TableController;
use App\Http\Controllers\Api\TaxRateController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WarehouseController;
use App\Http\Controllers\Api\Web\AddressController;
use App\Http\Controllers\Api\Web\CatalogController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\WifiVoucherController;
use App\Http\Controllers\Api\WifiZoneController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Payment providers send the customer / their notification here. Token-gated; nothing in the request is trusted.
    Route::match(['get', 'post'], '/payment-callbacks/{gateway}/{session}/{token}/{ipn?}', [GatewayCallbackController::class, 'handle'])
        ->where('ipn', 'ipn')->middleware('throttle:60,1')->name('gateway.callback');
    // One-click opt-out linked from every marketing email (signed, no login).
    Route::get('/marketing/unsubscribe/{customer}', [MarketingConsentController::class, 'unsubscribe'])
        ->middleware('signed')->name('marketing.unsubscribe');

    // Customer website (Phase 19). Public reads need no login; everything customer-specific needs a customer token (NOT a staff token).
    Route::prefix('web')->group(function () {
        Route::middleware('throttle:web-public')->group(function () {
            Route::get('/site', [CatalogController::class, 'site']);
            Route::get('/branches', [CatalogController::class, 'branches']);
            Route::get('/branches/{branch}/menu', [CatalogController::class, 'menu']);
            Route::get('/branches/{branch}/reviews', [CatalogController::class, 'reviews']);
            Route::get('/pages/{slug}', [CatalogController::class, 'page']);
            Route::get('/sitemap', [CatalogController::class, 'sitemap']);
            Route::get('/realtime/config', [App\Http\Controllers\Api\Web\RealtimeController::class, 'config']);
        });
        Route::middleware('throttle:web-otp')->group(function () {
            Route::post('/auth/code', [App\Http\Controllers\Api\Web\AuthController::class, 'sendCode']);
            Route::post('/auth/verify', [App\Http\Controllers\Api\Web\AuthController::class, 'verify']);
        });
        Route::middleware(['customer.auth', 'throttle:web-customer'])->group(function () {
            Route::get('/me', [App\Http\Controllers\Api\Web\AuthController::class, 'me']);
            Route::put('/me', [App\Http\Controllers\Api\Web\AuthController::class, 'update']);
            Route::post('/auth/logout', [App\Http\Controllers\Api\Web\AuthController::class, 'logout']);
            Route::get('/orders', [App\Http\Controllers\Api\Web\OrderController::class, 'index']);
            Route::get('/orders/{order}', [App\Http\Controllers\Api\Web\OrderController::class, 'show']);
            Route::get('/orders/{order}/status', [App\Http\Controllers\Api\Web\RealtimeController::class, 'status']);
            Route::post('/realtime/auth', [App\Http\Controllers\Api\Web\RealtimeController::class, 'auth']);
            Route::post('/orders/{order}/pay', [App\Http\Controllers\Api\Web\OrderController::class, 'pay']);
            Route::post('/orders/{order}/cancel', [App\Http\Controllers\Api\Web\OrderController::class, 'cancel']);
            Route::post('/orders/{order}/review', [App\Http\Controllers\Api\Web\AccountController::class, 'review']);
            Route::get('/loyalty', [App\Http\Controllers\Api\Web\AccountController::class, 'loyalty']);
            Route::post('/device-tokens', [App\Http\Controllers\Api\Web\DeviceTokenController::class, 'store']);
            Route::delete('/device-tokens', [App\Http\Controllers\Api\Web\DeviceTokenController::class, 'destroy']);
            Route::get('/addresses', [AddressController::class, 'index']);
            Route::post('/addresses', [AddressController::class, 'store']);
            Route::put('/addresses/{address}', [AddressController::class, 'update']);
            Route::delete('/addresses/{address}', [AddressController::class, 'destroy']);
            Route::get('/orders/{order}/reorder', [App\Http\Controllers\Api\Web\OrderController::class, 'reorder']);
            Route::get('/reservations', [App\Http\Controllers\Api\Web\AccountController::class, 'reservations']);
            Route::post('/reservations', [App\Http\Controllers\Api\Web\AccountController::class, 'reserve']);
            Route::post('/reservations/{reservation}/cancel', [App\Http\Controllers\Api\Web\AccountController::class, 'cancelReservation']);
            Route::post('/orders', [App\Http\Controllers\Api\Web\OrderController::class, 'store'])->middleware('throttle:web-order');
        });
    });

    // Public
    Route::get('/health', [HealthController::class, 'show'])->middleware('throttle:60,1');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('/auth/mfa', [AuthController::class, 'verifyMfa'])->middleware('throttle:mfa');
    Route::post('/auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:refresh');

    // Authenticated
    Route::middleware(['auth:sanctum', 'session.active', 'throttle:api', 'branch.scope', 'branch.models'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Settings — Phase 17. /settings/public is for every signed-in screen; the rest needs settings.manage.
        Route::get('/settings/public', [SettingsController::class, 'publicView']);
        Route::middleware('permission:settings.manage')->group(function () {
            Route::get('/settings', [SettingsController::class, 'index']);
            Route::put('/settings', [SettingsController::class, 'update']);
        });

        // Backups — Phase 16 (encrypted archives; running one / downloading one is audited)
        Route::middleware('permission:backups.manage')->prefix('backups')->group(function () {
            $b = BackupController::class;
            Route::get('/', [$b, 'index']);
            Route::post('/', [$b, 'store'])->middleware('throttle:account-sensitive');
            Route::post('/{backup}/verify', [$b, 'verify']);
            Route::get('/{backup}/download', [$b, 'download'])->middleware('throttle:account-sensitive');
        });

        // Self-service security — needs no permission beyond being signed in (it only ever touches your own account).
        Route::prefix('account')->group(function () {
            $a = AccountController::class;
            Route::get('/', [$a, 'show']);
            Route::put('/profile', [$a, 'updateProfile']);
            Route::middleware('throttle:account-sensitive')->group(function () use ($a) {
                Route::put('/password', [$a, 'changePassword']);
                Route::post('/mfa/setup', [$a, 'mfaSetup']);
                Route::post('/mfa/confirm', [$a, 'mfaConfirm']);
                Route::post('/mfa/recovery-codes', [$a, 'mfaRecoveryCodes']);
                Route::post('/mfa/disable', [$a, 'mfaDisable']);
            });
            Route::get('/sessions', [$a, 'sessions']);
            Route::delete('/sessions', [$a, 'revokeOtherSessions']);
            Route::delete('/sessions/{key}', [$a, 'revokeSession']);
        });

        Route::middleware('permission:orders.view')->group(function () {
            Route::get('/orders', [OrderController::class, 'index']);
            Route::get('/orders/{order}', [OrderController::class, 'show']);
        });

        Route::middleware('permission:orders.create')->group(function () {
            Route::post('/orders', [OrderController::class, 'store']);
            Route::post('/orders/{order}/items', [OrderController::class, 'addItems']);
            // Read-only menu/tables bundle for order-taking screens (POS, waiter).
            Route::get('/pos/catalog', [PosCatalogController::class, 'show']);
        });

        Route::middleware('permission:orders.cancel')->group(function () {
            Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
        });

        // Kitchen Display System — Phase 3
        // Kitchen stations — Phase 12 (listing is open to KDS users; editing needs kitchen.manage)
        Route::get('/kitchen/stations', [KitchenStationController::class, 'index'])
            ->middleware('permission:kds.access');
        Route::middleware('permission:kitchen.manage')->group(function () {
            Route::post('/kitchen/stations', [KitchenStationController::class, 'store']);
            Route::put('/kitchen/stations/{station}', [KitchenStationController::class, 'update']);
            Route::delete('/kitchen/stations/{station}', [KitchenStationController::class, 'destroy']);
        });

        Route::middleware('permission:kds.access')->group(function () {
            Route::get('/kitchen/tickets', [KitchenTicketController::class, 'index']);
            Route::post('/kitchen/tickets/{order}/bump', [KitchenTicketController::class, 'bump']);
            Route::post('/kitchen/tickets/{order}/reopen', [KitchenTicketController::class, 'reopen']);
        });

        // Menu — Phase 2
        Route::middleware('permission:menu.manage')->group(function () {
            Route::apiResource('categories', CategoryController::class)
                ->only(['index', 'store', 'update', 'destroy']);
            Route::apiResource('food-items', FoodItemController::class);
            Route::apiResource('combos', ComboController::class)
                ->only(['index', 'store', 'update', 'destroy']);
            Route::get('/food-items/{foodItem}/recipe', [RecipeController::class, 'show']);
            Route::put('/food-items/{foodItem}/recipe', [RecipeController::class, 'update']);
        });

        // Tables & Reservations — Phase 2
        Route::middleware('permission:tables.manage')->group(function () {
            Route::apiResource('tables', TableController::class)
                ->only(['index', 'store', 'update', 'destroy']);
            Route::apiResource('floor-plans', FloorPlanController::class)
                ->only(['index', 'store', 'update', 'destroy']);
            Route::put('/floor-plans/{floor_plan}/layout', [FloorPlanController::class, 'saveLayout']);
            Route::post('/orders/{order}/transfer-table', [OrderController::class, 'transferTable']);
        });

        // POS completion — Phase 13
        Route::middleware('permission:orders.view')->group(function () {
            Route::get('/orders/{order}/payments', [PaymentController::class, 'index']);
            Route::get('/orders/{order}/receipt', [ReceiptController::class, 'show']);
            Route::post('/orders/{order}/print', [ReceiptController::class, 'print']);
        });
        Route::middleware('permission:pos.access')->group(function () {
            Route::post('/orders/{order}/payments', [PaymentController::class, 'store']);
        });
        Route::middleware('permission:pos.access')->group(function () {
            $g = GatewayPaymentController::class;
            Route::get('/gateway-payments/options', [$g, 'options']);
            Route::post('/orders/{order}/gateway-payments', [$g, 'store']);
            Route::get('/gateway-payments/{session}', [$g, 'show']);
            Route::post('/gateway-payments/{session}/check', [$g, 'check']);
            Route::post('/gateway-payments/{session}/confirm', [$g, 'confirm']);
            Route::post('/gateway-payments/{session}/cancel', [$g, 'cancel']);
        });
        Route::middleware('permission:orders.refund')->group(function () {
            Route::post('/orders/{order}/refunds', [PaymentController::class, 'refund']);
            Route::post('/payments/{payment}/void', [PaymentController::class, 'void']);
        });
        Route::middleware('permission:orders.create')->group(function () {
            Route::put('/orders/{order}/discount', [DiscountController::class, 'apply']);
            Route::delete('/orders/{order}/discount', [DiscountController::class, 'remove']);
        });
        // Gift cards, segments, campaigns — Phase 15
        Route::middleware(['permission:giftcards.view', 'feature:gift_cards'])->group(function () {
            Route::get('/gift-cards', [GiftCardController::class, 'index']);
            Route::get('/gift-cards/{giftCard}', [GiftCardController::class, 'show']);
        });
        Route::post('/gift-cards/lookup', [GiftCardController::class, 'lookup'])->middleware(['permission:pos.access', 'feature:gift_cards', 'throttle:giftcard-lookup']);
        Route::post('/gift-cards', [GiftCardController::class, 'store'])->middleware(['permission:giftcards.issue', 'feature:gift_cards']);
        Route::middleware(['permission:giftcards.manage', 'feature:gift_cards'])->group(function () {
            Route::post('/gift-cards/{giftCard}/adjust', [GiftCardController::class, 'adjust']);
            Route::post('/gift-cards/{giftCard}/void', [GiftCardController::class, 'void']);
        });
        Route::middleware(['permission:campaigns.manage', 'feature:campaigns'])->group(function () {
            $seg = CustomerSegmentController::class;
            Route::post('/customer-segments/preview', [$seg, 'preview']);
            Route::get('/customer-segments', [$seg, 'index']);
            Route::post('/customer-segments', [$seg, 'store']);
            Route::get('/customer-segments/{segment}', [$seg, 'show']);
            Route::put('/customer-segments/{segment}', [$seg, 'update']);
            Route::delete('/customer-segments/{segment}', [$seg, 'destroy']);
            $c = CampaignController::class;
            Route::get('/campaigns', [$c, 'index']);
            Route::post('/campaigns', [$c, 'store']);
            Route::get('/campaigns/{campaign}', [$c, 'show']);
            Route::put('/campaigns/{campaign}', [$c, 'update']);
            Route::delete('/campaigns/{campaign}', [$c, 'destroy']);
            Route::get('/campaigns/{campaign}/audience', [$c, 'audience']);
            Route::post('/campaigns/{campaign}/send', [$c, 'send']);
            Route::post('/campaigns/{campaign}/cancel', [$c, 'cancel']);
        });

        Route::middleware('permission:cash.manage')->prefix('cash')->group(function () {
            $c = CashShiftController::class;
            Route::get('/shifts', [$c, 'index']);
            Route::get('/shifts/current', [$c, 'current']);
            Route::post('/shifts/open', [$c, 'open']);
            Route::get('/shifts/{shift}/report', [$c, 'report']);
            Route::post('/shifts/{shift}/movements', [$c, 'movement']);
            Route::post('/shifts/{shift}/close', [$c, 'close']);
            Route::post('/drawer/open', [$c, 'drawer']);
        });
        Route::middleware('permission:printers.manage')->group(function () {
            Route::apiResource('printers', PrinterController::class)
                ->only(['index', 'store', 'update', 'destroy']);
        });

        // Tax configuration — Phase 12
        Route::middleware('permission:tax.manage')->group(function () {
            Route::apiResource('tax-rates', TaxRateController::class)
                ->only(['index', 'store', 'update', 'destroy']);
        });
        Route::middleware(['permission:reservations.manage', 'feature:reservations'])->group(function () {
            Route::apiResource('reservations', ReservationController::class)
                ->only(['index', 'store', 'update']);
            Route::post('/reservations/{reservation}/cancel', [ReservationController::class, 'cancel']);
        });

        // Inventory — Phase 4
        Route::middleware('permission:inventory.manage')->group(function () {
            Route::get('/units', [UnitController::class, 'index']);
            Route::apiResource('warehouses', WarehouseController::class)
                ->only(['index', 'store', 'update', 'destroy']);
            Route::apiResource('suppliers', SupplierController::class)
                ->only(['index', 'store', 'update', 'destroy']);
            Route::apiResource('ingredients', IngredientController::class)
                ->only(['index', 'store', 'update', 'destroy']);
            Route::apiResource('purchases', PurchaseController::class)
                ->only(['index', 'store']);
            Route::post('/purchases/{purchase}/receive', [PurchaseController::class, 'receive']);
            Route::post('/purchases/{purchase}/cancel', [PurchaseController::class, 'cancel']);
            Route::get('/stock/ledger', [StockAdjustmentController::class, 'index']);
            Route::post('/stock/adjustments', [StockAdjustmentController::class, 'store']);
            Route::get('/stock/transfers', [StockTransferController::class, 'index']);
            Route::post('/stock/transfers', [StockTransferController::class, 'store']);
        });

        // Customers & Growth — Phase 5
        Route::middleware('permission:customers.view')->group(function () {
            Route::get('/customers', [CustomerController::class, 'index']);
            Route::get('/customers/{customer}', [CustomerController::class, 'show']);
            Route::get('/customers/{customer}/orders', [CustomerController::class, 'orders']);
            Route::get('/customers/{customer}/loyalty/ledger', [CustomerController::class, 'loyaltyLedger']);
        });
        Route::middleware('permission:customers.manage')->group(function () {
            Route::post('/customers', [CustomerController::class, 'store']);
            Route::put('/customers/{customer}', [CustomerController::class, 'update']);
            Route::delete('/customers/{customer}', [CustomerController::class, 'destroy']);
        });
        Route::middleware('permission:loyalty.manage')->group(function () {
            Route::post('/customers/{customer}/loyalty/adjust', [CustomerController::class, 'adjustLoyalty']);
        });

        Route::middleware('permission:promotions.manage')->group(function () {
            Route::apiResource('promotions', PromotionController::class)
                ->only(['index', 'store', 'update', 'destroy']);
        });

        Route::middleware('permission:reviews.view')->group(function () {
            Route::get('/reviews', [ReviewController::class, 'index']);
            Route::post('/reviews', [ReviewController::class, 'store']);
        });
        Route::middleware('permission:reviews.moderate')->group(function () {
            Route::post('/reviews/{review}/publish', [ReviewController::class, 'publish']);
            Route::post('/reviews/{review}/unpublish', [ReviewController::class, 'unpublish']);
            Route::delete('/reviews/{review}', [ReviewController::class, 'destroy']);
        });

        Route::middleware('permission:notifications.manage')->group(function () {
            Route::apiResource('notifications', NotificationController::class)
                ->only(['index', 'store', 'destroy']);
            Route::post('/notifications/{notification}/send', [NotificationController::class, 'send']);
            Route::get('/notification-deliveries', [NotificationDeliveryController::class, 'index']);
            Route::post('/notification-deliveries/test', [NotificationDeliveryController::class, 'test']);
            Route::post('/notification-deliveries/{delivery}/retry', [NotificationDeliveryController::class, 'retry']);
        });

        // Real-time + device registration — Phase 14 (any signed-in staff user)
        Route::get('/realtime/config', [RealtimeController::class, 'config']);
        Route::post('/realtime/auth', [RealtimeController::class, 'auth']);
        Route::post('/realtime/ably-token', [RealtimeController::class, 'ablyToken']);
        Route::post('/device-tokens', [DeviceTokenController::class, 'store']);
        Route::delete('/device-tokens', [DeviceTokenController::class, 'destroy']);

        // Staff app — Phase 21. Self-service attendance and "served"; the employee record decides the branch.
        Route::middleware(['permission:staff.app', 'employee'])->prefix('staff')->group(function () {
            Route::get('/me', [StaffApp::class, 'me']);
            Route::post('/clock-in', [StaffApp::class, 'clockIn']);
            Route::post('/clock-out', [StaffApp::class, 'clockOut']);
            Route::get('/attendance', [StaffApp::class, 'history']);
            Route::post('/orders/{order}/served', [StaffApp::class, 'served']);
        });

        // Rider app — Phase 20. The rider record (not a header) decides the branch; only the rider's own deliveries are reachable.
        Route::middleware(['permission:rider.app', 'rider'])->prefix('rider')->group(function () {
            Route::get('/me', [RiderApp::class, 'me']);
            Route::put('/status', [RiderApp::class, 'status']);
            Route::get('/deliveries', [RiderApp::class, 'deliveries']);
            Route::get('/deliveries/{id}', [RiderApp::class, 'show']);
            Route::post('/deliveries/{id}/pickup', [RiderApp::class, 'pickup']);
            Route::post('/deliveries/{id}/deliver', [RiderApp::class, 'deliver']);
            Route::post('/deliveries/{id}/fail', [RiderApp::class, 'fail']);
            Route::post('/sharing', [RiderApp::class, 'sharing']);
            Route::post('/location', [RiderApp::class, 'location'])->middleware('throttle:60,1');
            Route::get('/cash', [RiderApp::class, 'cash']);
        });
        Route::middleware('permission:cash.manage')->group(function () {
            Route::get('/cash/rider-holdings', [RiderCash::class, 'index']);
            Route::post('/cash/rider-holdings/{rider}/settle', [RiderCash::class, 'settle']);
        });

        // Delivery — Phase 6
        Route::middleware('permission:delivery.manage')->group(function () {
            Route::apiResource('delivery-zones', DeliveryZoneController::class)
                ->only(['index', 'store', 'update', 'destroy']);
            Route::apiResource('riders', RiderController::class)
                ->only(['index', 'store', 'update', 'destroy']);
            Route::get('/deliveries', [DeliveryController::class, 'index']);
            Route::post('/deliveries/{order}/assign', [DeliveryController::class, 'assign']);
            Route::post('/deliveries/{order}/pickup', [DeliveryController::class, 'pickup']);
            Route::post('/deliveries/{order}/deliver', [DeliveryController::class, 'deliver']);
            Route::post('/deliveries/{order}/fail', [DeliveryController::class, 'fail']);
        });

        // People & Finance — Phase 7
        Route::middleware('permission:hr.manage')->group(function () {
            Route::apiResource('employees', EmployeeController::class)
                ->only(['index', 'store', 'update', 'destroy']);
            Route::get('/attendance', [AttendanceController::class, 'index']);
            Route::get('/attendance/employee/{employee}', [AttendanceController::class, 'forEmployee']);
            Route::post('/attendance', [AttendanceController::class, 'store']);
        });
        Route::middleware('permission:payroll.manage')->group(function () {
            Route::get('/payroll/periods', [PayrollController::class, 'index']);
            Route::post('/payroll/periods', [PayrollController::class, 'store']);
            Route::get('/payroll/periods/{payrollPeriod}', [PayrollController::class, 'show']);
            Route::post('/payroll/periods/{payrollPeriod}/generate', [PayrollController::class, 'generate']);
            Route::put('/payroll/entries/{payrollEntry}', [PayrollController::class, 'updateEntry']);
            Route::post('/payroll/periods/{payrollPeriod}/finalize', [PayrollController::class, 'finalize']);
            Route::post('/payroll/periods/{payrollPeriod}/pay', [PayrollController::class, 'pay']);
        });
        Route::middleware('permission:finance.manage')->group(function () {
            Route::apiResource('expense-categories', ExpenseCategoryController::class)
                ->only(['index', 'store', 'destroy']);
            Route::apiResource('expenses', ExpenseController::class)
                ->only(['index', 'store', 'update', 'destroy']);
            Route::get('/accounting/summary', [AccountingController::class, 'summary']);
        });

        // Digital Presence — Phase 8
        Route::middleware('permission:cms.manage')->group(function () {
            Route::apiResource('cms/pages', CmsPageController::class)
                ->parameters(['pages' => 'cmsPage'])
                ->only(['index', 'show', 'store', 'update', 'destroy']);
            Route::post('/cms/pages/{cmsPage}/publish', [CmsPageController::class, 'publish']);
            Route::post('/cms/pages/{cmsPage}/unpublish', [CmsPageController::class, 'unpublish']);
            Route::get('/media', [MediaController::class, 'index']);
            Route::post('/media', [MediaController::class, 'store']);
            Route::delete('/media/{media}', [MediaController::class, 'destroy']);
            Route::get('/site-settings', [SiteSettingController::class, 'show']);
            Route::put('/site-settings', [SiteSettingController::class, 'update']);
        });

        // Integrations — Phase 8
        Route::middleware('permission:integrations.manage')->group(function () {
            Route::get('/payment-gateways', [PaymentGatewayController::class, 'index']);
            Route::put('/payment-gateways/{paymentGatewayConfig}', [PaymentGatewayController::class, 'update']);
            Route::apiResource('webhooks', WebhookController::class)
                ->only(['index', 'store', 'update', 'destroy']);
            Route::get('/webhooks/{webhook}/deliveries', [WebhookController::class, 'deliveries']);
            Route::post('/webhook-deliveries/{delivery}/redeliver', [WebhookController::class, 'redeliver']);
            Route::middleware('feature:wifi')->group(function () {
                Route::apiResource('wifi-zones', WifiZoneController::class)
                    ->parameters(['wifi-zones' => 'wifiZone'])
                    ->only(['index', 'store', 'update', 'destroy']);
                Route::get('/wifi-zones/{wifiZone}/vouchers', [WifiVoucherController::class, 'index']);
                Route::post('/wifi-zones/{wifiZone}/vouchers/generate', [WifiVoucherController::class, 'generate']);
                Route::post('/wifi/vouchers/{wifiVoucher}/redeem', [WifiVoucherController::class, 'redeem']);
            });
        });

        // Administration — Phase 10. The escalation rules (you can't grant more
        // than you hold, can't touch someone more powerful, can't remove the
        // last Super Admin) live in App\Support\AdminAccess, not in the routes.
        Route::middleware('permission:users.manage')->group(function () {
            Route::get('/users/options', [UserController::class, 'options']);
            Route::get('/users', [UserController::class, 'index']);
            Route::post('/users', [UserController::class, 'store']);
            Route::get('/users/{user}', [UserController::class, 'show']);
            Route::put('/users/{user}', [UserController::class, 'update']);
            Route::post('/users/{user}/password', [UserController::class, 'resetPassword']);
            Route::post('/users/{user}/mfa/reset', [UserController::class, 'resetMfa']);
            Route::delete('/users/{user}', [UserController::class, 'destroy']);
            Route::get('/roles', [RoleController::class, 'index']);
            Route::post('/roles', [RoleController::class, 'store']);
            Route::put('/roles/{role}', [RoleController::class, 'update']);
            Route::delete('/roles/{role}', [RoleController::class, 'destroy']);
            Route::get('/permissions', [RoleController::class, 'permissions']);
        });
        Route::middleware('permission:branches.manage')->group(function () {
            Route::get('/branches', [BranchController::class, 'index']);
            Route::post('/branches', [BranchController::class, 'store']);
            Route::put('/branches/{branch}', [BranchController::class, 'update']);
        });
        Route::middleware('permission:audit.view')->group(function () {
            Route::get('/activity-logs', [ActivityLogController::class, 'index']);
            Route::get('/activity-logs/actions', [ActivityLogController::class, 'actions']);
        });

        // Reports & Analytics — Phase 9 (read-only, computed live)
        Route::middleware('permission:reports.view')->group(function () {
            Route::get('/reports/sales', [ReportController::class, 'sales']);
            Route::get('/reports/sales/export', [ReportController::class, 'exportSales']);
            Route::get('/reports/products', [ReportController::class, 'products']);
            Route::get('/reports/categories', [ReportController::class, 'categories']);
            Route::get('/reports/delivery', [ReportController::class, 'delivery']);
            Route::get('/reports/customers', [ReportController::class, 'customers']);
            Route::get('/reports/branches', [ReportController::class, 'branches']);
        });

        // Dashboard metrics -- Phase 1 stub backed by real aggregate queries
        // (see DashboardController); expanded with more sources in later phases.
        Route::get('/dashboard/metrics', [DashboardController::class, 'index']);
    });
});
