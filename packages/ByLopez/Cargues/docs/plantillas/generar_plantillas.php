<?php

/*
 * Genera las plantillas del tipo «Productos — Camisetas piel de durazno» (docs/17 §2) con ejemplos SINTETICOS (prefijo EJEMPLO-).
 * Uso (desde la raiz del proyecto): php packages/ByLopez/Cargues/docs/plantillas/generar_plantillas.php
 * Los encabezados son las etiquetas del map: el paso «Relacionar columnas» los reconoce solos.
 */
require __DIR__.'/../../../../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$map = json_decode(file_get_contents(__DIR__.'/../../src/Database/Procedures/sp_cg_productos_camisetas_v1.map.json'), true);
$encabezados = array_column($map['campos'], 'etiqueta');

// Codigo base | SKU variante | Nombre | Publico | Color | Talla | Composicion | Manga | Cuello | Tela | Costo | Precio | Peso | Descripcion corta | Descripcion
$filas = [
    ['EJEMPLO-CAM-H01', 'EJEMPLO-CAM-H01-NEG-S', 'Camiseta Ejemplo Hombre Piel de Durazno', 'Hombre', 'Negro', 'S', '80% poliéster / 20% spandex', 'Manga Corta', 'Redondo', 'Piel Durazno', '5500', '8500', '0,17', '', ''],
    ['EJEMPLO-CAM-H01', 'EJEMPLO-CAM-H01-NEG-M', 'Camiseta Ejemplo Hombre Piel de Durazno', 'Hombre', 'Negro', 'M', '80% poliéster / 20% spandex', 'Manga Corta', 'Redondo', 'Piel Durazno', '5500', '8500', '0,17', '', ''],
    ['EJEMPLO-CAM-H01', 'EJEMPLO-CAM-H01-BLA-L', 'Camiseta Ejemplo Hombre Piel de Durazno', 'Hombre', 'Blanco', 'L', '80% poliéster / 20% spandex', 'Manga Corta', 'Redondo', 'Piel Durazno', '5500', '8500', '0,17', '', ''],
    ['EJEMPLO-CAM-D01', 'EJEMPLO-CAM-D01-ROS-S', 'Camiseta Ejemplo Dama Piel de Durazno', 'Dama', 'Rosado', 'S', '', 'Manga Corta', 'Cuello-V', '', '', '', '', '', ''],
    ['EJEMPLO-CAM-N01', 'EJEMPLO-CAM-N01-NEG-2-4', 'Camiseta Ejemplo Niño Piel de Durazno', 'Niño', 'Negro', '2-4', '', '', '', '', '', '', '', '', ''],
    ['EJEMPLO-CAM-D01', 'EJEMPLO-CAM-D01-ROS-M', 'Camiseta Ejemplo Dama Piel de Durazno', 'Dama', 'Rosado', 'M', '', 'Manga Corta', 'Cuello-V', '', '', '', '', '', ''],
];

