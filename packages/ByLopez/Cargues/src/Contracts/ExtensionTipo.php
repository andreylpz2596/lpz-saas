<?php

namespace ByLopez\Cargues\Contracts;

use ByLopez\Cargues\Models\Carga;
use Closure;

/**
 * Extension de un tipo de cargue (OPCIONAL; el motor sigue siendo generico). Se registra por nombre en
 * `cargues.extensiones` y el map del tipo la nombra con la clave `extension`. Existe para los casos en que una regla NO
 * puede vivir en un procedimiento SQL porque el proyecto ya la implementa en servicios de Laravel (normalizacion de telefonos,
 * catalogo geografico, alta de clientes con sus eventos): en vez de duplicarla o saltarsela, la extension la reutiliza.
 *
 * FRONTERA (docs/14):
 *  - `derivar` corre al PREPARAR la carga (antes de VALIDAR): solo LEE la BD y devuelve columnas derivadas por fila que el
 *    procedimiento usa para clasificar. VALIDAR sigue sin escribir nada de negocio.
 *  - `aplicar` corre en EJECUTAR, DESPUES de que el procedimiento confirmo su plan: escribe por los servicios de Laravel,
 *    una fila por transaccion, y deja la marca final de cada fila. Es REANUDABLE: solo procesa filas con `cg_estado` NULL y
 *    sus escrituras son idempotentes, asi que repetir la ejecucion no duplica nada.
 */
interface ExtensionTipo
{
    /**
     * @param  array<int, array<string,mixed>>  $filas  cg_carga_dato_id => valores normalizados de la fila
     * @return array<int, array<string,mixed>> cg_carga_dato_id => valores de los campos `derivados` del map
     */
    public function derivar(Carga $carga, array $filas): array;

    /**
     * Aplica las filas planeadas (cg_estado NULL con cg_detalle ya puesto por el procedimiento) y las marca. Debe ser idempotente.
     *
     * @param  Closure(int $procesadas, int $total): void  $progreso
     */
    public function aplicar(Carga $carga, array $map, Closure $progreso): void;
}
