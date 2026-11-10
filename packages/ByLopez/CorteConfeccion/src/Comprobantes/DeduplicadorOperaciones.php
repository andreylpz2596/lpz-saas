<?php

namespace ByLopez\CorteConfeccion\Comprobantes;

/**
 * Clasifica cada borrador de un lote frente al propio lote y frente a lo que el modulo ya registro:
 *   nueva              -> sin coincidencias
 *   posible_duplicado  -> coincidencia parcial (misma referencia con otro contexto, o mismo importe y fecha): requiere
 *                         revision humana y, si se continua, motivo auditado. NUNCA es certeza.
 *   duplicado          -> misma huella (referencia + banco + fecha + importe + moneda + direccion): no se contabiliza dos veces.
 * Una coincidencia con un movimiento REVERSADO se marca `reversado`: se conserva el vinculo y exige correccion explicita.
 */
class DeduplicadorOperaciones
{
    public const NUEVA = 'nueva';

    public const POSIBLE = 'posible_duplicado';

    public const DUPLICADO = 'duplicado';

    /**
     * @param  list<BorradorOperacion>  $lote
     * @return list<array{estado: string, coincidencias: list<array<string, mixed>>, en_lote_con: ?int}>
     */
    public function analizar(array $lote, FuenteOperacionesExistentes $fuente): array
    {
        $resultado = [];

        foreach ($lote as $i => $borrador) {
            $coincidencias = [];
            $estado = self::NUEVA;
            $enLoteCon = null;

            // Dentro del lote actual (una misma transferencia en dos capturas; o repetida por error).
            for ($j = 0; $j < $i; $j++) {
                $previo = $lote[$j];
                $huella = $borrador->huella();

                if ($huella !== null && $huella === $previo->huella()) {
                    $estado = self::DUPLICADO;
                    $enLoteCon = $j;
                } elseif ($estado !== self::DUPLICADO && $this->parecida($borrador, $previo)) {
                    $estado = self::POSIBLE;
                    $enLoteCon = $j;
                }
            }

            // Contra lo ya registrado, en TODOS los prestadores (puede haberse cargado en la persona equivocada).
            if ($huella = $borrador->huella()) {
                foreach ($fuente->operacionesPorHuella($huella) as $c) {
                    $coincidencias[] = $c + ['fuerza' => 'misma_huella'];
                    $estado = self::DUPLICADO;
                }
            }

            if ($estado !== self::DUPLICADO) {
                foreach ($fuente->operacionesCandidatas($borrador) as $c) {
                    $coincidencias[] = $c + ['fuerza' => 'parcial'];
                    $estado = self::POSIBLE;
                }
            }

            $resultado[] = ['estado' => $estado, 'coincidencias' => $coincidencias, 'en_lote_con' => $enLoteCon];
        }

        return $resultado;
    }

    /**
     * Parecidas pero sin certeza: misma referencia normalizada (con otro contexto), o mismo importe + fecha + moneda +
     * direccion cuando ALGUNA de las dos no tiene referencia (no hay forma de distinguirlas). Dos transferencias con
     * referencias distintas y legibles NO se consideran parecidas aunque tengan igual importe y fecha: pagar 15 veces
     * $ 20.000 el mismo dia es normal.
     */
    protected function parecida(BorradorOperacion $a, BorradorOperacion $b): bool
    {
        $ra = $a->referenciaNormalizada();
        $rb = $b->referenciaNormalizada();

        if ($ra !== null && $ra === $rb) {
            return true;
        }

        if ($ra !== null && $rb !== null) {
            return false;
        }

        return $a->valor !== null && $a->valor === $b->valor && $a->fecha !== null && $a->fecha === $b->fecha
            && $a->moneda === $b->moneda && $a->direccion === $b->direccion;
    }
}
