<?php

namespace Brainstream\ProductQuickView\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Webkul\Product\Models\ProductFlat;
use Illuminate\Support\Facades\Config;
use Webkul\Core\Repositories\CoreConfigRepository;
use Brainstream\ProductQuickView\Models\ProductQuickViewSettings;

class ProductQuickViewController extends Controller
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    public function __construct(protected CoreConfigRepository $coreConfigRepository) {}

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {        
        return view('productquickview::admin.index');
    }

    public function getSettings()
    {
        try {
            $settings = ProductQuickViewSettings::first();
            
            if (!$settings) {
                $settings = ProductQuickViewSettings::create([
                    'show_full_description' => true,
                    'show_product_number' => true,
                    'show_quantity' => true,
                    'show_sku' => true,
                ]);
            }

            return response()->json($settings);
        } catch (\Exception $e) {
            return response()->json([
                'show_full_description' => true,
                'show_product_number' => true,
                'show_quantity' => true,
                'show_sku' => true,
            ]);
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @return \Illuminate\Http\Response
     */

    public function update(Request $request)
    {
        try {

            $validatedData = $request->validate([
                'show_sku' => 'required|boolean',
                'show_product_number' => 'required|boolean',
                'show_quantity' => 'required|boolean',
                'show_full_description' => 'required|boolean',
            ]);

            // Explicitly convert values to boolean
            $formattedData = [
                'show_sku' => filter_var($validatedData['show_sku'], FILTER_VALIDATE_BOOLEAN),
                'show_product_number' => filter_var($validatedData['show_product_number'], FILTER_VALIDATE_BOOLEAN),
                'show_quantity' => filter_var($validatedData['show_quantity'], FILTER_VALIDATE_BOOLEAN),
                'show_full_description' => filter_var($validatedData['show_full_description'], FILTER_VALIDATE_BOOLEAN),
            ];

            $settings = ProductQuickViewSettings::first();
            
            if (!$settings) {
                $settings = ProductQuickViewSettings::create($formattedData);
            } else {
                $settings->update($formattedData);
            }

            // Force reload from database to ensure we have the correct values
            $settings = $settings->fresh();

            return response()->json([
                'message' => trans('productquickview::app.admin.settings.save-message'),
                'settings' => $settings
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error saving settings: ' . $e->getMessage()
            ], 500);
        }
    }
}