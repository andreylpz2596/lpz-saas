{{--
    Guardia del formulario de ingreso (solo en /admin/login). Evita el «500» repetido:
    1) un segundo envio (doble toque, «Ir» del teclado + boton) llegaba con el token viejo y, ademas,
       borraba la sesion recien abierta;
    2) la pagina restaurada con «atras» (iPhone) o abierta por horas trae un token vencido: se recarga.
--}}
@if (request()->routeIs('admin.session.create'))
    <script data-venta="guardia-login">
        (function () {
            var cargada = Date.now();
            var vencida = function () { return Date.now() - cargada > 100 * 60 * 1000; };
            var esLogin = function (f) { return f && f.tagName === 'FORM' && f.querySelector('input[name="password"]') && /\/login$/.test(new URL(f.action, location.href).pathname); };

            window.addEventListener('pageshow', function (e) { if (e.persisted) { location.reload(); } });

            document.addEventListener('visibilitychange', function () {
                var clave = document.querySelector('input[name="password"]');

                if (document.visibilityState === 'visible' && vencida() && (! clave || ! clave.value)) { location.reload(); }
            });

            document.addEventListener('submit', function (e) {
                var f = e.target;

                if (! esLogin(f)) { return; }

                if (f.dataset.enviando === '1') { e.preventDefault(); e.stopImmediatePropagation(); return; }

                if (vencida()) { e.preventDefault(); e.stopImmediatePropagation(); location.reload(); return; }

                var correo = f.querySelector('input[name="email"]');
                var clave = f.querySelector('input[name="password"]');

                // Si faltan datos, Bagisto muestra sus errores y el boton queda libre.
                if (! correo || ! correo.value || ! correo.validity.valid || clave.value.length < 6) { return; }

                f.dataset.enviando = '1';

                var boton = f.querySelector('button:not([type="button"])');
                var texto = boton ? boton.textContent : '';

                if (boton) { boton.style.opacity = '.6'; boton.style.pointerEvents = 'none'; boton.textContent = @json(trans('ventasasesor::app.sesion.ingresando')); }

                setTimeout(function () {
                    f.dataset.enviando = '';

                    if (boton) { boton.style.opacity = ''; boton.style.pointerEvents = ''; boton.textContent = texto; }
                }, 8000);
            }, true);
        })();
    </script>
@endif
