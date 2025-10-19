<?php

use Illuminate\Support\Facades\Route;
use Brainstream\ProductPdfGenerate\Http\Controllers\Admin\ProductPdfGenerateController;
use Brainstream\ProductPdfGenerate\Http\Controllers\Catalog\ProductController;

Route::group(['middleware' => ['web', 'admin'], 'prefix' => 'admin/productpdfgenerate'], function () {
    Route::controller(ProductPdfGenerateController::class)->group(function () {
        Route::get('', 'index')->name('admin.productpdfgenerate.index');
    });

    Route::prefix('catalog')->group(function () {
        Route::controller(ProductController::class)->prefix('products')->group(function () {
            Route::get('catalog/products/pdf', [ProductController::class, 'generatePdf'])->name('productpdfgenerate.catalog.products.pdf');
        });
    });
});