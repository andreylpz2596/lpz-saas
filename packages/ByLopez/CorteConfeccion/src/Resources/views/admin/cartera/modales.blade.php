{{-- Modales de la cuenta. Todo se maneja con delegacion de eventos (ver scripts.blade.php). --}}
@php
    $campo = 'w-full rounded-lg border px-3 py-2 text-base dark:border-gray-800 dark:bg-gray-900 dark:text-white sm:text-sm';
    $etiqueta = 'mb-1 block text-sm font-medium text-gray-700 dark:text-gray-300';
@endphp

{{-- ===== Registrar movimiento ===== --}}
<div id="c-modal-movimiento" class="fixed inset-0 z-[10010] hidden overflow-y-auto overscroll-contain bg-gray-900/60" role="dialog" aria-modal="true" aria-labelledby="c-mov-titulo">
    <div class="mx-auto my-6 w-full max-w-3xl px-3 max-sm:my-0 max-sm:px-0">
        <div class="rounded-xl bg-white shadow-xl max-sm:rounded-none dark:bg-gray-900">
            <div class="flex items-start justify-between gap-3 border-b p-4 dark:border-gray-800">
                <h2 id="c-mov-titulo" class="text-lg font-bold text-gray-900 dark:text-white">Registrar movimiento</h2>
                <button type="button" class="rounded-md px-2 py-1 text-xl leading-none text-gray-500 hover:text-gray-800 dark:text-gray-400" data-c-cerrar aria-label="Cerrar">&times;</button>
            </div>

            <div class="grid gap-4 p-4">
                <div class="hidden rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300" role="alert" data-c="errores"></div>

                <fieldset class="grid gap-2">
                    <legend class="{{ $etiqueta }}">¿Qué vas a registrar?</legend>
                    @if ($puedeRegistrar)
                        <label class="flex items-start gap-2 rounded border p-2 text-sm dark:border-gray-800"><input type="radio" name="c-tipo" value="abono" class="mt-1" checked> <span><b>Abono por trabajo</b><br><span class="text-xs text-gray-500">Dinero entregado por trabajo. Lo que no se aplique queda como anticipo.</span></span></label>
                    @endif
                    @if ($puedePrestamos)
                        <label class="flex items-start gap-2 rounded border p-2 text-sm dark:border-gray-800"><input type="radio" name="c-tipo" value="prestamo" class="mt-1" @checked(! $puedeRegistrar)> <span><b>Préstamo</b><br><span class="text-xs text-gray-500">Dinero personal. No cuenta como abono por trabajo.</span></span></label>
                        <label class="flex items-start gap-2 rounded border p-2 text-sm dark:border-gray-800"><input type="radio" name="c-tipo" value="devolucion" class="mt-1"> <span><b>Devolución de préstamo</b><br><span class="text-xs text-gray-500">La persona devuelve dinero real.</span></span></label>
                    @endif
                    @if ($puedeArranque)
                        <label class="flex items-start gap-2 rounded border border-dashed p-2 text-sm dark:border-gray-700"><input type="radio" name="c-tipo" value="saldo_inicial" class="mt-1"> <span><b>Saldo inicial (anterior al arranque)</b><br><span class="text-xs text-gray-500">Deuda previa por trabajo, anticipo previo o préstamo anterior. Se carga una vez; no es una transferencia nueva.</span></span></label>
                    @endif
                </fieldset>

                <div class="hidden" data-c="bloque-saldo-inicial">
                    <label for="c-saldo-tipo" class="{{ $etiqueta }}">Tipo de saldo inicial</label>
                    <select id="c-saldo-tipo" class="{{ $campo }}" data-c="saldo-tipo">
                        <option value="saldo_inicial_trabajo">Deuda previa por trabajo (le debo)</option>
                        <option value="saldo_inicial_anticipo">Anticipo previo (ya le entregué)</option>
                        <option value="saldo_inicial_prestamo">Préstamo anterior (me debe)</option>
                    </select>
                </div>

                <div class="hidden" data-c="bloque-prestamo">
                    <label for="c-prestamo-id" class="{{ $etiqueta }}">Préstamo que se devuelve</label>
                    <select id="c-prestamo-id" class="{{ $campo }}" data-c="prestamo-id"></select>
                </div>

                {{-- Cómo registrarlo: con comprobantes (por defecto) o manualmente --}}
                <div class="grid gap-3 rounded-lg border p-3 dark:border-gray-800" data-c="bloque-modo">
                    <div class="flex flex-wrap gap-4" role="radiogroup" aria-label="Cómo registrarlo" data-c="selector-modo">
                        <label class="flex items-center gap-2 text-sm font-semibold"><input type="radio" name="c-modo" value="detalle" checked> Con comprobantes</label>
                        <label class="flex items-center gap-2 text-sm font-semibold"><input type="radio" name="c-modo" value="total"> Ingresar manualmente</label>
                    </div>

                    <div class="grid gap-3" data-c="modo-detalle">
                        <div class="grid gap-2 rounded-lg bg-slate-50 p-3 dark:bg-gray-800">
                            <label for="c-archivos" class="{{ $etiqueta }} !mb-0">Comprobantes <span class="text-xs font-normal text-gray-500">(imágenes o PDF, hasta {{ $maxArchivos }}). Al elegirlos se leen solos.</span></label>
                            <input id="c-archivos" type="file" multiple accept="image/jpeg,image/png,image/webp,application/pdf" class="{{ $campo }}" data-c="archivos">
                            <p class="text-sm font-medium text-blue-700" data-c="estado-lectura" role="status" aria-live="polite"></p>
                            <p class="text-xs text-gray-500">Solo se prepara un borrador para que lo revises y corrijas: el dinero se registra cuando tú confirmas.@unless ($lectorDisponible) La lectura automática no está disponible en este servidor: los comprobantes se guardan como soporte y completas los datos tú.@endunless</p>
                            <div class="grid gap-1 text-xs" data-c="soportes-lista"></div>
                        </div>

                        <div class="grid gap-2" data-c="ops" aria-live="polite"></div>
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <button type="button" class="transparent-button" data-c-agregar-op>+ Agregar operación manual</button>
                            <p class="text-base font-bold" data-c="suma-ops"></p>
                        </div>
                    </div>

                    {{-- Manual (y saldos iniciales): un solo grupo de campos --}}
                    <div class="grid gap-3" data-c="campos-manual">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <label for="c-valor" class="{{ $etiqueta }}">Valor <span class="text-red-600">*</span></label>
                                <input id="c-valor" type="text" inputmode="decimal" autocomplete="off" placeholder="$ 0" class="{{ $campo }}" data-c="valor">
                            </div>
                            <div>
                                <label for="c-fecha" class="{{ $etiqueta }}">Fecha efectiva <span class="text-red-600">*</span></label>
                                <input id="c-fecha" type="date" class="{{ $campo }}" data-c="fecha">
                            </div>
                            <div>
                                <label for="c-medio" class="{{ $etiqueta }}">Medio</label>
                                <select id="c-medio" class="{{ $campo }}" data-c="medio">
                                    <option value="nequi">Nequi</option><option value="transferencia">Transferencia</option><option value="efectivo">Efectivo</option><option value="daviplata">Daviplata</option><option value="otro">Otro</option>
                                </select>
                            </div>
                            <div data-c="bloque-referencia">
                                <label for="c-referencia" class="{{ $etiqueta }}">Referencia</label>
                                <input id="c-referencia" type="text" maxlength="255" class="{{ $campo }}" data-c="referencia">
                            </div>
                        </div>
                        <div data-c="modo-total">
                            <label for="c-ntrans" class="{{ $etiqueta }}">Número de transferencias <span class="text-xs font-normal text-gray-500">(solo el total: no se pueden comparar referencias individuales ni detectar repetidas)</span></label>
                            <input id="c-ntrans" type="number" min="1" step="1" inputmode="numeric" class="{{ $campo }} sm:max-w-[10rem]" data-c="ntrans" placeholder="Ej. 15">
                        </div>
                    </div>
                </div>

                {{-- Aplicar a (solo abonos) --}}
                <div class="grid gap-2 rounded-lg border p-3 dark:border-gray-800" data-c="bloque-aplicar">
                    <p class="{{ $etiqueta }} !mb-0">Aplicar a</p>
                    <select class="{{ $campo }}" data-c="aplicar-modo" aria-label="Cómo aplicar">
                        <option value="auto">Automático: primero lo ya recibido, la más antigua primero</option>
                        <option value="anticipo">Dejar como anticipo (aplicar después)</option>
                        <option value="elegir">Elegir órdenes…</option>
                    </select>
                    <div class="hidden grid gap-2" data-c="aplicar-lineas"></div>
                    <p class="text-xs text-gray-500">Puedes abonar por prendas pendientes de recibir eligiéndolas aquí: siguen como pendientes y, al recibirlas, no se cobran de nuevo. Lo no aplicado queda como anticipo y se aplica después sin dinero nuevo.</p>
                </div>

                <div>
                    <label for="c-observacion" class="{{ $etiqueta }}">Soporte u observación</label>
                    <textarea id="c-observacion" rows="2" maxlength="2000" class="{{ $campo }}" data-c="observacion"></textarea>
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2 border-t p-4 pb-[max(1rem,env(safe-area-inset-bottom))] dark:border-gray-800">
                <button type="button" class="secondary-button" data-c-cerrar>Cancelar</button>
                <button type="button" class="primary-button" data-c-enviar-movimiento>Registrar</button>
            </div>
        </div>
    </div>
