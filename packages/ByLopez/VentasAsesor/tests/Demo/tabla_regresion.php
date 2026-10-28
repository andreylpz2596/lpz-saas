<?php

/*
| Genera la tabla «Prueba → resultado actual → causa → impacto → acción» (markdown, sin duplicados) a partir de los fallos de la corrida
| actual y de la comparacion con el respaldo. Uso: php tabla_regresion.php <actual.json> <respaldo.json>
| La clasificacion por archivo es MANUAL (revisada con el mensaje del fallo); lo que sale de la comparacion es automatico.
*/

$actual = json_decode((string) file_get_contents($argv[1]), true);
$respaldo = json_decode((string) file_get_contents($argv[2]), true);
$clave = fn (array $f) => basename(str_replace('\\', '/', explode('::', $f['archivo'])[0])).'::'.$f['prueba'];
$enRespaldo = array_flip(array_map($clave, $respaldo['fallos']));

// archivo => [clase, causa, impacto, accion]
$clases = [
    'AccesoInicioTest.php' => ['expectativa desactualizada', 'El menú y la ACL ya no se publican con la forma que asume la prueba (`Undefined array key "name"`; el archivo de ACL menciona el rol «ASESOR VENTAS»). Hipótesis: cambios de menú/ACL de jornadas anteriores sin actualizar la prueba.', 'Ninguno funcional demostrado; la prueba no protege lo que dice.', 'Reescribir la prueba con la ACL vigente (fuera del alcance de esta jornada; no se debilitó).'],
    'CierreAuditado02A2Test.php' => ['expectativa desactualizada', 'El inventario de llaves foráneas del esquema cambió (arreglo distinto del esperado).', 'Ninguno funcional demostrado.', 'Actualizar el inventario esperado tras revisar las llaves nuevas.'],
    'Com03ReglasComisionTest.php' => ['por investigar', 'COM03-1: `Failed asserting that true is false`. Falla igual en el respaldo.', 'Cubre la regla vigente de comisión; el flujo completo está cubierto por Com04 (pasa).', 'Investigar en una jornada propia; no se tocó.'],
    'F1DomiciliarioNuevaVentaTest.php' => ['expectativa desactualizada', 'Espera la tarifa de domicilio por domiciliario+zona del valor genérico; desde «envío local por origen» la tarifa y la disponibilidad salen de bodega+destino+zonas del origen.', 'Ninguno: la regla vigente tiene sus propias pruebas (Log06 F1-*, pasan).', 'Reescribir con la regla por origen.'],
    'Fase03dEntregaBorradorTest.php' => ['expectativa desactualizada', '`metodo_entrega_no_disponible` / `precio` nulo: la prueba arma un domicilio local con la configuración genérica (core_config) que ya no habilita el método (local = origen + destino + zonas del origen).', 'Ninguno funcional demostrado (el flujo real se ejerce en los recorridos A–J).', 'Reescribir con zonas locales por origen.'],
    'Fase03fConfirmacionVentaTest.php' => ['expectativa desactualizada (3) · orden de listeners (1)', 'Tres usan domicilio local con la configuración genérica (mismo motivo que Fase03d). `03F-45` compara el orden de listeners de `sales.order.save.*` y ya difiere (otros listeners registrados después de GRP-01).', 'Ninguno demostrado; el orden efectivo lo cubren F5/03F-37 (pasan).', 'Actualizar la lista esperada de listeners y la configuración local.'],
    'Fase03gbNuevaVentaTest.php' => ['expectativa desactualizada', 'Mismo motivo: `metodo_entrega_no_disponible` con domicilio local por configuración genérica.', 'Ninguno funcional demostrado.', 'Reescribir con zonas locales por origen.'],
    'Fase03gcDireccionNuevaVentaTest.php' => ['expectativa desactualizada', 'HTTP 422 al elegir domicilio local: el método no está disponible con la configuración que arma la prueba.', 'Ninguno funcional demostrado.', 'Reescribir con zonas locales por origen.'],
    'Fase03gdEntregaDireccionPagoTest.php' => ['expectativa desactualizada', 'HTTP 422 al elegir domicilio local (métodos no disponibles con la configuración genérica que arma la prueba); incluye las pruebas de dirección C/D, L, H, N.', 'Ninguno funcional demostrado.', 'Reescribir con zonas locales por origen.'],
    'Fase03gdPantallaNuevaVentaTest.php' => ['por investigar', 'HTTP 500 al renderizar la pantalla de Nueva venta en la prueba; falla igual en el respaldo. Los recorridos A–J la usan por API, y la matriz visual la abrió sin error (200).', 'Posible dependencia de datos del entorno de pruebas; la pantalla real responde 200 en la demo.', 'Investigar el 500 en el entorno de pruebas (jornada propia).'],
    'IntegridadAtribucionTest.php' => ['expectativa desactualizada (demostrado)', '«ninguna migración del módulo altera tablas del core»: se esperan 8 migraciones y hoy hay 33 (el módulo creció).', 'Ninguno: la regla (no tocar el core) se cumple; `packages/Webkul` sin cambios.', 'Actualizar el conteo/lista esperada.'],
    'MonedaMascaraVistasTest.php' => ['por investigar', 'M-2: el valor crudo guardado difiere del enviado (arreglos distintos). Falla igual en el respaldo.', 'Posible efecto de la configuración de moneda/máscara del entorno de pruebas.', 'Investigar (jornada propia).'],
    'PagBModeloPersistenteTest.php' => ['expectativa desactualizada', 'Textos «hasta PAG-C solo existe registrar…», unicidad de códigos de medio de cobro y ACL de cartera: el módulo avanzó (PAG-C…PAG-I existen) y las listas esperadas quedaron viejas.', 'Ninguno: los servicios de pago están cubiertos por PagC/E/F/G (pasan).', 'Actualizar las listas esperadas.'],
    'PagDAbonoClienteTest.php' => ['expectativa desactualizada', 'ACL y alcance (`abono_general`, `view_own` / `view_all`): la pantalla de Cartera devolvió HTML distinto del esperado. Falla igual en el respaldo.', 'Ninguno funcional demostrado; las pruebas de abono por servicio pasan.', 'Actualizar con la ACL vigente. (En el respaldo fallaban además 2 pruebas de este archivo por la cuenta global de 6.360 pagos; hoy pasan: dependen del estado de la base.)'],
    'PagoComprobanteTest.php' => ['contaminación de datos (demostrada)', 'Cuenta global: `Failed asserting that 6360 is identical to 0` — la prueba cuenta pagos de TODA la base y `lpz_saas_testing` conserva 6.360 pagos clonados de la auditoría E2E.', 'Ninguno funcional; ver acción.', 'Contar solo lo creado por la prueba (no se debilitó la verificación).'],
    'Rt04b1aGuardNotificacionesTest.php' => ['por investigar', '`ShippedNotification` encolada 2 veces en vez de 1. Falla igual en el respaldo (antes de los listeners de esta jornada).', 'Posible doble aviso de envío al cliente: revisar antes de producción.', 'Investigar en una jornada propia (no se amplió el alcance).'],
];

$porArchivo = [];

foreach ($actual['fallos'] as $f) {
    $archivo = basename(str_replace('\\', '/', explode('::', $f['archivo'])[0]));
    $porArchivo[$archivo][] = $f;
}

ksort($porArchivo);
echo "| Archivo de prueba | Fallos | Clase | Causa (demostrada o hipótesis) | ¿Falla igual en el respaldo? | Impacto | Acción |\n|---|---|---|---|---|---|---|\n";
$total = 0;

foreach ($porArchivo as $archivo => $lista) {
    $n = count($lista);
    $total += $n;
    $previos = count(array_filter($lista, fn ($f) => isset($enRespaldo[basename(str_replace('\\', '/', explode('::', $f['archivo'])[0])).'::'.$f['prueba']])));
    [$clase, $causa, $impacto, $accion] = $clases[$archivo] ?? ['SIN CLASIFICAR', $lista[0]['mensaje'], '?', '?'];
    echo sprintf("| `%s` | %d | %s | %s | %d de %d | %s | %s |\n", $archivo, $n, $clase, $causa, $previos, $n, $impacto, $accion);
}

echo "| **Total** | **{$total}** | | | | | |\n";
