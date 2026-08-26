<?php

namespace Brainstream\ProductPdfGenerate\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;

class ProductPdfGenerateServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        $this->loadRoutesFrom(__DIR__ . '/../Routes/admin-routes.php');

        $this->loadRoutesFrom(__DIR__ . '/../Routes/shop-routes.php');

        $this->loadTranslationsFrom(__DIR__ . '/../Resources/lang', 'productpdfgenerate');

        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'productpdfgenerate');
    
        // Override the specific view
        View::composer('shop::categories.view', function ($view) {
            $view->setPath(base_path('packages/Brainstream/ProductPdfGenerate/src/Resources/views/categories/view.blade.php'));
        });

        View::composer('admin::catalog.products.index', function ($view) {
            $view->setPath(base_path('packages/Brainstream/ProductPdfGenerate/src/Resources/views/catalog/products/index.blade.php'));
        });
    }
}