/** @type {import('tailwindcss').Config} */
module.exports = {
    content: ["./src/Resources/**/*.blade.php"],

    theme: {
        screens: {
            sm: "525px",
            md: "768px",
            lg: "1024px",
            xl: "1240px",
            "2xl": "1920px",
        },

        extend: {},
    },

    // Debe coincidir con packages/Webkul/Admin/tailwind.config.js para que el toggle
    // de modo oscuro (clase "dark" en <html>) funcione igual en las vistas de este paquete.
    darkMode: "class",

    // CRITICO: sin esto, utilidades genericas propias (ej. .px-2, .pt-3) se compilan
    // sin condicion y, como este CSS carga despues del bundle de Webkul (via @stack
    // ('styles') en su layout), le ganan en cascada a clases responsive de Webkul
    // como lg:pl-[286px] que reserva el espacio del sidebar fijo -- el sidebar termina
    // tapando el contenido. Escopar todo bajo .bylopez-scope hace que nuestras
    // utilidades solo puedan aplicar DENTRO de nuestro propio wrapper, nunca a
    // elementos del layout de Webkul que envuelven ese wrapper. Ver
    // Resources/views/admin/partials/estilos.blade.php (agrega la clase al <body>)
    // y cada vista de nivel superior (envuelve su contenido en <div class="bylopez-scope">).
    important: ".bylopez-scope",

    plugins: [],
};
