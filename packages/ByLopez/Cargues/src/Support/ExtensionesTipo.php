<?php

namespace ByLopez\Cargues\Support;

use ByLopez\Cargues\Contracts\ExtensionTipo;

/** Resuelve la extension de un tipo a partir de su map (registro en `cargues.extensiones`; nunca una clase escrita en el map). */
class ExtensionesTipo
{
    public function de(array $map): ?ExtensionTipo
    {
        $clave = $map['extension'] ?? null;
        $clase = is_string($clave) ? config('cargues.extensiones.'.$clave) : null;

        return $clase && is_subclass_of($clase, ExtensionTipo::class) ? app($clase) : null;
    }
}
