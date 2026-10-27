<?php

namespace ByLopez\Cargues\Extensiones\ClientesHistoricos;

use ByLopez\Cargues\Contracts\ExtensionTipo;
use ByLopez\Cargues\Exceptions\OperacionInvalidaException;
use ByLopez\Cargues\Models\Carga;
use ByLopez\Cargues\Services\Configuracion\ConexionTipo;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Extension del tipo «Clientes historicos — 99 Envios» (docs/14). REUTILIZA los servicios que el proyecto ya tiene para clientes
 * comerciales (VentasAsesor): normalizador de telefonos E.164, localizador de titular, alta de cliente sin cuenta ecommerce,
 * direcciones con el catalogo DIVIPOLA y los antecedentes de envios. Nada se escribe por INSERT directo ni se saltan reglas.
 *
 *  derivar(): al PREPARAR la carga (solo lectura) calcula por fila: telefono E.164 y su estado, nombre y apellido (convencion de
 *             `Nombres`), clave de nombre, cliente existente por telefono y si su nombre es compatible, municipio DIVIPOLA
 *             (solo si el catalogo lo permite sin ambiguedad) y transportadora efectiva (equivalencia explicita).
 *  aplicar(): tras el plan del procedimiento, UNA TRANSACCION POR FILA con la marca de la fila dentro de ella: cliente (crear o
 *             completar), direccion y antecedente. Un rechazo revierte la fila entera. Repetible: no duplica nada.
 *
 * NO crea pedidos, entregas, pagos, abonos ni comisiones, ni asigna correo, documento, contrasena o ubicaciones inventadas.
 */
class ClientesHistoricosExtension implements ExtensionTipo
{
    public function __construct(protected ConexionTipo $conexiones) {}

    // ---------------------------------------------------------------------------------------------------------------------
    // Derivar (solo lectura)
    // ---------------------------------------------------------------------------------------------------------------------

    public function derivar(Carga $carga, array $filas): array
    {
        $this->exigirModulo();
        $normalizador = app(\ByLopez\VentasAsesor\Contracts\NormalizadorTelefono::class);
        $localizador = app(\ByLopez\VentasAsesor\Support\Telefono\LocalizadorTitularTelefono::class);
        $equivalencias = DB::table('bylopez_lg_proveedor_transportadoras')->where('proveedor', $this->proveedor())->where('activo', 1)
            ->get(['codigo_externo', 'nombre_externo', 'transportadora_codigo']);
        $cacheGeo = [];
        $salida = [];
        $nombresPorTelefono = $this->nombresPorTelefono($carga, $normalizador);

        foreach ($filas as $id => $v) {
            $nombre = trim((string) ($v['nombre_destinatario'] ?? ''));
            $partes = Nombres::partir($nombre);
            $tel = $normalizador->normalizar(isset($v['telefono_destinatario']) ? (string) $v['telefono_destinatario'] : null);
            $cliente = null;
            $estadoCliente = 'sin_cliente';

            if ($tel->esBuscable()) {
                try {
                    $titular = $localizador->titular($tel);
                } catch (\ByLopez\VentasAsesor\Exceptions\TelefonoEnVariasFichasException) {
                    $titular = null;
                    $estadoCliente = 'varias_fichas';
                }

                if ($titular) {
                    $c = \Webkul\Customer\Models\CustomerProxy::modelClass()::find($titular->customerId);
                    $cliente = $c?->id;
                    $estadoCliente = $c && Nombres::compatibles(self::nombreDeCliente($c), $nombre) ? 'coincide' : 'nombre_distinto';
                }
            }

            $tr = trim((string) ($v['transportadora'] ?? ''));
            $trClave = '';
            $trEstado = 'sin_dato';

            if ($tr !== '') {
                $coinciden = $equivalencias->filter(fn ($e) => strcmp((string) $e->codigo_externo, $tr) === 0 || strcmp((string) $e->nombre_externo, $tr) === 0);
                $trEstado = match ($coinciden->count()) {
                    0 => 'sin_equivalencia', 1 => 'ok', default => 'ambigua',
                };
                $trClave = $coinciden->count() === 1 ? (string) $coinciden->first()->transportadora_codigo : '';
            }

            $llaveGeo = ($v['ciudad_destino'] ?? '').'|'.($v['departamento'] ?? '');
            $cacheGeo[$llaveGeo] ??= $this->ubicacion($v['ciudad_destino'] ?? null, $v['departamento'] ?? null);

            $salida[$id] = [
                'telefono_e164' => $tel->normalizado,
                'telefono_estado' => $tel->esBuscable() ? 'ok' : ($tel->motivo ?? $tel->estado->value),
                // 1 = todos los nombres del archivo con ese telefono son COMPATIBLES (variantes del mismo nombre); 0 = hay nombres que no lo son (telefono compartido).
                'telefono_nombres_ok' => $tel->esBuscable() ? (int) ($nombresPorTelefono[$tel->normalizado] ?? true) : null,
                'nombre_clave' => Nombres::clave($nombre) ?: null,
                'nombre_pila' => $partes[0] ?? null,
                'apellido' => $partes[1] ?? null,
                'cliente_id' => $cliente,
                'cliente_estado' => $estadoCliente,
                'municipio_codigo' => $cacheGeo[$llaveGeo][0],
                'geo_estado' => $cacheGeo[$llaveGeo][1],
                'transportadora_clave' => $trClave,
                'transportadora_estado' => $trEstado,
            ];
        }

        return $salida;
    }

