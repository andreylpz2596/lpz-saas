{{-- Guardia de scroll (iPhone/Safari). El drawer y los modales de Bagisto fijan body{overflow:hidden} al abrir y
body{overflow:auto} al cerrar; si la pagina se restaura desde la cache de atras/adelante, o el cierre no llega a
ejecutarse, el body queda bloqueado y no se puede bajar. Aqui se libera SOLO cuando no hay ningun drawer/modal abierto. --}}
<style>
    .lg-page, .va-orders-page { min-height: calc(100dvh - 64px); }
</style>
<script>
    (function () {
        if (window.__lgGuardiaScroll) {
            return;
        }

        window.__lgGuardiaScroll = true;

        function hayCapaAbierta() {
            return Array.prototype.some.call(document.querySelectorAll('[class*="z-[10001]"], [class*="z-[10002]"]'), function (el) {
                var estilo = window.getComputedStyle(el);

                return estilo.display !== 'none' && estilo.visibility !== 'hidden' && el.offsetWidth > 0 && el.offsetHeight > 0;
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
