<?php

namespace Brainstream\ProductPdfGenerate\Http\Controllers\Catalog;

use App\Http\Controllers\Controller;
use Webkul\Product\Models\Product;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\View;

class ProductController extends Controller
{
     public function generatePdf()
    {
        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', 300);

        $products = Product::cursor(); // Efficient lazy loading
        $html = preg_replace('/\s+/', ' ', View::make('productpdfgenerate::catalog.products.pdf', compact('products'))->render());
        $pdf = PDF::loadHTML($html)->setOptions([
            'dpi' => 96,  // Lower DPI for smaller file size
            'defaultFont' => 'Arial',
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => true
        ]);

        return $pdf->download('Productos-Catalogo_bylopez.pdf');
    }
}
