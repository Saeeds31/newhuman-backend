<?php

use Illuminate\Support\Facades\Route;
use Modules\Products\Http\Controllers\ProductAttributeController;
use Modules\Products\Http\Controllers\ProductController;
use Modules\Products\Http\Controllers\ProductsController;
use Modules\Products\Http\Controllers\ProductTypeController;
use Modules\Products\Http\Controllers\ProductVariantController;

Route::middleware(['auth:sanctum'])->prefix('v1/admin')->group(function () {
    Route::apiResource('product-types', ProductTypeController::class);
    Route::get('product-attributes', [ProductAttributeController::class, 'index']);
    Route::post('product-attributes', [ProductAttributeController::class, 'store']);
    Route::get('product-attributes/{productAttribute}', [ProductAttributeController::class, 'show']);
    Route::put('product-attributes/{productAttribute}', [ProductAttributeController::class, 'update']);
    Route::delete('product-attributes/{productAttribute}', [ProductAttributeController::class, 'destroy']);
    Route::get('product-attributes/{productAttribute}/values', [ProductAttributeController::class, 'values']);
    Route::post('product-attribute-values', [ProductAttributeController::class, 'storeValue']);
    Route::put('product-attribute-values/{productAttributeValue}', [ProductAttributeController::class, 'updateValue']);
    Route::delete('product-attribute-values/{productAttributeValue}', [ProductAttributeController::class, 'destroyValue']);
    Route::get('products', [ProductController::class, 'index']);
    Route::post('products', [ProductController::class, 'store']);
    Route::get('products/{product}', [ProductController::class, 'show']);
    Route::put('products/{product}', [ProductController::class, 'update']);
    Route::delete('products/{product}', [ProductController::class, 'destroy']);
    Route::patch('products/{product}/toggle-status', [ProductController::class, 'toggleStatus']);
    Route::get('product-types/{productType}/attributes', [ProductController::class, 'getAttributes']);


    // 

});
Route::prefix('v1/front')->group(function () {
    Route::get('products', [ProductController::class, 'frontIndex']);
    Route::get('products/{productId}', [ProductController::class, 'frontDetail']);
    Route::get('product-base-types', [ProductController::class, 'frontProductType']);
    Route::get('products-parents', [ProductController::class, 'getParentProducts']);
    Route::get('products/{parentId}/children-list', [ProductController::class, 'getChildrenByParentId']);
});