</div>

{{-- ===== Corte de cuentas ===== --}}
<div id="c-modal-corte" class="fixed inset-0 z-[10010] hidden overflow-y-auto overscroll-contain bg-gray-900/60" role="dialog" aria-modal="true" aria-labelledby="c-corte-titulo">
    <div class="mx-auto my-6 w-full max-w-xl px-3 max-sm:my-0 max-sm:px-0">
        <div class="rounded-xl bg-white shadow-xl max-sm:rounded-none dark:bg-gray-900">
            <div class="flex items-start justify-between gap-3 border-b p-4 dark:border-gray-800">
                <h2 id="c-corte-titulo" class="text-lg font-bold text-gray-900 dark:text-white">Hacer corte de cuentas</h2>
                <button type="button" class="rounded-md px-2 py-1 text-xl leading-none text-gray-500 hover:text-gray-800 dark:text-gray-400" data-c-cerrar aria-label="Cerrar">&times;</button>
            </div>
            <div class="grid gap-4 p-4">
                <div class="hidden rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300" role="alert" data-c="errores"></div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="hidden" data-c="corte-bloque-prestamo">
                        <label for="c-corte-prestamo" class="{{ $etiqueta }}">Préstamo a descontar</label>
                        <select id="c-corte-prestamo" class="{{ $campo }}" data-c="corte-prestamo"></select>
                    </div>
                    <div data-c="corte-bloque-descuento" class="hidden">
                        <label for="c-corte-descuento" class="{{ $etiqueta }}">Descuento de préstamo acordado</label>
                        <input id="c-corte-descuento" type="text" inputmode="decimal" autocomplete="off" value="0" class="{{ $campo }}" data-c="corte-descuento">
                        <p class="mt-1 text-xs text-gray-500">Empieza en cero. Solo con un monto acordado, y nunca sobre trabajo pendiente de recibir.</p>
                    </div>
                </div>

                <div class="grid gap-0 text-sm" data-c="corte-resumen"><p class="text-gray-500">Calculando…</p></div>
                <div data-c="corte-avisos" class="grid gap-2"></div>

                <div>
                    <label for="c-corte-obs" class="{{ $etiqueta }}">Observación</label>
                    <input id="c-corte-obs" type="text" maxlength="2000" class="{{ $campo }}" data-c="corte-obs" placeholder="Ej. acordado con la persona">
                </div>

                <p class="rounded bg-amber-50 p-2 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-100">Confirmar el corte <b>no registra ninguna transferencia</b>. Cuando pagues, usa «Registrar movimiento».</p>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2 border-t p-4 pb-[max(1rem,env(safe-area-inset-bottom))] dark:border-gray-800">
                <button type="button" class="secondary-button" data-c-cerrar>Cancelar</button>
                <button type="button" class="primary-button" data-c-enviar-corte disabled>Confirmar corte (sin pago)</button>
            </div>
        </div>
    </div>
