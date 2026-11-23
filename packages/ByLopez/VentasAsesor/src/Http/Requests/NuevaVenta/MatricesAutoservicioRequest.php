<?php

namespace ByLopez\VentasAsesor\Http\Requests\NuevaVenta;

/** Autoservicio: matrices por lote SIN cliente en la peticion (lo fija el servidor). */
class MatricesAutoservicioRequest extends MatricesNuevaVentaRequest
{
    public function rules(): array
    {
        return array_diff_key(parent::rules(), ['customer_id' => 1]);
    }
}
