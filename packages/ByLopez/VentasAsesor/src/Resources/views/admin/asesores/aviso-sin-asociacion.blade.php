{{-- ASESOR-01: el usuario vende como asesor pero no tiene perfil de asesor activo. Falla cerrado: no puede crear ventas. --}}
@if (! empty($asesorSinAsociacion))
    <div class="mt-3.5 rounded-lg border p-4 text-sm font-semibold" style="border-color:#fda4af;background:#fff1f2;color:#9f1239" data-asesor-sin-asociacion>
        @lang('ventasasesor::app.asesores.sin-asociacion')
    </div>
@endif
