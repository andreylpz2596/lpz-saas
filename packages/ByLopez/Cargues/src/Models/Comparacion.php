<?php

namespace ByLopez\Cargues\Models;

use Illuminate\Database\Eloquent\Model;

class Comparacion extends Model
{
    protected $table = 'bylopez_cg_comparaciones';

    protected $guarded = [];

    protected $casts = ['relaciones' => 'array', 'predeterminada' => 'boolean'];
}
