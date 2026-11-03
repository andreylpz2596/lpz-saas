<?php

use ByLopez\VentasAsesor\Models\ComisionLiquidacion;
use ByLopez\VentasAsesor\Services\Comisiones\EvaluarComisionesService;
use ByLopez\VentasAsesor\Services\Comisiones\LiquidacionesComisionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Webkul\Sales\Models\Order;

require_once __DIR__.'/helpersCom4.php';

/* Helpers de COM6 (solo lpz_saas_testing): panel de comisiones, avance de la asesora, cuenta y comprobantes. Datos de fixture, no reglas. */

if (! function_exists('com6_como_en_roles')) {
    /**
     * El arbol de Roles marca las claves agrupadoras al marcar una hoja; el menu de Bagisto las necesita (sin ellas, `Undefined array key "name"`
     * en el layout). Un rol real las tiene: se agregan como lo hace la pantalla de Roles.
     */
    function com6_como_en_roles($usuario): void
    {
        $rol = $usuario->role;

        if ($rol === null || ! is_array($rol->permissions)) {
            return;   // rol de acceso total: no tiene lista de claves
        }

        $todas = $rol->permissions;

        foreach ($rol->permissions as $clave) {
            $partes = explode('.', $clave);

            for ($i = 1; $i < count($partes); $i++) {
                $todas[] = implode('.', array_slice($partes, 0, $i));
            }
        }

        $rol->update(['permissions' => array_values(array_unique($todas))]);
    }
}

if (! function_exists('com6_liquidar')) {
    /** Liquidacion aprobada de lo habilitado de la asesora del escenario (mes actual). */
    function com6_liquidar(array $e): ComisionLiquidacion
    {
        $admin = f03b_admin('all');
        $servicio = app(LiquidacionesComisionService::class);
        $liq = $servicio->crearBorrador($admin, (int) $e['asesor']->id, now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString(), [], 'COM6', 'com6-liq-'.Str::uuid());

        return $servicio->aprobar($admin, (int) $liq->id);
    }
}

if (! function_exists('com6_habilitar')) {
    /** Vende, paga, entrega y valida un pedido de `$unidades` y deja la comision habilitada. */
    function com6_habilitar(array $e, int $unidades): Order
    {
        $pedido = com4_vender($e, $unidades);
        com4_pagar($pedido);
        com4_entregar($pedido);
        app(EvaluarComisionesService::class)->evaluarPedido((int) $pedido->id);

        return $pedido->fresh();
    }
}

if (! function_exists('com6_imagen_comprobante')) {
    /** Dibuja un comprobante FICTICIO (imagen real, no texto) para leerlo con el OCR local. Null si el equipo no tiene la fuente. */
    function com6_imagen_comprobante(array $lineas, string $formato = 'png'): ?string
    {
        $fuente = 'C:\\Windows\\Fonts\\arial.ttf';

        if (! function_exists('imagettftext') || ! is_file($fuente)) {
            return null;
        }

        $img = imagecreatetruecolor(720, 80 + count($lineas) * 70);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        $negro = imagecolorallocate($img, 20, 10, 40);

        foreach ($lineas as $i => $linea) {
            imagettftext($img, 30, 0, 40, 80 + $i * 70, $negro, $fuente, $linea);
        }

        ob_start();
        $formato === 'jpg' ? imagejpeg($img, null, 90) : imagepng($img);

        return (string) ob_get_clean();
    }
}

if (! function_exists('com6_archivo')) {
    function com6_archivo(string $contenido, string $nombre, string $mime = 'image/png'): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'com6');
        file_put_contents($ruta, $contenido);

        return new UploadedFile($ruta, $nombre, $mime, null, true);
    }
}
