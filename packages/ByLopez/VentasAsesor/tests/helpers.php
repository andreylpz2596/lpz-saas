<?php

use Webkul\User\Models\Admin;
use Webkul\User\Models\Role;

/*
|--------------------------------------------------------------------------
| Helpers de pruebas de VentasAsesor
|--------------------------------------------------------------------------
|
| Administradores y roles se construyen EN MEMORIA y nunca se guardan. La suite
| de este proyecto corre sobre la base de desarrollo (no existe base de pruebas
| aislada) y la Fase 01 exige cero escrituras, incluido el AUTO_INCREMENT que una
| fabrica consumiria aunque la transaccion se revierta.
|
*/

if (! function_exists('va01_rutaInicio')) {
    function va01_rutaInicio(): string
    {
        return 'admin.ventas_asesor.inicio.index';
    }
}

if (! function_exists('va01_admin')) {
    function va01_admin(mixed $permisos, ?string $tipo = 'custom', string $nombreRol = 'VA01 Rol', array $atributos = []): Admin
    {
        $admin = (new Admin)->forceFill(array_merge([
            'name' => 'VA01 Admin',
            'email' => 'va01@example.test',
            'status' => 1,
            'two_factor_enabled' => false,
        ], $atributos));

        $admin->setRelation('role', (new Role)->forceFill([
            'name' => $nombreRol,
            'permission_type' => $tipo,
            'permissions' => $permisos,
        ]));

        return $admin;
    }
}

if (! function_exists('va01_adminConRolHuerfano')) {
    function va01_adminConRolHuerfano(): Admin
    {
        $admin = (new Admin)->forceFill([
            'name' => 'VA01 Huerfano',
            'email' => 'va01-huerfano@example.test',
            'status' => 1,
            'two_factor_enabled' => false,
            'role_id' => PHP_INT_MAX,
        ]);

        $admin->setRelation('role', null);

        return $admin;
    }
}

if (! function_exists('va01_clavesAcl')) {
    function va01_clavesAcl(): array
    {
        return collect(config('acl'))
            ->pluck('key')
            ->filter(fn ($clave) => $clave === 'ventas_asesor' || str_starts_with($clave, 'ventas_asesor.'))
            ->values()
            ->all();
    }
}

if (! function_exists('va01_permisosRolAsesorVentasActual')) {
    /**
     * Copia literal de los 18 permisos que tiene hoy el rol real "ASESOR VENTAS"
     * (auditoria 01 §18.1), para no depender de la base de datos.
     */
    function va01_permisosRolAsesorVentasActual(): array
    {
        return [
            'sales', 'sales.orders', 'sales.orders.create', 'sales.orders.view', 'sales.orders.cancel',
            'sales.invoices', 'sales.invoices.view', 'sales.invoices.create',
            'sales.shipments', 'sales.shipments.view', 'sales.shipments.create',
            'sales.refunds', 'sales.refunds.view', 'sales.refunds.create',
            'sales.transactions', 'sales.transactions.view',
            'catalog', 'catalog.products',
        ];
    }
}
