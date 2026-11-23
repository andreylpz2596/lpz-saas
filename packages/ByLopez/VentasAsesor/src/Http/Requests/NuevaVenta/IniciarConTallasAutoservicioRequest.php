<?php

namespace ByLopez\VentasAsesor\Http\Requests\NuevaVenta;

/** Autoservicio: igual que la del asesor pero SIN cliente ni canal en la peticion (los fija el servidor). */
class IniciarConTallasAutoservicioRequest extends IniciarConTallasNuevaVentaRequest
{
    public function rules(): array
    {
        return array_diff_key(parent::rules(), ['customer_id' => 1, 'canal' => 1]);
    }
}
