{{-- Guardia de scroll (iPhone/Safari). El drawer y los modales de Bagisto fijan body{overflow:hidden} al abrir y
body{overflow:auto} al cerrar; si la pagina se restaura desde la cache de atras/adelante, o el cierre no llega a
ejecutarse, el body queda bloqueado y no se puede bajar. Aqui se libera SOLO cuando no hay ningun drawer/modal abierto.
Una capa abierta es un overlay fijo a pantalla completa (`fixed inset-0`, el que usan el drawer, el modal, el modal de
confirmacion y los modales de ByLopez); el header sticky de Bagisto lleva el mismo z-index pero NO es una capa. --}}
<style>
    .lg-page, .va-orders-page { min-height: calc(100dvh - 64px); }

    /* Las paginas ByLopez usan margen negativo y quedan 12-16 px mas altas que el contenedor del layout. Con
       overflow-x:hidden ese contenedor se vuelve un scroller vertical diminuto que consume el gesto tactil al cambiar de
       direccion. `clip` recorta igual sin crear contexto de scroll; las tablas y pestañas conservan su propio overflow-x:auto. */
    div.w-full.overflow-x-hidden:has(> .lg-page, > .va-orders-page) { overflow-x: clip; overflow-y: visible; }
</style>
<script>
    (function () {
        if (window.__lgGuardiaScroll) {
            return;
        }

        window.__lgGuardiaScroll = true;

        function hayCapaAbierta() {
            return Array.prototype.some.call(document.querySelectorAll('.fixed.inset-0'), function (el) {
                var estilo = window.getComputedStyle(el);

                return estilo.position === 'fixed' && estilo.pointerEvents !== 'none' && estilo.display !== 'none'
                    && estilo.visibility !== 'hidden' && el.offsetWidth > 0 && el.offsetHeight > 0;
            });
        }

        function liberar() {
            if (document.body && document.body.style.overflow && ! hayCapaAbierta()) {
                document.body.style.overflow = '';
            }
        }

        var temporizador = null;

        function programar() {
            clearTimeout(temporizador);
            temporizador = setTimeout(liberar, 400);
        }

        window.addEventListener('pageshow', programar);
        document.addEventListener('visibilitychange', function () {
            if (! document.hidden) { programar(); }
        });

        document.addEventListener('DOMContentLoaded', function () {
            new MutationObserver(programar).observe(document.body, { attributes: true, attributeFilter: ['style'] });
            programar();
        });
    })();
</script>
