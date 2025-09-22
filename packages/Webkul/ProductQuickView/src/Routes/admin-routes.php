<?php

use Illuminate\Support\Facades\Route;
use Brainstream\ProductQuickView\Http\Controllers\Admin\ProductQuickViewController;

Route::group(['middleware' => ['web', 'admin'], 'prefix' => 'admin/productquickview'], function () {
    Route::controller(ProductQuickViewController::class)->group(function () {
        Route::get('', 'index')->name('admin.productquickview.index');
        
        Route::post('settings', 'update')->name('admin.productquickview.update');

        Route::get('settings', 'getSettings')
            ->name('admin.productquickview.getSettings')
            ->middleware(['web']); 
    });
});