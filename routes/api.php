<?php

use App\Http\Controllers\v1\Admin\BlogController;
use App\Http\Controllers\v1\Auth\AuthController;
use App\Http\Controllers\v1\Auth\ForgotPasswordController;
use App\Http\Controllers\v1\Auth\ResetPasswordController;
use App\Http\Controllers\v1\Users\AccountController;
use App\Http\Controllers\v1\Users\CartController;
use App\Http\Controllers\v1\Users\OrderController;
use App\Http\Controllers\v1\Users\PaymentController;
use App\Http\Controllers\v1\Users\ProductCategoryController;
use App\Http\Controllers\v1\Users\ProductReviewController;
use App\Http\Controllers\v1\Users\RecentlyViewedController;
use App\Http\Controllers\v1\Users\VendorOrderController;
use App\Http\Controllers\v1\Users\VendorProductController;
use App\Http\Controllers\v1\Users\PayoutHistoryController;
use App\Http\Controllers\v1\Users\WishlistController;
use App\Http\Controllers\v1\Users\PostalCodeController;
use App\Http\Controllers\v1\Users\TopRatedController;
use App\Http\Controllers\v1\Users\NotificationController;
use App\Http\Controllers\v1\Users\PushSubscriptionController;
use App\Http\Controllers\v1\Users\BankAccountController;
use App\Http\Controllers\v1\Orders\OrderPricingController;
use App\Http\Controllers\v1\Agent\AgentController;
use App\Http\Controllers\Admin\PricingConfigController;
use App\Http\Controllers\Admin\WeightTierController;
use App\Http\Controllers\Admin\RiderPayoutRuleController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminVendorController;
use App\Http\Controllers\Admin\AdminProductController;
use App\Http\Controllers\Admin\AdminAgentController;
use App\Http\Controllers\Admin\AdminAgentWithdrawalController;
use App\Http\Controllers\Admin\AdminAgentCommissionController;
use App\Http\Controllers\Admin\AdminAgentCommissionReportController;
use App\Http\Controllers\Admin\AdminRevenueController;
use App\Http\Controllers\Admin\AdminNotificationController;
use App\Http\Controllers\Admin\SlideController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::prefix('auth')->group(function () {
        Route::post('/register', [AuthController::class, 'register']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/password/email', [ForgotPasswordController::class, 'sendResetLink']);
        Route::post('/password/reset', [ResetPasswordController::class, 'reset']);
        Route::post('/verify/email-otp', [AuthController::class, 'verifyOtp']);
        Route::post('/resend/email-otp', [AuthController::class, 'resendOtp']);
    });

  

    Route::group(["middleware" => ["auth:api"]], function () {
        Route::prefix('agent')->group(function () {
            Route::get('dashboard', [AgentController::class, 'dashboard']);
            Route::get('tier-upgrade-eligibility', [AgentController::class, 'tierUpgradeEligibility']);
Route::post('request-tier-upgrade', [AgentController::class, 'requestTierUpgrade']);
            Route::get('withdrawal-prefix-sums', [AgentController::class, 'withdrawalPrefixSums']);
            Route::get('transactions', [AgentController::class, 'transactions']);
            Route::get('referred-customers', [AgentController::class, 'referredCustomers']);
            Route::get('referred-agents', [AgentController::class, 'referredAgents']);
            Route::get('referred-vendors', [AgentController::class, 'referredVendors']);
            Route::put('customer-notification-prefs', [AgentController::class, 'updateCustomerNotificationPrefs']);
            Route::post('customer-reminders', [AgentController::class, 'sendCustomerReminder']);
            Route::post('vendor-reminders', [AgentController::class, 'sendVendorReminder']);
            Route::put('bank-details', [AgentController::class, 'updateBankDetails']);
            Route::post('bank-details', [AgentController::class, 'updateBankDetails']);
            Route::get('banks', [AgentController::class, 'listBanks']);
            Route::post('bank-details/resolve', [AgentController::class, 'resolveBankDetails']);
            Route::post('request-withdrawal', [AgentController::class, 'requestWithdrawal']);
            Route::post('apply-delivery-agent', [AgentController::class, 'applyToBecomeDeliveryAgent']);
        });

        Route::prefix('push')->group(function () {
            Route::post('/subscribe', [PushSubscriptionController::class, 'subscribe']);
            Route::delete('/unsubscribe', [PushSubscriptionController::class, 'unsubscribe']);
        });

        Route::prefix('account')->group(function () {
            Route::post('/shipping-address', [AccountController::class, 'addShippingAddress']);
            Route::get('/all/shipping-address', [AccountController::class, 'listShippingAddresses']);
            Route::patch('/update/shipping-address/{id}', [AccountController::class, 'updateShippingAddress']);
            Route::delete('/shipping-address/{id}', [AccountController::class, 'deleteShippingAddress']);
            Route::get('/shipping-address/{id}', [AccountController::class, 'getShippingAddress']);
        });

        Route::prefix('auth')->group(function () {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('user', [AuthController::class, 'getUser']);
            Route::get('profile', [AuthController::class, 'profile']);
            Route::put('update', [AuthController::class, 'updateProfile']);
            Route::get('payment/verify/{reference}', [PaymentController::class, 'verify']);
        });

        Route::get('/banks', [BankAccountController::class, 'listBanks']);
        Route::get('/bank-account', [BankAccountController::class, 'show']);
        Route::post('/bank-account', [BankAccountController::class, 'store']);
        Route::put('/bank-account', [BankAccountController::class, 'update']);
        Route::post('/bank-account/resolve', [BankAccountController::class, 'resolve']);
        Route::post('/withdraw', [BankAccountController::class, 'withdraw']);

        Route::prefix('vendor')->middleware('auth:api')->group(function () {
            Route::get('subcategories', [VendorProductController::class, 'getSubcategories']);
            Route::post('products-by-category', [VendorProductController::class, 'getProductsByCategory']);
            Route::post('products', [VendorProductController::class, 'storeVendorProducts']);
            Route::put('products/{id}', [VendorProductController::class, 'updateVendorProducts']);
            Route::post('products/delete', [VendorProductController::class, 'deleteVendorProducts']);
            Route::post('profile', [VendorProductController::class, 'updateProfile']);
            Route::get('notifications', [NotificationController::class, 'vendorNotifications']);
            Route::get('notifications/all', [NotificationController::class, 'allNotifications']);
            Route::post('notifications/{id}/read', [NotificationController::class, 'markOneAsRead']);
            Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
            Route::get('payout-history', [PayoutHistoryController::class, 'vendorHistory']);
        });
        Route::post('/payment/initialize', [PaymentController::class, 'initialize']);
        Route::get('/transactions', [OrderController::class, 'userTransactions']);

        Route::prefix('orders')->group(function () {
            Route::post('estimate', [OrderPricingController::class, 'estimate']);
            Route::post('checkout', [OrderController::class, 'checkout']);
             Route::post('/{order}/confirm', [OrderController::class, 'confirmDelivery']);
            Route::put('/{order}/update', [OrderController::class, 'update']);
            Route::get('', [OrderController::class, 'getUserOrders']);
            Route::get('/{id}', [OrderController::class, 'getOrderDetails']);
            Route::post('/{id}/reorder', [OrderController::class, 'reorder']);
            Route::post('/{id}/initiate-delivery', [OrderController::class, 'initiateDelivery']);
            Route::get('/completed/all', [VendorOrderController::class, 'vendorOrders']);

            Route::get('/vendor/{orderId}/items', [VendorOrderController::class, 'orderItems']);
            Route::post('/vendor/{vendorOrder}/ready', [VendorOrderController::class, 'toggleItemReady']);
        });

         Route::prefix('agent')->group(function () {
            Route::get('available-pickups', [OrderController::class, 'availablePickups']);
            Route::post('accept-delivery/{orderId}', [OrderController::class, 'acceptDelivery']);
            Route::get('my-pickups', [OrderController::class, 'myPickups']);
            Route::get('payout-history', [PayoutHistoryController::class, 'riderHistory']);
        });
    });

    Route::middleware(['auth:api', 'role:Admin,Super Admin'])->group(function () {
        Route::post('/blog', [BlogController::class, 'store']);
        Route::put('/blog/{id}', [BlogController::class, 'update']);
        Route::delete('/blog/{id}', [BlogController::class, 'destroy']);
        Route::get('/admin/orders', [OrderController::class, 'getAllOrdersForAdmin']);
        Route::get('/admin/orders/{orderId}', [OrderController::class, 'getOrderDetailsForAdmin']);
        Route::patch('/admin/orders/{orderId}/status', [OrderController::class, 'updateOrderStatusForAdmin']);
        Route::get('/admin/users', [AuthController::class, 'getAllUsersForAdmin']);
        Route::get('/admin/users/{id}', [AuthController::class, 'getSingleUserForAdmin']);
        Route::post('/admin/users/{id}/toggle-status', [AuthController::class, 'toggleUserStatus']);

        // Admin Dashboard
        Route::get('/admin/dashboard/metrics', [AdminDashboardController::class, 'metrics']);
        Route::get('/admin/dashboard/chart', [AdminDashboardController::class, 'chartData']);
        Route::get('/admin/dashboard/activity', [AdminDashboardController::class, 'recentActivity']);
 // Get all agents
    Route::get('/', [AdminAgentController::class, 'getAgents']);
    
    // Delivery applications

    Route::post('/admin/agents/applications/{agentId}', [AdminAgentController::class, 'approveDeliveryApplication']);
    
    // Tier upgrades

    Route::post('/admin/agents/tier-upgrades/{agentId}', [AdminAgentController::class, 'approveTierUpgrade']);

    // Manual tier update (admin override)
    Route::put('/admin/agents/{agentId}/tier', [AdminAgentController::class, 'updateAgentTier']);

    // Update delivery agent application status (admin override)
    Route::put('/admin/agents/{agentId}/delivery-status', [AdminAgentController::class, 'updateDeliveryAgentStatus']);

    // Tier configuration CRUD

    Route::post('/admin/agents/tier-configs', [AdminAgentController::class, 'createDeliveryTierConfig']);
    Route::put('/admin/agents/tier-configs/{configId}', [AdminAgentController::class, 'updateDeliveryTierConfig']);
    Route::delete('/admin/agents/tier-configs/{configId}', [AdminAgentController::class, 'deleteDeliveryTierConfig']);
        // Admin Vendors, Products, Agents, Notifications
        Route::get('/admin/vendors', [AdminVendorController::class, 'index']);
        Route::get('/admin/vendors/{id}', [AdminVendorController::class, 'show']);
        Route::get('/admin/products', [AdminProductController::class, 'index']);
        Route::get('/admin/agents', [AdminAgentController::class, 'index']);
          Route::get('/admin/agents/tier-upgrades', [AdminAgentController::class, 'getTierUpgradeRequests']);
         Route::get('/admin/agents/tier-configs', [AdminAgentController::class, 'getDeliveryTierConfigs']);
              Route::get('/admin/agents/applications', [AdminAgentController::class, 'getDeliveryApplications']);
        Route::get('/admin/agents/{id}', [AdminAgentController::class, 'show']);
        Route::post('/admin/agents/{id}/approve-delivery-agent', [AdminAgentController::class, 'approveDeliveryAgent']);
        Route::post('/admin/agents/{id}/reject-delivery-agent', [AdminAgentController::class, 'rejectDeliveryAgent']);
        Route::get('/admin/agent-withdrawals', [AdminAgentWithdrawalController::class, 'index']);
        Route::get('/admin/agent-withdrawals/history', [AdminAgentWithdrawalController::class, 'history']);
        Route::post('/admin/agent-withdrawals/{id}/approve', [AdminAgentWithdrawalController::class, 'approve']);
        Route::post('/admin/agent-withdrawals/{id}/reject', [AdminAgentWithdrawalController::class, 'reject']);
        Route::get('/admin/agent-commission-settings', [AdminAgentCommissionController::class, 'show']);
        Route::put('/admin/agent-commission-settings', [AdminAgentCommissionController::class, 'update']);
        Route::get('/admin/agent-commission-report', [AdminAgentCommissionReportController::class, 'index']);
        Route::get('/admin/agent-commission-report/orders/{orderId}', [AdminAgentCommissionReportController::class, 'show']);
        Route::get('/admin/revenue', [AdminRevenueController::class, 'index']);
        Route::get('/admin/revenue/{orderId}', [AdminRevenueController::class, 'show']);
        Route::get('/admin/notifications', [AdminNotificationController::class, 'index']);

        // Admin Pricing Management
        Route::prefix('admin')->group(function () {
            // Pricing Configurations
            Route::get('pricing-config', [PricingConfigController::class, 'index']);
            Route::post('pricing-config', [PricingConfigController::class, 'store']);
            Route::get('pricing-config/{pricingConfig}', [PricingConfigController::class, 'show']);
            Route::patch('pricing-config/{pricingConfig}', [PricingConfigController::class, 'update']);
            Route::delete('pricing-config/{pricingConfig}', [PricingConfigController::class, 'destroy']);
            Route::post('pricing-config/{pricingConfig}/toggle-active', [PricingConfigController::class, 'toggleActive']);
            Route::post('pricing-preview', [PricingConfigController::class, 'preview']);
            Route::post('pricing-validate', [PricingConfigController::class, 'validate']);

            // Weight Tiers
            Route::get('weight-tiers', [WeightTierController::class, 'index']);
            Route::post('weight-tiers', [WeightTierController::class, 'store']);
            Route::post('weight-tiers/bulk', [WeightTierController::class, 'bulkStore']);
            Route::get('weight-tiers/{weightTier}', [WeightTierController::class, 'show']);
            Route::patch('weight-tiers/{weightTier}', [WeightTierController::class, 'update']);
            Route::post('weight-tiers/{weightTier}/toggle-active', [WeightTierController::class, 'toggleActive']);
            Route::delete('weight-tiers/{weightTier}', [WeightTierController::class, 'destroy']);

            // Rider Payout Rules
            Route::get('rider-payout-rules', [RiderPayoutRuleController::class, 'index']);
            Route::post('rider-payout-rules', [RiderPayoutRuleController::class, 'store']);
            Route::get('rider-payout-rules/{riderPayoutRule}', [RiderPayoutRuleController::class, 'show']);
            Route::patch('rider-payout-rules/{riderPayoutRule}', [RiderPayoutRuleController::class, 'update']);
            Route::post('rider-payout-rules/{riderPayoutRule}/toggle-active', [RiderPayoutRuleController::class, 'toggleActive']);
            Route::delete('rider-payout-rules/{riderPayoutRule}', [RiderPayoutRuleController::class, 'destroy']);

            // Slides Management
            Route::get('slides', [SlideController::class, 'index']);
            Route::post('slides', [SlideController::class, 'store']);
            Route::get('slides/{id}', [SlideController::class, 'show']);
            Route::put('slides/{id}', [SlideController::class, 'update']);
            Route::delete('slides/{id}', [SlideController::class, 'destroy']);
        });
    });

    // Public routes

    // Push notification VAPID key (no authentication required)
    Route::get('/push/vapid-key', [PushSubscriptionController::class, 'vapidKey']);

    // Paystack payment routes (no authentication required)
    Route::get('/payment/callback', [PaymentController::class, 'callback']);
    Route::post('/verify-payment', [PaymentController::class, 'verify']);
    Route::post('/payment/webhook', [PaymentController::class, 'webhook']);

    // Cart routes (available to both guests and authenticated users)
    Route::post('/cart/add', [CartController::class, 'addToCart']);
    Route::get('/cart', [CartController::class, 'viewCart']);
    Route::post('/cart/update', [CartController::class, 'updateQuantity']);
    Route::delete('/cart/remove', [CartController::class, 'removeFromCart']);
    Route::post('/cart/add-multiple', [CartController::class, 'addMultipleToCart']);
    Route::delete('/cart/delete-multiple', [CartController::class, 'deleteMultipleFromCart']);
    // routes/api.php
    Route::get('/public/vendor/{vendorId}/products', [VendorProductController::class, 'publicVendorProducts']);
    Route::get('/suggest-address', [PostalCodeController::class, 'suggestAddress']);
    Route::post('/check-coverage', [PostalCodeController::class, 'checkCoverage']);


    Route::get('/all/vendor', [VendorProductController::class, 'listVendors']);
    Route::get('/all/categories', [VendorProductController::class, 'listVendorCategories']);

    // Public Pricing Information
    Route::get('/pricing-rates', [OrderPricingController::class, 'getPricingRates']);
    Route::post('/calculate-distance', [OrderPricingController::class, 'calculateDistance']);
    // Show a vendor's product set (public catalog / dashboard listing)
    Route::get('/vendor/products/{id}', [VendorProductController::class, 'showVendorProducts']);

    Route::get('/categories', [ProductCategoryController::class, 'index']);
    Route::post('/products', [ProductCategoryController::class, 'products']);
    Route::get('/all/unit', [ProductCategoryController::class, 'Unitindex']);
    Route::get('/product/{id}', [ProductCategoryController::class, 'getProduct']);
    Route::post('/imagekit', [ProductCategoryController::class, 'uploadImages']);

    Route::prefix('wishlist')->group(function () {
        Route::get('/', [WishlistController::class, 'index']);
        Route::post('/', [WishlistController::class, 'store']);
        Route::delete('/{id}', [WishlistController::class, 'destroy']);
    });

    Route::prefix('product')->group(function () {
        Route::post('/rating', [ProductReviewController::class, 'storeRating']);
        Route::post('/review', [ProductReviewController::class, 'storeReview']);
        Route::get('/{productId}/reviews', [ProductReviewController::class, 'showProductReviews']);
    });
    Route::prefix('blog')->group(function () {
        Route::get('/', [BlogController::class, 'index']);
        Route::get('/{id}', [BlogController::class, 'show']);
    });

    // Top Rated endpoints (public)
    Route::get('/top-rated/stores', [TopRatedController::class, 'topRatedStores']);
    Route::get('/top-rated/products', [TopRatedController::class, 'topRatedProducts']);

    // Public slides for carousel
    Route::get('/slides/active', [SlideController::class, 'active']);
});
