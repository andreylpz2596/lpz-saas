{{--
    Mascara MONETARIA solo VISUAL (ByLopez). Compartida por VentasAsesor y CorteConfeccion; se incluye con
    @include('corteconfeccion::admin.partials.moneda') (una sola vez por pagina).

    REGLA: el VALOR DE NEGOCIO no cambia. La mascara solo cambia lo que el usuario VE y ESCRIBE ($ 100.000); lo que se
    guarda, valida y calcula sigue siendo el valor crudo de siempre: texto/numero plano, punto decimal, sin separadores
    ("100000", "7300.5"). Un valor que el usuario no toca se envia EXACTAMENTE como llego (p. ej. "14500.00").

    Tres piezas (todas usan la misma logica):
      - <v-moneda v-model="..."></v-moneda>   componente Vue para un <input v-model> comun. `numero` = emite Number (como un
        input type="number"); sin `numero` emite el texto crudo. `sin-prefijo` si el formulario ya pinta el simbolo $.
        Cierre explicito (no self-closing) en x-template.
      - <input data-moneda ...>                mejora un input de un formulario servido por Blade: el original queda oculto y
        conserva name/value/eventos; el campo visible lo reemplaza. Sin JS, el input original sigue funcionando.
      - ByLopezMoneda.formatear(valor)         "$ 7.300" para valores de lectura.

    Escritura: coma = decimal; los puntos escritos se ignoran (son separadores de miles). Al PEGAR, un unico punto con 1-2
    decimales al final ("7300.50") se lee como decimal; cualquier otro punto es separador de miles.
--}}
@pushOnce('scripts', 'bylopez-moneda')
    <script>
        (function (global) {
            'use strict';

            // Misma regla que `ByLopez\Logistica\Support\Moneda::prefijo()` (lectura en el servidor): si Bagisto no tiene simbolo y devuelve el
            // CODIGO («COP»), se muestra «$». Asi «$ 8.500» se ve igual en el servidor, en el navegador y dentro de los campos.
            var PREFIJO = @json((($simboloBase = trim((string) core()->currencySymbol(core()->getBaseCurrencyCode()))) === '' || $simboloBase === (string) core()->getBaseCurrencyCode() ? '$' : $simboloBase).' ');
            var GRUPO = '.';
            var DECIMAL = ',';

            /** Valor de negocio como texto crudo ("7300.5"), '' si esta vacio, null si no es un numero plano (no se toca). */
            function crudo(valor) {
                if (valor === null || valor === undefined) {
                    return '';
                }

                var texto = String(valor).trim();

                if (texto === '') {
                    return '';
                }

                return /^\d+(\.\d+)?$/.test(texto) ? texto : null;
            }

            function agrupar(enteros) {
                return String(enteros).replace(/^0+(?=\d)/, '').replace(/\B(?=(\d{3})+(?!\d))/g, GRUPO);
            }

            /** Para LEER: 7300 → "$ 7.300"; 7300.5 → "$ 7.300,50"; ceros decimales no se muestran. No numerico: tal cual. */
            function formatear(valor, opciones) {
                var prefijo = opciones && opciones.prefijo !== undefined ? opciones.prefijo : PREFIJO;
                var r = crudo(valor);

                if (r === null) {
                    return String(valor);
                }

                if (r === '') {
                    return '';
                }

                var partes = r.split('.');
                var decimales = (partes[1] || '').replace(/0+$/, '');

                if (decimales.length === 1) {
                    decimales += '0';
                }

                return prefijo + agrupar(partes[0]) + (decimales ? DECIMAL + decimales : '');
            }

            /**
             * Para LEER un importe calculado en JS (Number o texto): «$ 8.500», «-$ 1.200», «—» si no hay valor.
             * `Intl` en espanol NO agrupa los miles de 4 cifras («8500 COP» junto a «17.000 COP»): aqui siempre se agrupa.
             * Solo presentacion: redondea a 2 decimales para mostrar y nunca altera el valor que se guarda o se envia.
             */
            function precio(valor) {
                if (valor === null || valor === undefined || valor === '') {
                    return '—';
                }

                var n = Number(valor);

                if (!isFinite(n)) {
                    return String(valor);
                }

                return (n < 0 ? '-' : '') + formatear(Math.abs(n).toFixed(2));
            }

            /** Lo ESCRITO → { raw, texto } o { invalido: true } si el digito/decimal de mas no cabe (se ignora la tecla). */
            function interpretar(escrito, o) {
                o = o || {};

                var maxEnteros = o.maxEnteros || 15;
                var maxDecimales = o.maxDecimales === undefined ? 2 : o.maxDecimales;
                var prefijo = o.prefijo === undefined ? PREFIJO : o.prefijo;
                var t = String(escrito === null || escrito === undefined ? '' : escrito).replace(/[^\d.,]/g, '');
                var enteros;
                var decimales = null;
                var coma = t.indexOf(DECIMAL);

                if (coma >= 0) {
                    enteros = t.slice(0, coma).replace(/\./g, '');
                    decimales = t.slice(coma + 1).replace(/[.,]/g, '');
                } else if (o.pegado && maxDecimales > 0 && /^\d+\.\d{1,2}$/.test(t)) {
                    enteros = t.split('.')[0];
                    decimales = t.split('.')[1];
                } else {
                    enteros = t.replace(/\./g, '');
                }

                if (enteros.length > maxEnteros || (decimales !== null && (maxDecimales === 0 || decimales.length > maxDecimales))) {
                    return { invalido: true };
                }

                enteros = enteros.replace(/^0+(?=\d)/, '');

                if (enteros === '' && decimales !== null) {
                    enteros = '0';
                }

                return {
                    raw: enteros === '' ? '' : enteros + (decimales ? '.' + decimales : ''),
                    texto: enteros === '' ? '' : prefijo + agrupar(enteros) + (decimales !== null ? DECIMAL + decimales : ''),
                };
            }

            /** Lo que emite un input type="number" con v-model: Number, o '' si esta vacio. */
            function aNumero(raw) {
                if (raw === '' || raw === null || raw === undefined) {
                    return '';
                }

                var n = parseFloat(raw);

                return isNaN(n) ? raw : n;
            }

            function iguales(a, b) {
                var x = crudo(a);
                var y = crudo(b);

                if (x === null || y === null) {
                    return String(a) === String(b);
                }

                return x === y || (x !== '' && y !== '' && Number(x) === Number(y));
            }

            function contar(texto) {
                return (texto.match(/[\d,]/g) || []).length;
            }

            /** Posicion del cursor tras `n` digitos/comas del texto formateado. */
            function posicion(texto, n) {
                if (n <= 0) {
                    var primero = texto.search(/[\d,]/);

                    return primero < 0 ? texto.length : primero;
                }

                var vistos = 0;

                for (var i = 0; i < texto.length; i++) {
                    if (/[\d,]/.test(texto.charAt(i))) {
                        vistos++;

                        if (vistos === n) {
                            return i + 1;
                        }
                    }
                }

                return texto.length;
            }

            /** Al salir del campo: sin coma colgante ni ceros de mas. */
            function mostrar(raw, prefijo) {
                if (raw === '') {
                    return '';
                }

                var partes = raw.split('.');

                return (prefijo === undefined ? PREFIJO : prefijo) + agrupar(partes[0]) + (partes[1] ? DECIMAL + partes[1] : '');
            }

            /**
             * Conecta un <input type="text"> a la mascara. `alCambiar(raw)` se llama SOLO cuando el usuario cambia el valor
             * (nunca al poner el valor inicial). `poner(valor)` fija el valor desde afuera sin emitir.
             */
            function vincular(input, o) {
                o = o || {};

                var estado = { raw: '', texto: '', especial: false, editado: false };

                function aplicar(r, tokensAntes) {
                    input.value = r.texto;
                    estado.texto = r.texto;
                    estado.editado = true;

                    if (global.document.activeElement === input) {
                        try {
                            var p = posicion(r.texto, tokensAntes);
                            input.setSelectionRange(p, p);
                        } catch (e) { /* tipo sin seleccion */ }
                    }

                    if (r.raw !== estado.raw) {
                        estado.raw = r.raw;
                        estado.especial = false;

                        if (o.alCambiar) {
                            o.alCambiar(r.raw);
                        }
                    }
                }

                function alEscribir(evento) {
                    var tipo = evento && evento.inputType ? evento.inputType : '';
                    var caret = input.selectionStart === null || input.selectionStart === undefined ? input.value.length : input.selectionStart;
                    var tokensAntes = contar(input.value.slice(0, caret));
                    var opciones = { maxEnteros: o.maxEnteros, maxDecimales: o.maxDecimales, prefijo: o.prefijo, pegado: /paste|drop/i.test(tipo) };
                    var r = interpretar(input.value, opciones);

                    if (r.invalido) {
                        // Un digito o decimal de mas: se ignora la tecla y el campo queda como estaba.
                        input.value = estado.texto;

                        try {
                            var q = posicion(estado.texto, Math.max(0, tokensAntes - 1));
                            input.setSelectionRange(q, q);
                        } catch (e) { /* noop */ }

                        return;
                    }

                    // Borrar solo un separador de miles no cambia el valor: se borra el digito vecino, como espera el usuario.
                    if (/^delete(ContentBackward|ContentForward)$/.test(tipo) && !estado.especial && r.raw === estado.raw && r.texto === estado.texto) {
                        var tokens = input.value.replace(/[^\d,]/g, '');
                        var indice = tipo === 'deleteContentBackward' ? tokensAntes - 1 : tokensAntes;

                        if (indice >= 0 && indice < tokens.length) {
                            var ajustado = interpretar(tokens.slice(0, indice) + tokens.slice(indice + 1), opciones);

                            if (!ajustado.invalido) {
                                r = ajustado;
                                tokensAntes = indice;
                            }
                        }
                    }

                    aplicar(r, tokensAntes);
                }

                input.addEventListener('input', alEscribir);
                input.addEventListener('blur', function () {
                    // Solo se normaliza lo que el usuario escribio (coma colgante); un valor sin tocar se queda como se mostro.
                    if (!estado.especial && estado.editado) {
                        input.value = mostrar(estado.raw, o.prefijo);
                        estado.texto = input.value;
                    }
                });

                return {
                    poner: function (valor) {
                        var r = crudo(valor);

                        if (r === null) {
                            // No es un numero plano: se muestra tal cual y no se interpreta.
                            estado.raw = '';
                            estado.especial = true;
                            estado.texto = String(valor);
                            input.value = estado.texto;

                            return;
                        }

                        estado.especial = false;
                        estado.editado = false;
                        estado.raw = r;
                        estado.texto = formatear(r, { prefijo: o.prefijo });
                        input.value = estado.texto;
                    },
                    crudo: function () {
                        return estado.raw;
                    },
                };
            }

            function decimalesDelPaso(paso) {
                if (paso === '1') {
                    return 0;
                }

                var m = /^\d*\.(\d+)$/.exec(paso || '');

                return m ? m[1].length : 2;
            }

            /**
             * Mejora un <input> de un formulario servido por Blade. El original queda OCULTO y es la fuente de verdad (conserva
             * name, value y eventos: Vue/VeeValidate/POST lo ven igual); el campo visible escribe en el con los mismos eventos
             * que un usuario. `required`/`min` pasan al campo visible (un campo oculto invalido bloquearia el envio sin avisar).
             */
            function mejorar(original) {
                if (original.getAttribute('data-moneda-listo') || !original.parentNode) {
                    return null;
                }

                var doc = global.document;
                var visible = doc.createElement('input');
                var minimo = parseFloat(original.getAttribute('min'));
                var decimales = original.getAttribute('data-moneda-decimales');
                var maxDecimales = decimales !== null && decimales !== '' ? parseInt(decimales, 10) : decimalesDelPaso(original.getAttribute('step'));

                original.setAttribute('data-moneda-listo', '1');

                visible.type = 'text';
                visible.setAttribute('inputmode', maxDecimales > 0 ? 'decimal' : 'numeric');
                visible.setAttribute('autocomplete', 'off');
                visible.className = original.className;
                visible.placeholder = original.getAttribute('placeholder') || '';
                visible.required = original.required;
                visible.readOnly = original.readOnly;
                visible.disabled = original.disabled;

                if (original.id) {
                    visible.id = original.id;
                    original.id = original.id + '-valor';
                }

                ['aria-label', 'title', 'data-testid'].forEach(function (a) {
                    if (original.getAttribute(a)) {
                        visible.setAttribute(a, original.getAttribute(a));
                    }
                });

                original.removeAttribute('required');
                original.removeAttribute('min');
                original.removeAttribute('max');
                original.style.display = 'none';
                original.setAttribute('tabindex', '-1');
                original.setAttribute('aria-hidden', 'true');
                original.parentNode.insertBefore(visible, original);

                var visto = original.value;

                function validar(raw) {
                    visible.setCustomValidity(!isNaN(minimo) && raw !== '' && Number(raw) < minimo ? 'El valor debe ser mayor o igual a ' + minimo + '.' : '');
                }

                var enlace = vincular(visible, {
                    maxDecimales: maxDecimales,
                    prefijo: original.hasAttribute('data-moneda-sin-prefijo') ? '' : undefined,
                    alCambiar: function (raw) {
                        original.value = raw;
                        visto = original.value;
                        validar(raw);
                        original.dispatchEvent(new global.Event('input', { bubbles: true }));
                        original.dispatchEvent(new global.Event('change', { bubbles: true }));
                    },
                });

                enlace.poner(original.value);
                validar(enlace.crudo());

                // Vue/JS pueden cambiar el valor del original sin eventos (p. ej. al abrir un registro): se refleja en el visible.
                var temporizador = global.setInterval(function () {
                    if (!original.isConnected) {
                        visible.remove();
                        global.clearInterval(temporizador);

                        return;
                    }

                    if (original.value !== visto) {
                        visto = original.value;

                        if (!iguales(visto, enlace.crudo())) {
                            enlace.poner(visto);
                            validar(enlace.crudo());
                        }
                    }

                    visible.readOnly = original.readOnly;
                    visible.disabled = original.disabled;
                }, 300);

                return { visible: visible, enlace: enlace };
            }

            function mejorarTodo(raiz) {
                var doc = global.document;

                Array.prototype.forEach.call((raiz || doc).querySelectorAll('input[data-moneda]:not([data-moneda-listo])'), mejorar);
            }

            function iniciar() {
                var doc = global.document;
                var pendiente = false;

                // Vue monta el panel en `load`; se mejora DESPUES (si no, Vue recrearia los campos). Luego, lo que aparezca.
                global.setTimeout(function () {
                    mejorarTodo();

                    new global.MutationObserver(function () {
                        if (pendiente) {
                            return;
                        }

                        pendiente = true;
                        global.requestAnimationFrame(function () {
                            pendiente = false;
                            mejorarTodo();
                        });
                    }).observe(doc.body, { childList: true, subtree: true });
                }, 50);
            }

            global.ByLopezMoneda = {
                prefijo: PREFIJO, crudo: crudo, formatear: formatear, precio: precio, interpretar: interpretar, aNumero: aNumero,
                iguales: iguales, vincular: vincular, mejorar: mejorar, mejorarTodo: mejorarTodo,
            };

            if (global.document && global.addEventListener) {
                if (global.document.readyState === 'complete') {
                    iniciar();
                } else {
                    global.addEventListener('load', iniciar);
                }
            }
        })(window);
    </script>

    <script type="module">
        // Componente Vue: <v-moneda v-model="..."></v-moneda>. Muestra "$ 100.000" y emite el valor crudo (texto, o Number con `numero`).
        app.component('v-moneda', {
            inheritAttrs: false,

            props: {
                modelValue: { default: '' },
                numero: { type: Boolean, default: false },
                maxEnteros: { type: Number, default: 15 },
                maxDecimales: { type: Number, default: 2 },
                // El formulario ya pinta su propio simbolo de moneda junto al campo: no se repite.
                sinPrefijo: { type: Boolean, default: false },
            },

            emits: ['update:modelValue'],

            template: '<input ref="campo" type="text" inputmode="decimal" autocomplete="off" v-bind="$attrs">',

            mounted() {
                this.enlace = window.ByLopezMoneda.vincular(this.$refs.campo, {
                    maxEnteros: this.maxEnteros,
                    maxDecimales: this.maxDecimales,
                    prefijo: this.sinPrefijo ? '' : undefined,
                    alCambiar: (raw) => this.$emit('update:modelValue', this.numero ? window.ByLopezMoneda.aNumero(raw) : raw),
                });

                // El valor inicial solo se MUESTRA con formato; no se emite ni se reescribe.
                this.enlace.poner(this.modelValue);
            },

            watch: {
                modelValue(nuevo) {
                    // Cambios que vienen de afuera (otro codigo, reinicio del formulario); los propios no se reponen mientras se escribe.
                    if (!window.ByLopezMoneda.iguales(nuevo, this.enlace.crudo())) {
                        this.enlace.poner(nuevo);
                    }
                },
            },
        });
    </script>
@endPushOnce