</div>

{{-- ===== Motivo (reversos) y aplicar anticipo ===== --}}
<div id="c-modal-motivo" class="fixed inset-0 z-[10010] hidden overflow-y-auto overscroll-contain bg-gray-900/60" role="dialog" aria-modal="true" aria-labelledby="c-motivo-titulo">
    <div class="mx-auto my-6 w-full max-w-md px-3 max-sm:my-0 max-sm:px-0">
        <div class="rounded-xl bg-white shadow-xl max-sm:rounded-none dark:bg-gray-900">
            <div class="flex items-start justify-between gap-3 border-b p-4 dark:border-gray-800">
                <h2 id="c-motivo-titulo" class="text-lg font-bold text-gray-900 dark:text-white">Reversar</h2>
                <button type="button" class="rounded-md px-2 py-1 text-xl leading-none text-gray-500 hover:text-gray-800 dark:text-gray-400" data-c-cerrar aria-label="Cerrar">&times;</button>
            </div>
            <div class="grid gap-3 p-4">
                <div class="hidden rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300" role="alert" data-c="errores"></div>
                <p class="text-sm text-gray-600 dark:text-gray-300">El movimiento original se conserva con su fecha, motivo y responsable. No se borra nada.</p>
                <div>
                    <label for="c-motivo" class="{{ $etiqueta }}">Motivo <span class="text-red-600">*</span></label>
                    <textarea id="c-motivo" rows="3" maxlength="500" class="{{ $campo }}" data-c="motivo"></textarea>
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2 border-t p-4 pb-[max(1rem,env(safe-area-inset-bottom))] dark:border-gray-800">
                <button type="button" class="secondary-button" data-c-cerrar>Cancelar</button>
                <button type="button" class="primary-button" data-c-enviar-motivo>Reversar</button>
            </div>
        </div>
    </div>