    /**
     * Por telefono E.164: ¿todos los nombres de ESTE archivo que lo usan son IGUALES entre si tras normalizar mayusculas, tildes y espacios? «JUAN  PÉREZ» y
     * «Juan Perez» si; «Juan Perez» y «Juan Carlos Perez» (palabras anadidas u omitidas) NO: quedan para revision. Lee el archivo completo (T6) en cada lote (sin cache: una carga se puede preparar de nuevo).
     *
     * @return array<string, bool>
     */
    protected function nombresPorTelefono(Carga $carga, $normalizador): array
    {
        $porTel = [];

        foreach (DB::table('bylopez_cg_carga_datos')->where('carga_id', $carga->id)->where('estado_final', 'pendiente')->pluck('datos_normalizados') as $json) {
            $v = (array) (json_decode((string) $json, true)['valores'] ?? []);
            $t = $normalizador->normalizar(isset($v['telefono_destinatario']) ? (string) $v['telefono_destinatario'] : null);

            if ($t->esBuscable() && isset($v['nombre_destinatario'])) {
                $porTel[$t->normalizado][] = (string) $v['nombre_destinatario'];
            }
        }

        $ok = [];

        foreach ($porTel as $tel => $nombres) {
            $nombres = array_values(array_unique($nombres));
            $ok[$tel] = true;

            foreach ($nombres as $i => $a) {
                foreach (array_slice($nombres, $i + 1) as $b) {
                    if (! Nombres::compatibles($a, $b)) {
                        $ok[$tel] = false;
                    }
                }
            }
        }

        return $ok;
    }

    /**
     * Municipio DIVIPOLA SOLO si el catalogo lo permite sin ambiguedad. La exportacion trae «CIUDAD/NOMBRE OFICIAL»: se prueba
     * primero el oficial. Con departamento en el archivo se usa para desambiguar; sin el, un nombre repetido es ambiguo.
     *
     * @return array{0: ?string, 1: string} [codigo|null, ok|sin_ciudad|ambigua|no_encontrada]
     */
    protected function ubicacion(?string $ciudad, ?string $departamento): array
    {
        $ciudad = trim((string) $ciudad);

        if ($ciudad === '') {
            return [null, 'sin_ciudad'];
        }

        $dep = trim((string) $departamento) === '' ? null : \ByLopez\VentasAsesor\Support\Geo\NombresGeograficos::busqueda((string) $departamento);

        foreach (array_reverse(array_map('trim', explode('/', $ciudad))) as $nombre) {
            $b = \ByLopez\VentasAsesor\Support\Geo\NombresGeograficos::busqueda($nombre);

            if ($b === '') {
                continue;
            }

            $q = DB::table('bylopez_geo_municipios as m')->join('bylopez_geo_departamentos as d', 'd.codigo_divipola', '=', 'm.departamento_codigo')
                ->where('m.activo', 1)->where('m.nombre_busqueda', $b);

            if ($dep !== null) {
                $q->where(fn ($w) => $w->where('d.nombre_busqueda', $dep)->orWhere('d.codigo_divipola', $dep));
            }

            $codigos = $q->pluck('m.codigo_divipola')->all();

            if (count($codigos) === 1) {
                return [(string) $codigos[0], 'ok'];
            }

            if (count($codigos) > 1) {
                return [null, 'ambigua'];
            }
        }

        return [null, 'no_encontrada'];
    }

