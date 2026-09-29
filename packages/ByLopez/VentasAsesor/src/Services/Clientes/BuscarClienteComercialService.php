<?php

namespace ByLopez\VentasAsesor\Services\Clientes;

use ByLopez\VentasAsesor\Contracts\NormalizadorTelefono;
use ByLopez\VentasAsesor\DataObjects\ClienteEncontrado;
use ByLopez\VentasAsesor\DataObjects\ConsultaClienteComercial;
use ByLopez\VentasAsesor\DataObjects\ResultadoBusquedaCliente as Resultado;
use ByLopez\VentasAsesor\Enums\TipoResultadoBusquedaCliente as Tipo;
use ByLopez\VentasAsesor\Exceptions\TelefonoEnVariasFichasException;
use ByLopez\VentasAsesor\Support\Correo\DestinatarioCorreo;
use ByLopez\VentasAsesor\Support\Telefono\LocalizadorTitularTelefono;
use Illuminate\Support\Facades\DB;
use Webkul\Customer\Contracts\Customer;
use Webkul\Customer\Models\CustomerProxy;

/**
 * Busqueda comercial unica para todos los canales (RT-04B1b). Solo lectura.
 *
 * Identidad fuerte: el TELEFONO (un numero normalizado = un Customer, incluidos
 * secundarios y fichas historicas sin indexar). El EMAIL identifica dentro de su
 * canal, que es la unicidad real de Bagisto (email, channel_id). El NOMBRE solo
 * produce candidatos: nunca se elige ni se fusiona por nombre.
 *
 * Telefono de A y email de B → CONFLICTO (fail closed).
 */
class BuscarClienteComercialService
{
    public const LIMITE_MAXIMO = 50;

    public function __construct(
        protected NormalizadorTelefono $normalizador,
        protected LocalizadorTitularTelefono $localizador,
    ) {}

    public function buscar(ConsultaClienteComercial $consulta): Resultado
    {
        $avisos = [];
        $porTelefono = null;
        $porEmail = null;

        if ($this->tieneTexto($consulta->telefono)) {
            $telefono = $this->normalizador->normalizar($consulta->telefono);

            if (! $telefono->esBuscable()) {
                $avisos[] = Resultado::AVISO_TELEFONO_NO_NORMALIZABLE;
            } else {
                try {
                    $porTelefono = $this->localizador->titular($telefono);
                } catch (TelefonoEnVariasFichasException $e) {
                    return new Resultado(Tipo::CONFLICTO, conflicto: Resultado::CONFLICTO_TELEFONO_EN_VARIAS_FICHAS,
                        clientesEnConflicto: $e->customerIds, avisos: $avisos);
                }
            }
        }

        if ($this->tieneTexto($consulta->email)) {
            if (DestinatarioCorreo::motivoDeOmision(trim($consulta->email)) !== null) {
                $avisos[] = Resultado::AVISO_EMAIL_INVALIDO;
            } else {
                $porEmail = $this->idPorEmail(trim($consulta->email), $consulta->channelId);
            }
        }

        if ($porTelefono && $porEmail && $porTelefono->customerId !== $porEmail) {
            return new Resultado(Tipo::CONFLICTO, conflicto: Resultado::CONFLICTO_TELEFONO_Y_EMAIL,
                clientesEnConflicto: [$porTelefono->customerId, $porEmail], avisos: $avisos);
        }

        if ($porTelefono) {
            return new Resultado(Tipo::TELEFONO_EXACTO, $this->encontrado($porTelefono->customerId, $porTelefono->fuente), avisos: $avisos);
        }

        if ($porEmail) {
            return new Resultado(Tipo::EMAIL_EXACTO, $this->encontrado($porEmail, 'email'), avisos: $avisos);
        }

        $candidatos = $this->tieneTexto($consulta->nombre)
            ? $this->candidatosPorNombre($consulta->nombre, $consulta->limiteCandidatos)
            : [];

        return new Resultado($candidatos === [] ? Tipo::SIN_RESULTADOS : Tipo::CANDIDATOS_NOMBRE, candidatos: $candidatos, avisos: $avisos);
    }

    /** Id del Customer con ese email en el canal (unicidad real de Bagisto: email + channel_id). */
    public function idPorEmail(string $email, ?int $channelId = null): ?int
    {
        $id = CustomerProxy::modelClass()::query()
            ->where('email', $email)
            ->where('channel_id', $channelId ?? core()->getDefaultChannel()->id)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Candidatos por nombre: cada palabra debe aparecer en "nombre apellido". Una sola
     * consulta con el grupo precargado.
     *
     * @return list<ClienteEncontrado>
     */
    public function candidatosPorNombre(string $texto, int $limite = 20): array
    {
        $palabras = array_values(array_filter(preg_split('/\s+/u', trim($texto)), fn ($p) => mb_strlen($p) >= 2));

        if ($palabras === []) {
            return [];
        }

        $tabla = (new (CustomerProxy::modelClass()))->getTable();
        $prefijo = DB::getTablePrefix();
        $consulta = CustomerProxy::modelClass()::query()->with('group');

        foreach ($palabras as $palabra) {
            $consulta->where(DB::raw("CONCAT({$prefijo}{$tabla}.first_name, ' ', {$prefijo}{$tabla}.last_name)"),
                'like', '%'.addcslashes($palabra, '%_\\').'%');
        }

        return $consulta->orderBy('first_name')->orderBy('last_name')
            ->limit(max(1, min($limite, self::LIMITE_MAXIMO)))
            ->get()
            ->map(fn (Customer $c) => ClienteEncontrado::desde($c, 'nombre', $this->invitadoId()))
            ->all();
    }

    protected function encontrado(int $customerId, string $via): ClienteEncontrado
    {
        return ClienteEncontrado::desde(CustomerProxy::modelClass()::query()->with('group')->findOrFail($customerId), $via, $this->invitadoId());
    }

    private function invitadoId(): ?int
    {
        $invitado = core()->getGuestCustomerGroup();

        return $invitado ? (int) $invitado->id : null;
    }

    private function tieneTexto(?string $valor): bool
    {
        return $valor !== null && trim($valor) !== '';
    }
}
