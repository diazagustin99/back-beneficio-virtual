<?php

use App\Http\Controllers\Api\V1\MerchantController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PreferenceController;
use App\Http\Controllers\Api\V1\PreferenceMerchantController;
use App\Http\Controllers\Api\V1\PreferenceWalletController;
use App\Http\Controllers\Api\V1\PromotionCategoryController;
use App\Http\Controllers\Api\V1\PromotionController;
use App\Http\Controllers\Api\V1\PromotionSnapshotController;
use App\Http\Controllers\Api\V1\PushSubscriptionController;
use App\Http\Controllers\Api\V1\ScrapeRunController;
use App\Http\Controllers\Api\V1\WalletController;
use App\Http\Controllers\Api\V1\WelcomeCarouselController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::apiResource('wallets', WalletController::class)->only(['index', 'show']);
    Route::apiResource('merchants', MerchantController::class)->only(['index', 'show']);
    Route::apiResource('promotion-categories', PromotionCategoryController::class)->only(['index', 'show']);
    Route::apiResource('promotions', PromotionController::class)->only(['index', 'show']);
    Route::get('promotions/{promotion}/snapshots', [PromotionSnapshotController::class, 'index']);
    Route::apiResource('scrape-runs', ScrapeRunController::class)->only(['index', 'show']);

    Route::get('welcome-carousel', [WelcomeCarouselController::class, 'index']);

    Route::post('preferences', [PreferenceController::class, 'store']);
    Route::get('preferences/{preference:token}', [PreferenceController::class, 'show']);
    Route::patch('preferences/{preference:token}', [PreferenceController::class, 'update']);

    Route::get('preferences/{preference:token}/notifications', [NotificationController::class, 'index']);
    Route::get('preferences/{preference:token}/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('preferences/{preference:token}/notifications/{notification}/read', [NotificationController::class, 'markRead']);

    Route::post('preferences/{preference:token}/push-subscriptions', [PushSubscriptionController::class, 'store']);

    Route::post('preferences/{preference:token}/merchants/{merchant}', [PreferenceMerchantController::class, 'store']);
    Route::delete('preferences/{preference:token}/merchants/{merchant}', [PreferenceMerchantController::class, 'destroy']);

    Route::post('preferences/{preference:token}/wallets/{wallet}', [PreferenceWalletController::class, 'store']);
    Route::delete('preferences/{preference:token}/wallets/{wallet}', [PreferenceWalletController::class, 'destroy']);
});
