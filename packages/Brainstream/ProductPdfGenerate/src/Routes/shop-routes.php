<?php

use Illuminate\Support\Facades\Route;
use Brainstream\ProductPdfGenerate\Http\Controllers\Shop\ProductPdfGenerateController;

Route::group(['middleware' => ['web', 'theme', 'locale', 'currency'], 'prefix' => 'productpdfgenerate'], function () {
    Route::get('', [ProductPdfGenerateController::class, 'index'])->name('shop.productpdfgenerate.index');
});