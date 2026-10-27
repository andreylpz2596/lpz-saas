<?php

namespace ByLopez\Cargues\Models;

use Illuminate\Database\Eloquent\Model;

class Estructura extends Model
{
    public const ACTIVA = 'activa';

    public const ARCHIVADA = 'archivada';

    public const EN_CAMBIO = 'en_cambio';

    protected $table = 'bylopez_cg_estructuras';

    protected $guarded = [];
}
