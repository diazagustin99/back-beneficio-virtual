<?php

use App\Http\Controllers\Api\V1\MerchantController;
use App\Http\Controllers\Api\V1\PromotionCategoryController;
use App\Http\Controllers\Api\V1\PromotionController;
use App\Http\Controllers\Api\V1\PromotionSnapshotController;
use App\Http\Controllers\Api\V1\ScrapeRunController;
use App\Http\Controllers\Api\V1\WalletController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::apiResource('wallets', WalletController::class)->only(['index', 'show']);
    Route::apiResource('merchants', MerchantController::class)->only(['index', 'show']);
    Route::apiResource('promotion-categories', PromotionCategoryController::class)->only(['index', 'show']);
    Route::apiResource('promotions', PromotionController::class)->only(['index', 'show']);
    Route::get('promotions/{promotion}/snapshots', [PromotionSnapshotController::class, 'index']);
    Route::apiResource('scrape-runs', ScrapeRunController::class)->only(['index', 'show']);
});