    // ---------------------------------------------------------------------------------------------------------------------
    // Aplicar (servicios de Laravel, una transaccion por fila)
    // ---------------------------------------------------------------------------------------------------------------------

    public function aplicar(Carga $carga, array $map, Closure $progreso): void
    {
        $this->exigirModulo();
        $cfg = (array) $carga->config_congelada;
        $tabla = (string) $map['tabla'];
        $this->exigirMismaBase((string) $cfg['conexion']);

        $total = (int) DB::table($tabla)->where('cg_carga_id', $carga->id)->whereNull('cg_estado')->count();
        $hechas = 0;
        $progreso(0, $total);

        while (true) {
            $filas = DB::table($tabla)->where('cg_carga_id', $carga->id)->whereNull('cg_estado')->orderBy('cg_fila_origen')->limit(100)->get();

            if ($filas->isEmpty()) {
                break;
            }

            foreach ($filas as $f) {
                try {
                    $r = DB::transaction(fn () => $this->aplicarFila($carga, $tabla, $f));
                } catch (RechazoFila $e) {
                    $r = ['rechazada', null, $e->codigo, $e->getMessage()];
                    $this->marcar($tabla, (int) $f->cg_id, ...$r);
                } catch (Throwable $e) {
                    report($e);
                    $this->marcar($tabla, (int) $f->cg_id, 'rechazada', null, 'ERROR_INESPERADO', 'Error inesperado al aplicar la fila ('.class_basename($e).'); no se aplicó nada de esta fila.');
                }

                $progreso(++$hechas, $total);
            }
        }
    }

