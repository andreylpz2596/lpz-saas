<?php

namespace ByLopez\Logistica\Services\Guias;

use ByLopez\Logistica\Exceptions\OperacionLogisticaInvalidaException as Invalida;
use ByLopez\Logistica\Models\Guia;
use ByLopez\Logistica\Models\GuiaCorreccion;
use ByLopez\Logistica\Services\Cuentas\GeneradorMovimientosCuenta;
use ByLopez\Logistica\Services\Despachos\DespachoService;
use ByLopez\Logistica\Services\Transportadoras\TransportadorasService;
use ByLopez\Logistica\Support\AutorizacionLogistica;
use ByLopez\Logistica\Support\ConsecutivoGuiaInterna;
use ByLopez\Logistica\Support\FuentePanel;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Webkul\User\Contracts\Admin;

/**
 * CORREGIR GUIA. Una guia mal digitada se corrige sin tocar el despacho: el Shipment (salida de inventario) y el numero
 * de guia son conceptos distintos.
 *
 * Cada correccion es una accion EXPLICITA y AUDITADA: motivo obligatorio, quien y cuando, y la fila de historial (solo de
 * adicion) con numero y transportadora anterior/nueva. La guia conserva el valor VIGENTE; `shipments.track_number`,
 * `carrier_code` y `carrier_title` se sincronizan con el.
 *
 * NO crea otro Shipment, no descuenta inventario, no cambia cantidades, no registra ventas y no toca Cartera: solo
 * escribe `bylopez_lg_guias`, `bylopez_lg_guia_correcciones` y las tres columnas de texto del Shipment que ya existian.
 * El PDF no se toca (su reemplazo es otro flujo, con su propio motivo).
 *
 * GUIA INTERNA (GI-000001): su numero lo asigna el sistema y NO se corrige (tambien lo impide el modelo). Una correccion nunca
 * convierte una guia interna en externa ni viceversa: se decide por el origen GUARDADO en la guia, y la transportadora nueva
 * debe usar el mismo tipo de guia. Los numeros GI-… estan reservados: una guia externa no puede escribirlos.
 */
class CorregirGuiaService
{
    public function __construct(
        protected AutorizacionLogistica $autorizacion,
        protected TransportadorasService $transportadoras,
        protected GeneradorMovimientosCuenta $cuentas,
    ) {}