$instrucciones = [
    ['Columna', '¿Obligatoria?', 'Qué escribir'],
    ['Código del producto base', 'Sí', 'SKU del producto padre. Todas las filas con el mismo código forman UN producto (sus variantes). Se escribe como texto: los ceros iniciales se conservan.'],
    ['SKU de la variante', 'Sí', 'SKU único de cada combinación color + talla. No puede repetirse ni pertenecer a otro producto.'],
    ['Nombre del producto', 'Sí', 'Nombre del producto padre; igual en todas sus filas. La variante se llama «nombre + color + talla» (el color no se repite si el nombre ya lo trae).'],
    ['Público (Hombre, Dama o Niño)', 'Sí', 'Hombre, Dama o Niño (también Caballero o Mujer). Define la familia de atributos y las categorías.'],
    ['Color', 'Sí', 'Texto exacto de una opción del atributo Color (p. ej. Negro, Azul Cielo, Curuba/Salmon). Si no existe, el producto se bloquea: créela en Catálogo › Atributos.'],
    ['Talla', 'Sí', 'Adulto (Hombre, Dama): S, M, L, XL, XXL, T-Unica. Niño: 2-4, 6-8, 10-12, 14-16 (grupo de edad, como el catálogo actual de niño). Una talla de adulto no vale para niño ni al revés.'],
    ['Composición, Manga, Cuello', 'No', 'Texto exacto de una opción existente. Vacío = dato pendiente (el producto se crea y se completa luego en Catálogo).'],
    ['Tipo de tela', 'No', 'Vacío = Piel Durazno (valor del tipo).'],
    ['Costo', 'No', 'Costo comisionable de la variante (8.500 o 8500). Vacío = sin costo. No escriba 0: en el aplicativo 0 significa «sin costo».'],
    ['Precio de venta', 'No', 'Precio de la variante. Vacío = el producto se crea INACTIVO hasta completarlo. No escriba 0.'],
    ['Peso (kg)', 'No', 'Peso de envío de la variante (0,17). Si falta, el producto se crea, pero Bagisto lo exige para guardar desde su formulario.'],
    ['Descripción corta, Descripción', 'No', 'Textos del producto. Si faltan, se arman solo con datos confirmados (nombre, público, tela, manga, cuello, composición y marca que existan) para que el producto se pueda guardar desde el administrador; reemplácelas en Catálogo cuando quiera.'],
    ['', '', '', '', ''],
    ['Reglas', '', 'Una fila por variante. Solo se crean las combinaciones que están en el archivo. Nunca se borran variantes que no aparezcan. Si un producto tiene un problema (opción desconocida, SKU de otro producto, datos distintos de los guardados), ese producto entero no se carga y los demás sí. El cargue no crea stock.'],
    ['Ejemplos', '', 'Las filas EJEMPLO-… son ficticias: bórrelas antes de cargar sus productos.'],
];

$libro = new Spreadsheet;
$hoja = $libro->getActiveSheet()->setTitle('Productos');
$hoja->fromArray($encabezados, null, 'A1');
$hoja->getStyle('A1:O1')->getFont()->setBold(true);

foreach ($filas as $i => $f) {
    foreach ($f as $c => $valor) {
        $celda = $hoja->getCell([$c + 1, $i + 2]);
        // Codigos, SKU y textos como TEXTO (sin perder ceros); importes como numero.
        in_array($c, [10, 11, 12], true) && $valor !== ''
            ? $celda->setValueExplicit((float) str_replace(',', '.', $valor), DataType::TYPE_NUMERIC)
            : $celda->setValueExplicit($valor, DataType::TYPE_STRING);
    }
}

$hoja->getStyle('A2:J1000')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
$hoja->getStyle('K2:L1000')->getNumberFormat()->setFormatCode('#,##0');

foreach (range('A', 'O') as $col) {
    $hoja->getColumnDimension($col)->setAutoSize(true);
}

$hoja->freezePane('A2');
$ayuda = $libro->createSheet()->setTitle('Instrucciones');
$ayuda->fromArray($instrucciones, null, 'A1');
$ayuda->getStyle('A1:C1')->getFont()->setBold(true);
$ayuda->getColumnDimension('A')->setWidth(32);
$ayuda->getColumnDimension('B')->setWidth(14);
$ayuda->getColumnDimension('C')->setWidth(110);
$ayuda->getStyle('C1:C30')->getAlignment()->setWrapText(true);
$libro->setActiveSheetIndex(0);
(new Xlsx($libro))->save(__DIR__.'/Plantilla_Productos_Camisetas_Piel_Durazno.xlsx');

// CSV: punto y coma, UTF-8 con BOM (Excel en español lo abre bien).
$csv = fopen(__DIR__.'/Plantilla_Productos_Camisetas_Piel_Durazno.csv', 'wb');
fwrite($csv, "\xEF\xBB\xBF");

foreach ([$encabezados, ...$filas] as $f) {
    fputcsv($csv, $f, ';', '"', '');
}

fclose($csv);
echo "plantillas generadas\n";
