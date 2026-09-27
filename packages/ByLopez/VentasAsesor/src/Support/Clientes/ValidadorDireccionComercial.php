<?php

namespace ByLopez\VentasAsesor\Support\Clientes;

use ByLopez\VentasAsesor\Contracts\NormalizadorTelefono;
use ByLopez\VentasAsesor\DataObjects\DireccionComercial;
use ByLopez\VentasAsesor\Exceptions\DatosClienteComercialInvalidosException as Invalidos;
use ByLopez\VentasAsesor\Support\Correo\DestinatarioCorreo;
use Illuminate\Support\Facades\Validator;
use Webkul\Core\Rules\PostCode;

/**
 * Reglas de una direccion comercial (RT-04B1b), compartidas por las direcciones
 * guardadas del cliente y las del borrador de venta (03D), para no duplicarlas.
 *
 * Frente a los Form Requests de Bagisto: email opcional (RT-04) y telefono normalizado
 * a E.164 en lugar de PhoneNumber. Obligatorio lo que exige la operacion: nombre,
 * apellido, lineas, ciudad, departamento, pais y telefono.
 *
 * Codigo postal (D-46): la BD lo admite NULL, en lpz_saas no hay tasas de impuesto y
 * ningun carrier lo usa. Las direcciones GUARDADAS lo siguen exigiendo (decision de
 * RT-04, sin cambios); en el borrador es opcional. Si viene, se valida con PostCode.
 */
class ValidadorDireccionComercial
{
    public function __construct(protected NormalizadorTelefono $normalizador) {}

    /** @return array<string, mixed> columnas de addresses (address con lineas unidas por PHP_EOL) */
    public function validar(DireccionComercial $d, bool $exigirCodigoPostal): array
    {
        $errores = [];
        $lineas = array_values(array_filter(array_map('trim', $d->lineas), fn ($l) => $l !== ''));
        $codigoPostal = trim((string) $d->codigoPostal);

        $obligatorios = ['nombre' => $d->nombre, 'apellido' => $d->apellido, 'ciudad' => $d->ciudad,
            'departamento' => $d->departamento, 'pais' => $d->pais];

        if ($exigirCodigoPostal) {
            $obligatorios['codigo_postal'] = $codigoPostal;
        }

        foreach ($obligatorios as $campo => $valor) {
            if (trim($valor) === '') {
                $errores[$campo] = Invalidos::REQUERIDO;
            }
        }

        if ($lineas === []) {
            $errores['lineas'] = Invalidos::REQUERIDO;
        } elseif (mb_strlen(implode(PHP_EOL, $lineas)) > 255) {
            $errores['lineas'] = Invalidos::DEMASIADO_LARGO;
        }

        if (! isset($errores['codigo_postal']) && $codigoPostal !== ''
            && Validator::make(['postcode' => $codigoPostal], ['postcode' => [new PostCode]])->fails()) {
            $errores['codigo_postal'] = Invalidos::FORMATO_INVALIDO;
        }

        $telefono = $this->normalizador->normalizar($d->telefono);

        if (! $telefono->esBuscable()) {
            $errores['telefono'] = Invalidos::TELEFONO_NO_NORMALIZABLE;
        }

        $email = $d->email === null || trim($d->email) === '' ? null : trim($d->email);

        if ($email !== null && DestinatarioCorreo::motivoDeOmision($email) !== null) {
            $errores['email'] = Invalidos::EMAIL_INVALIDO;
        }

        if ($errores !== []) {
            throw new Invalidos($errores);
        }

        return [
            'first_name' => trim($d->nombre),
            'last_name' => trim($d->apellido),
            'company_name' => $d->empresa === null || trim($d->empresa) === '' ? null : trim($d->empresa),
            'address' => implode(PHP_EOL, $lineas),
            'city' => trim($d->ciudad),
            'state' => trim($d->departamento),
            'country' => strtoupper(trim($d->pais)),
            'postcode' => $codigoPostal === '' ? null : $codigoPostal,
            'phone' => $telefono->normalizado,
            'email' => $email,
        ];
    }
}
