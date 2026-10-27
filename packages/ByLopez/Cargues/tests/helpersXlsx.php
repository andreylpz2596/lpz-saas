<?php

/*
| Generadores de XLSX SINTETICOS (XML escrito a mano, con la forma que usa Excel o una exportacion web). Sin datos reales.
*/

function cg_x(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

/** Contenedor minimo de un XLSX con las hojas dadas (nombre => xml de la hoja). */
function cg_zip_xlsx(string $ruta, array $hojas, ?string $sharedStrings = null, ?string $estilos = null, bool $f1904 = false): void
{
    $z = new ZipArchive;
    $z->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $sheets = '';
    $i = 0;

    foreach ($hojas as $nombre => $xml) {
        $i++;
        $ct .= "<Override PartName=\"/xl/worksheets/sheet{$i}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        $rels .= "<Relationship Id=\"rId{$i}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$i}.xml\"/>";
        $sheets .= '<sheet name="'.cg_x($nombre)."\" sheetId=\"{$i}\" r:id=\"rId{$i}\"/>";
        $z->addFromString("xl/worksheets/sheet{$i}.xml", $xml);
    }

    $rels .= '<Relationship Id="rId90" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

    if ($sharedStrings !== null) {
        $ct .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
        $rels .= '<Relationship Id="rId91" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
        $z->addFromString('xl/sharedStrings.xml', $sharedStrings);
    }

    $z->addFromString('[Content_Types].xml', $ct.'</Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><workbookPr'.($f1904 ? ' date1904="1"' : '').'/><sheets>'.$sheets.'</sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', $rels.'</Relationships>');
    $z->addFromString('xl/styles.xml', $estilos ?? '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs></styleSheet>');
    $z->close();
}

function cg_hoja_xml(string $filas, ?string $dimension = null): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .($dimension ? '<dimension ref="'.$dimension.'"/>' : '').'<sheetData>'.$filas.'</sheetData></worksheet>';
}

/**
 * Fixture de fidelidad (08 §3.8): hojas «Resumen» y «Envios»; encabezados en la fila 3; fila 5 ausente; fechas con
 * varios formatos; cadenas compartidas, en linea y enriquecidas; formulas con y sin cache; error; bool; numeros.
 */
function cg_xlsx_fidelidad(string $ruta, bool $f1904): void
{
    $largo = str_repeat('abcdefghij', 500);
    $ss = ['Reporte de envíos', 'Guía', 'Teléfono', 'Fecha', 'Fecha y hora', 'Recaudo', 'Porcentaje', 'Nota', 'Fórmula', 'Activo', 'Ciudad', 'Vacía', '0012345678', '+573001234567'];
    $si = implode('', array_map(fn ($t) => '<si><t>'.cg_x($t).'</t></si>', $ss))
        .'<si><r><t xml:space="preserve">Hola </t></r><r><rPr><b/></rPr><t>mundo</t></r></si>'   // 14: enriquecida
        .'<si><t>'.cg_x('=HYPERLINK("http://x")').'</t></si>'                                      // 15
        .'<si><t>'.$largo.'</t></si>'                                                              // 16
        .'<si><t>Hoja de resumen</t></si>';                                                        // 17
    $d = $f1904 ? 1462 : 0;
    $s = fn (string $r, int $i, int $st = 0) => "<c r=\"{$r}\" t=\"s\"".($st ? " s=\"{$st}\"" : '')."><v>{$i}</v></c>";
    $n = fn (string $r, string $v, int $st = 0) => "<c r=\"{$r}\"".($st ? " s=\"{$st}\"" : '')."><v>{$v}</v></c>";
    $is = fn (string $r, string $t) => "<c r=\"{$r}\" t=\"inlineStr\"><is><t xml:space=\"preserve\">".cg_x($t).'</t></is></c>';

    $filas = '<row r="1">'.$s('A1', 0).'</row>'
        .'<row r="3">'.$s('A3', 1).$s('B3', 2).$s('C3', 3).$s('D3', 4).$s('E3', 5).$s('F3', 6).$s('G3', 7).$s('H3', 8).$s('I3', 9).$s('J3', 10).$s('K3', 1).$s('L3', 11).'</row>'
        .'<row r="4">'.$s('A4', 12, 3).$s('B4', 13).$n('C4', (string) (45931 - $d), 1).$n('D4', (string) (45931.5 - $d), 2).$n('E4', '138000.5', 4).$n('F4', '0.15', 5)
        .$s('G4', 14).'<c r="H4"><f>1+1</f><v>2</v></c><c r="I4" t="b"><v>1</v></c>'.$is('J4', 'Bogotá D.C. – ñ').$n('K4', '9912345678901', 7).'</row>'
        .'<row r="6">'.$s('A6', 15).$n('B6', '3001234567').$n('C6', (string) (45931 - $d), 6).'<c r="D6"/>'.$n('E6', '0.30000000000000004')
        .'<c r="H6"><f>A6&amp;"x"</f></c>'.$is('J6', '  espacios  ').'</row>'
        .'<row r="7">'.$s('A7', 16).$n('B7', '1.2345678901234568E+18').'<c r="C7" s="1"><v>60</v></c><c r="E7" t="e"><v>#N/A</v></c>'.$n('K7', '0').'</row>';

    $estilos = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .'<numFmts count="2"><numFmt numFmtId="164" formatCode="dd/mm/yyyy\ hh:mm"/><numFmt numFmtId="165" formatCode="yyyy\-mm\-dd"/></numFmts>'
        .'<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
        .'<borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        .'<cellXfs count="8"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        .'<xf numFmtId="14" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        .'<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        .'<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        .'<xf numFmtId="10" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        .'<xf numFmtId="1" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs></styleSheet>';

    cg_zip_xlsx($ruta, ['Resumen' => cg_hoja_xml('<row r="1">'.$s('A1', 17).'</row>'), 'Envios' => cg_hoja_xml($filas)],
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="18" uniqueCount="18">'.$si.'</sst>', $estilos, $f1904);
}

/**
 * Hoja con la estructura de una EXPORTACION WEB (SheetJS): todo `t="str"`, sin cadenas compartidas ni formulas.
 * `$celdas`: lista de filas, cada una lista de textos (null = celda ausente). Devuelve el numero de celdas escritas.
 */
function cg_xlsx_sheetjs(string $ruta, array $celdas, ?string $dimension = null): int
{
    $filas = '';
    $total = 0;

    foreach ($celdas as $i => $fila) {
        $r = $i + 1;
        $xml = '';

        foreach ($fila as $j => $v) {
            if ($v === null) {
                continue;
            }

            $col = '';

            for ($k = $j + 1; $k > 0; $k = intdiv($k - 1, 26)) {
                $col = chr(65 + ($k - 1) % 26).$col;
            }

            $xml .= "<c r=\"{$col}{$r}\" t=\"str\"><v>".cg_x((string) $v).'</v></c>';
            $total++;
        }

        $filas .= "<row r=\"{$r}\">{$xml}</row>";
    }

    cg_zip_xlsx($ruta, ['Envíos Completos' => cg_hoja_xml($filas, $dimension)]);

    return $total;
}

function cg_ruta_tmp(string $nombre): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cargues_pruebas';
    @mkdir($dir, 0777, true);

    return $dir.DIRECTORY_SEPARATOR.uniqid().'_'.$nombre;
}