    /** @return array{0:string,1:?string,2:?string,3:?string} [estado, detalle, codigo, mensaje] ya marcados dentro de la transaccion */
    protected function aplicarFila(Carga $carga, string $tabla, object $f): array
    {
        $normalizador = app(\ByLopez\VentasAsesor\Contracts\NormalizadorTelefono::class);
        $localizador = app(\ByLopez\VentasAsesor\Support\Telefono\LocalizadorTitularTelefono::class);
        $antecedentes = app(\ByLopez\VentasAsesor\Services\Clientes\AntecedentesEnviosClienteService::class);
        $pendientes = app(\ByLopez\VentasAsesor\Services\Clientes\DireccionesPendientesClienteService::class);

        // 1. Cliente: se resuelve DE NUEVO (el plan pudo quedar viejo) y nunca se unen personas por el nombre.
        $tel = $normalizador->normalizar((string) $f->telefono_e164);

        if (! $tel->esBuscable()) {
            throw new RechazoFila('TELEFONO_NO_VALIDO', 'El teléfono no se pudo normalizar.');
        }

        try {
            $titular = $localizador->titular($tel);
        } catch (\ByLopez\VentasAsesor\Exceptions\TelefonoEnVariasFichasException) {
            throw new RechazoFila('TELEFONO_EN_VARIAS_FICHAS', 'Ese teléfono figura en varias fichas de cliente: identidad ambigua, revise manualmente.');
        }

        $creado = false;
        $obs = [];                                                    // observaciones [codigo, mensaje]: la fila se APLICA igual

        if ($titular) {
            $cliente = \Webkul\Customer\Models\CustomerProxy::modelClass()::find($titular->customerId);

            if (! $cliente || ! Nombres::compatibles(self::nombreDeCliente($cliente), (string) $f->nombre_destinatario)) {
                throw new RechazoFila('CLIENTE_NOMBRE_DISTINTO', 'Ya existe un cliente con ese teléfono y su nombre no coincide con el del archivo: puede ser un teléfono compartido. No se unen personas por el teléfono.');
            }

            $customerId = (int) $cliente->id;
        } else {
            // customer.create.after tiene UN listener (Admin\Listeners\Customer): encola un correo al administrador si el aviso esta activo.
            // Un cargue masivo no envia correos ni notificaciones: con el aviso activo, SOLO la operacion de crear queda bloqueada.
            if (! config('cargues.clientes_historicos.permitir_aviso_admin') && core()->getConfigData('emails.general.notifications.emails.general.notifications.customer_registration_confirmation_mail_to_admin')) {
                throw new RechazoFila('CREAR_CLIENTE_BLOQUEADO_NOTIFICACION', 'El aviso por correo al administrador por cliente nuevo está activo: crear clientes lo enviaría por cada fila. Apáguelo (Configuración › Correos) o permítalo en cargues.clientes_historicos.permitir_aviso_admin. Completar clientes existentes sigue funcionando.');
            }

            $grupo = DB::table('customer_groups')->where('code', (string) config('cargues.clientes_historicos.grupo_codigo'))->first();

            if (! $grupo) {
                throw new RechazoFila('CREAR_CLIENTE_BLOQUEADO_SIN_GRUPO', 'No existe el grupo de cliente «'.config('cargues.clientes_historicos.grupo_codigo').'» configurado para crear clientes importados. Defina CARGUES_CLIENTES_HISTORICOS_GRUPO.');
            }

            try {
                $nuevo = app(\ByLopez\VentasAsesor\Services\Clientes\CrearClienteComercialService::class)->crear(new \ByLopez\VentasAsesor\DataObjects\DatosClienteComercial(
                    nombre: (string) $f->nombre_pila, apellido: (string) ($f->apellido ?? \ByLopez\VentasAsesor\Services\Clientes\DatosPendientesClienteService::APELLIDO_PENDIENTE), telefono: (string) $f->telefono_e164, customerGroupId: (int) $grupo->id,
                ));
            } catch (\ByLopez\VentasAsesor\Exceptions\ClienteComercialExistenteException|\ByLopez\VentasAsesor\Exceptions\ConflictoIdentidadClienteException $e) {
                throw new RechazoFila('CLIENTE_YA_EXISTE', 'Otro proceso creó o identificó a este cliente mientras se aplicaba: reintente la ejecución.');
            } catch (\ByLopez\VentasAsesor\Exceptions\DatosClienteComercialInvalidosException|\ByLopez\VentasAsesor\Exceptions\GrupoComercialInvalidoException $e) {
                throw new RechazoFila('CLIENTE_INVALIDO', 'El alta del cliente fue rechazada por sus reglas ('.class_basename($e).').');
            }

            $customerId = (int) $nuevo->id;
            $creado = true;

            // Un destinatario de UNA palabra NO se rechaza: el apellido queda como «(pendiente)» y registrado como dato por completar.
            if ($f->apellido === null) {
                app(\ByLopez\VentasAsesor\Services\Clientes\DatosPendientesClienteService::class)->registrar($customerId, 'apellido', (string) $f->nombre_destinatario, 'NOMBRE_DE_UNA_PALABRA', (int) $carga->id, (int) $f->cg_fila_origen);
                $obs[] = ['APELLIDO_PENDIENTE', 'Apellido pendiente de completar: el destinatario trae una sola palabra («'.trim((string) $f->nombre_destinatario).'»); el cliente se creó con el apellido «(pendiente)», editable desde su ficha.'];
            }
        }

        // 2. Direccion: solo con municipio inequivoco; nunca se adivina ni se sobrescribe una existente.
        $cambioDireccion = false;

        if (trim((string) $f->direccion_destinatario) !== '') {
            if ($f->geo_estado === 'ok' && $f->municipio_codigo) {
                [$cambioDireccion, $o] = $this->direccion($customerId, $f, $carga);
                $o && $obs[] = $o;
            } else {
                $motivo = match ($f->geo_estado) {
                    'ambigua' => 'la ciudad coincide con más de un municipio y el archivo no trae departamento',
                    'sin_ciudad' => 'la fila no trae ciudad',
                    default => 'la ciudad no se encontró en el catálogo geográfico',
                };
                $pendientes->registrar($customerId, (string) $f->direccion_destinatario, $f->ciudad_destino, $f->departamento, 'UBICACION_'.strtoupper((string) $f->geo_estado), (int) $carga->id, (int) $f->cg_fila_origen);
                $obs[] = ['UBICACION_'.strtoupper((string) $f->geo_estado), self::textoPendiente((string) $f->direccion_destinatario, $f->ciudad_destino, $motivo)];
            }
        }

        // 2b. Transportadora informada SIN equivalencia (o ambigua): NO se rechaza; el envio queda con transportadora «sin determinar» y pendiente.
        if (in_array($f->transportadora_estado, ['sin_equivalencia', 'ambigua'], true)) {
            $obs[] = ['TRANSPORTADORA_PENDIENTE', 'La transportadora «'.$f->transportadora.'» de 99 Envíos '.($f->transportadora_estado === 'ambigua' ? 'coincide con más de una equivalencia' : 'no tiene equivalencia activa en Logística').': el envío se guardó con la transportadora original conservada, pendiente de resolver (no se mezcla con otros envíos).'];
        }

        // 3. Antecedente del envio (regla explicita de actualizacion en AntecedentesEnviosClienteService).
        $ant = $antecedentes->registrar(
            $customerId, $this->proveedor(), (string) ($f->transportadora_clave ?? ''), (string) $f->numero_de_guia, (string) $f->estado_envio,
            $f->fecha_estado ?: null, (int) $carga->id, (int) $f->cg_fila_origen, (string) $f->nombre_destinatario,   // nombre completo ORIGINAL como evidencia
            $f->transportadora,                                                                       // transportadora ORIGINAL como evidencia
        );

        if ($ant['resultado'] === $antecedentes::CONFLICTO) {
            throw new RechazoFila((string) $ant['motivo'], match ($ant['motivo']) {
                'GUIA_DE_OTRO_CLIENTE' => 'La guía ya está registrada para otro cliente: no se reasigna.',
                'CONFLICTO_MISMA_FECHA' => 'La guía ya tiene otro estado con la misma fecha: no se elige ganador.',
                default => 'La guía coincide con más de una transportadora registrada: no se elige ninguna.',
            });
        }

        $cambioAntecedente = in_array($ant['resultado'], [$antecedentes::CREADO, $antecedentes::ACTUALIZADO], true);

        [$estado, $detalle] = match (true) {
            $creado => ['aplicada', 'cliente_creado'],
            $cambioDireccion || $cambioAntecedente => ['aplicada', 'cliente_completado'],
            $ant['resultado'] === $antecedentes::OMITIDO_ANTIGUO => ['omitida_antigua', (string) $ant['motivo']],
            default => ['sin_cambio', 'ya_registrado'],
        };

        // Varias observaciones en una fila: codigos separados por coma (maximo 60) y mensajes unidos.
        $codigo = $obs === [] ? null : mb_substr(implode(',', array_column($obs, 0)), 0, 60);
        $mensaje = $obs === [] ? null : implode(' | ', array_column($obs, 1));
        $this->marcar($tabla, (int) $f->cg_id, $estado, $detalle, $codigo, $mensaje);

        return [$estado, $detalle, $codigo, $mensaje];
    }

