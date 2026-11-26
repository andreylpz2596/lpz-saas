{{--
    Ajustes moviles compartidos de los modulos ByLopez (iPhone/Safari y pantallas tactiles). Solo en rutas ByLopez:
    las pantallas nativas de Bagisto no cambian. No toca la etiqueta viewport: el zoom manual sigue disponible.
    Auditoria: packages/ByLopez/VentasAsesor/docs/36_AUDITORIA_RESPONSIVE_IPHONE.md
--}}
@if (request()->routeIs('admin.ventas_asesor.*', 'admin.logistica.*', 'admin.corte_confeccion.*', 'admin.cargues.*', 'admin.autoservicio.*'))
<style data-bylopez="responsive-movil">
    /* 1. Safari de iPhone acerca la pantalla al enfocar un campo con letra menor de 16 px y no vuelve a alejarla: hay que
          pellizcar para seguir (p. ej. las cantidades de la modal de tallas, con 14 px). Con 16 px no hay acercamiento
          automatico. Solo en pantallas tactiles; los campos grandes (text-lg en adelante) conservan su tamano. */
    @media (pointer: coarse) {
        input:not([type="checkbox"]):not([type="radio"]):not([type="range"]):not([type="color"]):not([type="file"]):not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="reset"]):not(.text-lg):not(.text-xl):not(.text-2xl):not(.text-3xl),
        select:not(.text-lg):not(.text-xl):not(.text-2xl),
        textarea:not(.text-lg):not(.text-xl):not(.text-2xl) {
            font-size: 16px;
        }

        /* Casillas nativas de 12-13 px: dificiles de acertar con el dedo. Las casillas ocultas que Bagisto dibuja con
           iconos (peer/hidden/sr-only) no se tocan. */
        input[type="checkbox"]:not(.hidden):not(.sr-only):not(.peer),
        input[type="radio"]:not(.hidden):not(.sr-only):not(.peer) {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }

        /* Cerrar drawer/modal de Bagisto: el icono mide 30 px; el area tactil pasa a 44 px sin moverlo. */
        div.fixed[class*="z-[10002]"] :is(.icon-cross, .icon-cancel-1).cursor-pointer {
            display: inline-block;
            padding: 7px;
            margin: -7px;
        }
    }

    /* 2. El contenedor del layout de Bagisto (`overflow-x-hidden`) se vuelve un desplazador vertical diminuto cuando la
          pagina mide unos pixeles mas que el (margenes negativos): consume el gesto tactil al cambiar de direccion y
          anula `position: sticky`. `clip` recorta igual sin crear desplazamiento (guardia-scroll ya lo hacia solo para
          Logistica y pedidos; Cargues seguia con la trampa). */
    div.max-w-full.flex-1.flex-col > div.pb-4 > div.w-full.overflow-x-hidden {
        overflow-x: clip;
        overflow-y: visible;
    }

    /* 3. Drawers y modales de Bagisto: el desplazamiento de su contenido no arrastra la pagina de fondo. */
    div.fixed.inset-y-0[class*="z-[10002]"] .overflow-auto,
    div.fixed.inset-0.overflow-y-auto[class*="z-[10002]"] {
        overscroll-behavior: contain;
        scroll-padding-block: 12px 24px;
    }

    /* 4. Modal de Bagisto: su caja va al fondo de un contenedor de 100vh. En Safari 100vh es la altura con las barras
          ocultas, asi que con las barras visibles el boton principal puede quedar debajo de la barra inferior. */
    @supports (min-height: 100dvh) {
        div.fixed.inset-0.overflow-y-auto[class*="z-[10002]"] > div.min-h-screen {
            min-height: 100dvh;
        }
    }

    /* 5. Telefono en horizontal (poca altura): el pie del drawer deja 32 px vacios debajo de los botones y le quita
          espacio a la lista; se reduce a 12 px. */
    @media (max-height: 500px) and (orientation: landscape) {
        div.fixed.inset-y-0[class*="z-[10002]"] div.flex.h-full.flex-col > div.pb-8:last-child {
            padding-bottom: 12px;
        }
    }
</style>
@endif
