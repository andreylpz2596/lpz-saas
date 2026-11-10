<?php

/*
| Lectura asistida de comprobantes (docs/LECTURA_COMPROBANTES_PAGOS.md).
| `lector`: tesseract (local, por defecto: el archivo no sale del servidor) | anthropic (servicio externo, exige activarlo y
| una credencial) | ninguno. Si el lector elegido no esta disponible, el registro manual sigue funcionando.
*/
return [
    'lector' => env('CC_COMPROBANTES_LECTOR', 'tesseract'),

    // Tesseract local.
    'tesseract' => env('CC_TESSERACT_BIN'),
    'tessdata' => env('CC_TESSDATA', storage_path('app/tessdata')),
    // Carpeta de poppler (pdftotext y pdftoppm) para leer PDF; si esta en el PATH no hace falta.
    'poppler' => env('CC_POPPLER_DIR'),
    'max_paginas' => (int) env('CC_COMPROBANTES_MAX_PAGINAS', 10),
    // Tiempo maximo por archivo (segundos) y por proceso externo.
    'tiempo_max' => (int) env('CC_COMPROBANTES_TIEMPO_MAX', 120),

    // Servicio externo (opcional).
    'activo' => (bool) env('CC_COMPROBANTES_ACTIVO', false),
    'api_key' => env('ANTHROPIC_API_KEY'),
    'modelo' => env('CC_COMPROBANTES_MODELO', 'claude-sonnet-5-5'),
    'url' => env('CC_COMPROBANTES_URL', 'https://api.anthropic.com'),

    'timeout' => (int) env('CC_COMPROBANTES_TIMEOUT', 60),
    'max_mb' => (int) env('CC_COMPROBANTES_MAX_MB', 10),
    'max_archivos' => (int) env('CC_COMPROBANTES_MAX_ARCHIVOS', 20),
];
