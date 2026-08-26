<?php

namespace Brainstream\ProductPdfGenerate\Http\Controllers\Shop;

use Illuminate\Routing\Controller;

class ProductPdfGenerateController extends Controller
{
    public function index()
    {
        return view('productpdfgenerate::shop.index');
    }
}