</div>

<div id="c-modal-aplicar" class="fixed inset-0 z-[10010] hidden overflow-y-auto overscroll-contain bg-gray-900/60" role="dialog" aria-modal="true" aria-labelledby="c-aplicar-titulo">
    <div class="mx-auto my-6 w-full max-w-md px-3 max-sm:my-0 max-sm:px-0">
        <div class="rounded-xl bg-white shadow-xl max-sm:rounded-none dark:bg-gray-900">
            <div class="flex items-start justify-between gap-3 border-b p-4 dark:border-gray-800">
                <h2 id="c-aplicar-titulo" class="text-lg font-bold text-gray-900 dark:text-white">Aplicar anticipo</h2>
                <button type="button" class="rounded-md px-2 py-1 text-xl leading-none text-gray-500 hover:text-gray-800 dark:text-gray-400" data-c-cerrar aria-label="Cerrar">&times;</button>
            </div>
            <div class="grid gap-3 p-4">
                <div class="hidden rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-900 dark:bg-red-950 dark:text-red-300" role="alert" data-c="errores"></div>
                <p class="text-sm text-gray-600 dark:text-gray-300">Aplicar un anticipo no registra dinero nuevo: usa el mismo abono y conserva su vínculo.</p>
                <p class="text-sm">Disponible: <b data-c="aplicar-disponible"></b></p>
                <div>
                    <label for="c-aplicar-destino" class="{{ $etiqueta }}">Aplicar a</label>
                    <select id="c-aplicar-destino" class="{{ $campo }}" data-c="aplicar-destino"></select>
                </div>
                <div>
                    <label for="c-aplicar-valor" class="{{ $etiqueta }}">Valor</label>
                    <input id="c-aplicar-valor" type="text" inputmode="decimal" autocomplete="off" class="{{ $campo }}" data-c="aplicar-valor">
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-2 border-t p-4 pb-[max(1rem,env(safe-area-inset-bottom))] dark:border-gray-800">
                <button type="button" class="secondary-button" data-c-cerrar>Cancelar</button>
                <button type="button" class="primary-button" data-c-enviar-aplicar>Aplicar</button>
            </div>
        </div>
    </div>
</div>