    /**
     * @param  string|null  $numeroNuevo  null o igual al vigente = no cambia el numero
     * @param  string|null  $transportadoraNueva  null o igual a la vigente = no cambia la transportadora
     */
    public function corregir(?Admin $actor, int $guiaId, ?string $numeroNuevo, ?string $transportadoraNueva, ?string $motivo): GuiaCorreccion
    {
        if (! $this->autorizacion->tienePermiso($actor, AutorizacionLogistica::PERMISO_GUIAS_CORREGIR)) {
            throw new Invalida(Invalida::SIN_PERMISO);
        }

        $motivo = trim((string) $motivo);

        if ($motivo === '') {
            throw new Invalida(Invalida::MOTIVO_REQUERIDO);
        }

        try {
            return DB::transaction(function () use ($actor, $guiaId, $numeroNuevo, $transportadoraNueva, $motivo) {
                $guia = Guia::query()->lockForUpdate()->find($guiaId) ?? throw new Invalida(Invalida::GUIA_INEXISTENTE);

                $interna = $guia->esInterna();
                $numero = $this->numero($numeroNuevo, $guia->numero_guia, $interna);
                $codigo = $this->transportadora($transportadoraNueva, $guia->transportadora_codigo, $interna);

                if ($numero === $guia->numero_guia && $codigo->codigo === $guia->transportadora_codigo) {
                    throw new Invalida(Invalida::SIN_CAMBIOS);
                }

                if (Guia::query()->where('transportadora_codigo', $codigo->codigo)->where('numero_guia', $numero)->whereKeyNot($guia->id)->exists()) {
                    throw new Invalida(Invalida::GUIA_DUPLICADA, ['numero_guia' => $numero]);
                }

                $correccion = GuiaCorreccion::query()->create([
                    'guia_id' => $guia->id,
                    'order_id' => $guia->order_id,
                    'shipment_id' => $guia->shipment_id,
                    'numero_anterior' => $guia->numero_guia,
                    'numero_nuevo' => $numero,
                    'transportadora_anterior' => $guia->transportadora_codigo,
                    'transportadora_nueva' => $codigo->codigo,
                    'motivo' => mb_substr($motivo, 0, 500),
                    'actor_admin_id' => (int) $actor->id,
                    'actor_name_snapshot' => mb_substr((string) $actor->name, 0, 191),
                ]);

                Guia::corrigiendo(fn () => $guia->forceFill([
                    'numero_guia' => $numero,
                    'transportadora_codigo' => $codigo->codigo,
                ])->save());

                // El Shipment refleja la guia VIGENTE. Solo texto: ni cantidades, ni inventario, ni estado del pedido.
                DB::table('shipments')->where('id', $guia->shipment_id)->update([
                    'track_number' => $numero,
                    'carrier_code' => $codigo->codigo,
                    'carrier_title' => $codigo->nombre,
                ]);

                // Cuenta corriente: si cambio la transportadora, lo generado se revierte a la anterior y pasa a la nueva (si liquida).
                if ($codigo->codigo !== $correccion->transportadora_anterior) {
                    $this->cuentas->sincronizarEnvio((int) $guia->shipment_id, $actor);
                }

                return $correccion;
            });
        } catch (UniqueConstraintViolationException) {
            throw new Invalida(Invalida::GUIA_DUPLICADA, ['numero_guia' => (string) $numeroNuevo]);
        }
    }

    private function numero(?string $nuevo, string $vigente, bool $interna): string
    {
        $nuevo = trim((string) $nuevo);

        if ($nuevo === '' || $nuevo === $vigente) {
            return $vigente;
        }

        // El numero GI es inmutable: cualquier otro valor se rechaza, no se ignora en silencio.
        if ($interna) {
            throw new Invalida(Invalida::GUIA_INTERNA_INMUTABLE);
        }

        if (! preg_match(DespachoService::PATRON_GUIA, $nuevo)) {
            throw new Invalida(Invalida::DATOS_INVALIDOS, ['campo' => 'numero_guia']);
        }

        if (ConsecutivoGuiaInterna::esReservado($nuevo)) {
            throw new Invalida(Invalida::GUIA_RESERVADA, ['numero_guia' => $nuevo]);
        }

        return $nuevo;
    }

    /** Sin cambio devuelve la vigente (aunque ya este inactiva en el catalogo); una nueva debe estar ACTIVA y no ser recogida. */
    private function transportadora(?string $nueva, string $vigente, bool $interna): object
    {
        $nueva = trim((string) $nueva);

        if ($nueva === '' || $nueva === $vigente) {
            return (object) [
                'codigo' => $vigente,
                'nombre' => $this->transportadoras->nombreDe($vigente) ?? $vigente,
            ];
        }

        if ($nueva === FuentePanel::TRANSPORTADORA_RECOGIDA) {
            throw new Invalida(Invalida::TRANSPORTADORA_INVALIDA, ['codigo' => $nueva]);
        }

        $destino = $this->transportadoras->activaPorCodigo($nueva) ?? throw new Invalida(Invalida::TRANSPORTADORA_INVALIDA, ['codigo' => $nueva]);

        // Corregir no cambia el TIPO de guia: una interna solo pasa a otra transportadora con guia interna, y una externa solo a
        // una con guia externa. Se compara contra el origen guardado en la guia, no contra la transportadora anterior.
        if ((bool) $destino->genera_guia_interna !== $interna) {
            throw new Invalida(Invalida::GUIA_CAMBIO_DE_TIPO, ['codigo' => $nueva]);
        }

        return $destino;
    }
}
