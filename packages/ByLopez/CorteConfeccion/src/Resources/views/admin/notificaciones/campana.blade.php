{{--
    Avisos de Corte y Confeccion dentro de la campana NATIVA de Bagisto (sin otra campana ni modificar packages/Webkul).

    Se inyecta con el evento `bagisto.admin.layout.vue-app-mount.before`, que corre despues de que el header registra
    `v-notifications` y antes de app.mount(). Extiende ESE componente: inserta los avisos del usuario en la misma lista
    (antes de los pedidos), suma sus no leidos al mismo contador y «marcar todo como leido» los incluye.
    Si Bagisto cambia la plantilla y el punto de anclaje no aparece, no toca nada: la campana sigue como siempre y los
    avisos siguen disponibles en el listado de notificaciones.
--}}
@if (auth()->guard('admin')->check() && \ByLopez\CorteConfeccion\Support\ConsolidadoConfeccionGate::puedeVer())
    <script type="text/x-template" id="cc-avisos-campana">
        <a
            class="flex items-start gap-1.5 border-b p-3 hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-950"
            v-for="aviso in ccAvisos"
            :key="'cc-' + aviso.id"
            :href="aviso.url"
            data-cc-aviso
        >
            <span
                class="icon-information h-fit rounded-full text-2xl"
                :class="aviso.hacia === 'urgente' ? 'bg-red-100 text-red-600 dark:!text-red-600' : 'bg-amber-100 text-amber-600 dark:!text-amber-600'"
            ></span>

            <div class="grid">
                <p class="text-gray-800 dark:text-white">@{{ aviso.titulo }}</p>
                <p class="text-xs text-gray-600 dark:text-gray-300">@{{ aviso.texto }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">@{{ aviso.autor ? aviso.autor + ' · ' : '' }}@{{ aviso.fecha }}</p>
            </div>
        </a>
    </script>

    <script type="module">
        (function () {
            var plantilla = document.getElementById('v-notifications-template');
            var avisos = document.getElementById('cc-avisos-campana');
            var base = window.app && window.app._context && window.app._context.components['v-notifications'];

            if (!plantilla || !avisos || !base || !base.methods) { return; }

            var html = plantilla.innerHTML;
            var ancla = html.indexOf('v-for="notification in notifications"');
            var inicio = ancla < 0 ? -1 : html.lastIndexOf('<a', ancla);

            if (inicio < 0) { return; }

            plantilla.innerHTML = html.slice(0, inicio) + avisos.innerHTML + html.slice(inicio)
                .replace('v-if="notifications?.length"', 'v-if="notifications?.length || ccAvisos.length"');

            var urls = {
                pedidos: @json(route('admin.notification.get_notification')),
                pedidosLeer: @json(route('admin.notification.read_all')),
                avisos: @json(route('admin.corte_confeccion.notificaciones.lista')),
                avisosLeer: @json(route('admin.corte_confeccion.notificaciones.leer_todas')),
            };

            window.app.component('v-notifications', Object.assign({}, base, {
                data: function () {
                    var datos = base.data ? base.data.call(this) : {};
                    datos.ccAvisos = [];
                    return datos;
                },

                methods: Object.assign({}, base.methods, {
                    getNotification: function () {
                        var self = this;

                        Promise.all([
                            this.$axios.get(urls.pedidos, { params: { limit: 5, read: 0 } }).then(function (r) { return r.data; }).catch(function () { return null; }),
                            this.$axios.get(urls.avisos).then(function (r) { return r.data; }).catch(function () { return null; }),
                        ]).then(function (res) {
                            if (res[0]) { self.notifications = res[0].search_results.data; }
                            self.ccAvisos = res[1] ? res[1].avisos : [];
                            self.totalUnRead = (res[0] ? res[0].total_unread : 0) + (res[1] ? res[1].no_leidas : 0);
                        });
                    },

                    readAll: function () {
                        var self = this;

                        Promise.all([
                            this.$axios.post(urls.pedidosLeer).then(function (r) { return r.data; }).catch(function () { return null; }),
                            this.$axios.post(urls.avisosLeer).then(function (r) { return r.data; }).catch(function () { return null; }),
                        ]).then(function (res) {
                            if (res[0]) { self.notifications = res[0].search_results.data; }
                            if (res[1]) { self.ccAvisos = []; }
                            self.totalUnRead = (res[0] ? res[0].total_unread : 0) + (res[1] ? 0 : self.ccAvisos.length);
                            if (res[0] && res[0].success_message) { self.$emitter.emit('add-flash', { type: 'success', message: res[0].success_message }); }
                        });
                    },
                }),
            }));
        })();
    </script>
@endif
