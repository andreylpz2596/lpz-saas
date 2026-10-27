<?php

namespace ByLopez\Logistica\Enums;

/** Quien produjo el PDF: la operaria lo adjunto, o una API de transportadora lo genero (futuro, misma tabla). */
enum OrigenArchivoGuia: string
{
    case MANUAL = 'manual';
    case API = 'api';
}
