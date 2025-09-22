<?php

namespace Brainstream\ProductQuickView\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Webkul\Shop\Http\Resources\ProductResource;
use Brainstream\ProductQuickView\Overrides\ProductResource as CustomProductResource;

class ProductQuickViewServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->app->singleton(ProductResource::class, function ($app) {
            return new CustomProductResource(null);
        });
        
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        $this->loadRoutesFrom(__DIR__ . '/../Routes/admin-routes.php');

        $this->loadTranslationsFrom(__DIR__ . '/../Resources/lang', 'productquickview');

        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'productquickview');

        Event::listen('bagisto.admin.layout.head', function($viewRenderEventManager) {
            $viewRenderEventManager->addTemplate('productquickview::admin.layouts.style');
        });

        // Override the specific view
        View::composer('shop::components.products.card', function ($view) {
            $view->setPath(base_path('packages/Brainstream/ProductQuickView/src/Resources/views/components/products/card.blade.php'));
        });
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        // Change the binding to be more explicit
        $this->app->singleton(ProductResource::class, function ($app) {
            return new CustomProductResource(null);
        });
        
        // Alternative approach using extend if singleton doesn't work
        $this->app->extend(ProductResource::class, function ($service, $app) {
            return new CustomProductResource(null);
        });

        $this->registerConfig();
    }

    /**
     * Register package config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->mergeConfigFrom(
            dirname(__DIR__) . '/Config/admin-menu.php', 'menu.admin'
        );

        $this->mergeConfigFrom(
            dirname(__DIR__) . '/Config/acl.php', 'acl'
        );
    }
}