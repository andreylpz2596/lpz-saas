<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@lang('productpdfgenerate::app.catalog.products.index.product-pdf')</title>
    <style>
        body {
            font-family: Arial, sans-serif;
        }
        .container {
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }
        h1 {
            text-align: center;
            margin-bottom: 5px;
        }
        table {
            width: 100%;
            border-collapse: collapse; 
            margin-bottom: 5px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: center;
            vertical-align: top; 
        }
        th {
            background-color: #f2f2f2;
        }
        .product-image {
            max-width: 140px;
            height: 140px; 
            display: block;
            margin: 0 auto; 
        }
        p.product-name {
            word-wrap: break-word;
            max-width: 150px;
            margin: 5px auto;
            text-align: center;
        }
        td {
            width: 33.33%; /* Each product takes up one-third of the row */
            height: 250px; 
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>@lang('productpdfgenerate::app.catalog.products.index.product-list')</h1>
        <table>
            <tbody>
                @foreach($products as $index => $product)
                    @if ($index % 3 === 0)
                        <tr>
                    @endif
                    <td>
                        <div>
                            @php
                                $image = $product->images->first();
                            @endphp

                             @if($image && Storage::disk('public')->exists($image->path))
                                @php
                                    $imagePath = Storage::disk('public')->path($image->path);
                                    $imageData = file_get_contents($imagePath);
                                    $imageSize = getimagesize($imagePath);
                                    $width = $imageSize[0];
                                    $height = $imageSize[1];
                                    // Resize image to a maximum width of 140px while maintaining aspect ratio
                                    if ($width > 140) {
                                        $height = (int) (140 / $width * $height);
                                        $width = 140;
                                    }
                                    $imageResized = imagecreatetruecolor($width, $height);
                                    $imageSource = imagecreatefromstring($imageData);
                                    imagecopyresampled($imageResized, $imageSource, 0, 0, 0, 0, $width, $height, $imageSize[0], $imageSize[1]);
                                    ob_start();
                                    imagejpeg($imageResized, null, 75); // 75 is the quality (0-100)
                                    $imageData = ob_get_clean();
                                    imagedestroy($imageResized);
                                    imagedestroy($imageSource);
                                @endphp
                                <img src="data:image/jpeg;base64,{{ base64_encode($imageData) }}" alt="Product Image" class="product-image"/>
                            @endif
                        </div>
                        <p class="product-name">{{ $product->name }}</p>
                        <p><strong>@lang('productpdfgenerate::app.catalog.products.index.sku')</strong> {{ $product->sku }}</p>
                        <p><strong>@lang('productpdfgenerate::app.catalog.products.index.price')</strong> ${{ number_format($product->price, 2) }}</p>
                    </td>
                    @if ($index % 3 === 2)
                        </tr>
                    @endif
                @endforeach

                @if ($products->count() % 3 !== 0)
                    @for ($i = 0; $i < (3 - ($products->count() % 3)); $i++)
                        <td></td>
                    @endfor
                    </tr>
                @endif
            </tbody>
        </table>
    </div>
</body>
</html>