    /** Nombre completo del cliente tal como se compara con el archivo; el apellido «(pendiente)» es un marcador, no parte del nombre. */
    protected static function nombreDeCliente(object $c): string
    {
        $apellido = trim((string) $c->last_name) === \ByLopez\VentasAsesor\Services\Clientes\DatosPendientesClienteService::APELLIDO_PENDIENTE ? '' : $c->last_name;

        return trim($c->first_name.' '.$apellido);
    }

    /** @return array{0: bool, 1: ?array{0:string,1:string}} [creada, observacion] */
    protected function direccion(int $customerId, object $f, ?Carga $carga = null): array
    {
        $catalogo = app(\ByLopez\VentasAsesor\Services\Geo\CatalogoUbicaciones::class);
        $ubicacion = $catalogo->resolver((string) $f->municipio_codigo);

        if ($ubicacion === null) {
            app(\ByLopez\VentasAsesor\Services\Clientes\DireccionesPendientesClienteService::class)->registrar($customerId, (string) $f->direccion_destinatario, $f->ciudad_destino, $f->departamento, 'UBICACION_NO_ENCONTRADA', $carga ? (int) $carga->id : null, (int) $f->cg_fila_origen);

            return [false, ['UBICACION_NO_ENCONTRADA', self::textoPendiente((string) $f->direccion_destinatario, $f->ciudad_destino, 'el municipio ya no está activo en el catálogo')]];
        }

        $busqueda = \ByLopez\VentasAsesor\Support\Geo\NombresGeograficos::class;
        $linea = $busqueda::busqueda((string) $f->direccion_destinatario);
        $ciudad = $busqueda::busqueda($ubicacion->municipio);
        $existentes = DB::table('addresses')->where('customer_id', $customerId)->where('address_type', 'customer')->get(['address', 'city', 'default_address']);

        foreach ($existentes as $e) {
            if ($busqueda::busqueda(str_replace(["\r", "\n"], ' ', (string) $e->address)) === $linea && $busqueda::busqueda((string) $e->city) === $ciudad) {
                app(\ByLopez\VentasAsesor\Services\Clientes\DireccionesPendientesClienteService::class)->resolver($customerId, (string) $f->direccion_destinatario);

                return [false, null];                              // ya la tiene: sin duplicar
            }
        }

        try {
            app(\ByLopez\VentasAsesor\Services\Clientes\GuardarDireccionClienteComercialService::class)->guardar($customerId, new \ByLopez\VentasAsesor\DataObjects\DireccionComercial(
                nombre: (string) $f->nombre_pila, apellido: (string) ($f->apellido ?? \ByLopez\VentasAsesor\Services\Clientes\DatosPendientesClienteService::APELLIDO_PENDIENTE), lineas: [trim((string) $f->direccion_destinatario)], ciudad: '', departamento: '', pais: 'CO',
                codigoPostal: null, telefono: (string) $f->telefono_e164, predeterminada: $existentes->where('default_address', 1)->isEmpty(),
                municipioCodigo: (string) $f->municipio_codigo,
            ));
        } catch (\ByLopez\VentasAsesor\Exceptions\DatosClienteComercialInvalidosException $e) {
            app(\ByLopez\VentasAsesor\Services\Clientes\DireccionesPendientesClienteService::class)->registrar($customerId, (string) $f->direccion_destinatario, $f->ciudad_destino, $f->departamento, 'DIRECCION_NO_CREADA', $carga ? (int) $carga->id : null, (int) $f->cg_fila_origen);

            return [false, ['DIRECCION_NO_CREADA', self::textoPendiente((string) $f->direccion_destinatario, $f->ciudad_destino, 'no cumple las reglas de direcciones del cliente ('.implode(', ', array_keys($e->errores ?? [])).')')]];
        }

        app(\ByLopez\VentasAsesor\Services\Clientes\DireccionesPendientesClienteService::class)->resolver($customerId, (string) $f->direccion_destinatario);

        return [true, null];
    }

