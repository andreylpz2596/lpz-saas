<?php

namespace ByLopez\VentasAsesor\Http\Controllers\Admin;

use ByLopez\VentasAsesor\Http\Requests\Geo\BuscarUbicacionRequest;
use ByLopez\VentasAsesor\Services\Geo\CatalogoUbicaciones;
use ByLopez\VentasAsesor\Support\Http\RespuestaErrorNuevaVenta as Respuesta;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Autocomplete de ubicaciones colombianas (D-58): `GET admin/ventas-asesor/ubicaciones?q=pes`.
 * Delgado sobre CatalogoUbicaciones: catalogo local (sin Internet), solo municipios activos,
 * maximo 15 resultados, formato «Departamento — Municipio». Reutilizable por Clientes, Logistica
 * y la API. Autorizado por la ACL de la ruta (Bouncer), igual que el resto de Nueva venta.
 */
class UbicacionesController extends Controller
{
    public function __construct(protected CatalogoUbicaciones $catalogo) {}

    public function buscar(BuscarUbicacionRequest $request): JsonResponse
    {
        return Respuesta::envolver(fn () => $this->catalogo->buscar($request->texto()));
    }
}