    /** «Dirección pendiente de completar» con el TEXTO ORIGINAL (no es un rechazo: el cliente y el antecedente sí se guardaron). */
    protected static function textoPendiente(string $direccion, ?string $ciudad, string $motivo): string
    {
        return 'Dirección pendiente de completar. Texto original: «'.trim($direccion).($ciudad ? ' — '.trim($ciudad) : '').'». Motivo: '.$motivo.'. El cliente y el antecedente sí se guardaron.';
    }

    protected function marcar(string $tabla, int $cgId, string $estado, ?string $detalle, ?string $codigo = null, ?string $mensaje = null): void
    {
        DB::table($tabla)->where('cg_id', $cgId)->whereNull('cg_estado')->update([
            'cg_estado' => $estado, 'cg_detalle' => $detalle, 'cg_codigo' => $codigo,
            'cg_mensaje' => $mensaje === null ? null : mb_substr($mensaje, 0, 500), 'cg_procesado_en' => now(),
        ]);
    }

    // ---------------------------------------------------------------------------------------------------------------------

    protected function proveedor(): string
    {
        return (string) config('cargues.clientes_historicos.proveedor', '99envios');
    }

    /** La marca de cada fila va en la MISMA transaccion que sus efectos: la intermedia debe estar en la base de la aplicacion. */
    protected function exigirMismaBase(string $conexion): void
    {
        $c = $this->conexiones->conexion($conexion);
        $app = DB::connection();

        if ($c->getName() !== $app->getName() && ($c->getDatabaseName() !== $app->getDatabaseName() || ($c->getConfig('host') ?? null) !== ($app->getConfig('host') ?? null) || ($c->getConfig('port') ?? null) !== ($app->getConfig('port') ?? null))) {
            throw new OperacionInvalidaException('EXTENSION_CONEXION_DISTINTA', 'Este tipo aplica por los servicios de la aplicación y su intermedia debe estar en la base de la aplicación (conexión «'.$app->getName().'»). La conexión del tipo es otra.');
        }
    }

    protected function exigirModulo(): void
    {
        if (! class_exists(\ByLopez\VentasAsesor\Services\Clientes\CrearClienteComercialService::class)) {
            throw new OperacionInvalidaException('MODULO_NO_DISPONIBLE', 'Este tipo usa los servicios de clientes del módulo Ventas Asesor, que no está instalado.');
        }
    }
}
