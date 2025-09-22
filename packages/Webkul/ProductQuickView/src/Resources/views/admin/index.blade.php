<x-admin::layouts>
    <x-slot:title>
        @lang('productquickview::app.admin.product-quickview.title')
    </x-slot>

    <v-product-quickview-config></v-product-quickview-config>

    @pushOnce('scripts')
    @routes
        <script type="text/x-template" id="v-product-quickview-config-template">
            <div class="max-w-[1000px] mx-auto">
                <div class="p-4 bg-white rounded box-shadow">
                    <div class="flex gap-4 items-center justify-between mb-8">
                        <p class="text-xl text-gray-800 font-bold">
                            @lang('productquickview::app.admin.product-quickview.title')
                        </p>

                        <div class="flex gap-2">
                            <button 
                                type="button"
                                class="secondary-button"
                                @click="resetSettings"
                            >
                                @lang('productquickview::app.admin.settings.resetsettings')
                            </button>

                            <button 
                                type="submit"
                                class="primary-button"
                                @click="saveSettings"
                            >
                                @lang('productquickview::app.admin.settings.savesettings')
                            </button>
                        </div>
                    </div>

                    <x-admin::form
                        v-slot="{ meta, errors, handleSubmit }"
                        as="div"
                    >
                        <form 
                            id="productQuickviewForm"
                            @submit.prevent="handleSubmit($event, save)"
                        >
                            <div class="grid gap-4">

                                <div class="flex gap-2.5 justify-between items-center">
                                    <p class="text-gray-800 font-medium">
                                        @lang('productquickview::app.admin.settings.show_sku')
                                    </p>

                                    <div class="flex gap-2.5 items-center">
                                        <x-admin::form.control-group>
                                            <x-admin::form.control-group.control
                                                type="switch"
                                                name="show_sku"
                                                v-model="settings.show_sku"
                                               ::checked="settings.show_sku"
                                                @change="handleSwitchChange($event, 'show_sku')"
                                            />
                                        </x-admin::form.control-group>
                                    </div>
                                </div>

                                <div class="flex gap-2.5 justify-between items-center">
                                    <p class="text-gray-800 font-medium">
                                        @lang('productquickview::app.admin.settings.show_product_number')
                                    </p>

                                    <div class="flex gap-2.5 items-center">
                                        <x-admin::form.control-group>
                                            <x-admin::form.control-group.control
                                                type="switch"
                                                name="show_product_number"
                                                v-model="settings.show_product_number"
                                                ::checked="settings.show_product_number"
                                                @change="handleSwitchChange($event, 'show_product_number')"
                                            />
                                        </x-admin::form.control-group>
                                    </div>
                                </div>

                                <div class="flex gap-2.5 justify-between items-center">
                                    <p class="text-gray-800 font-medium">
                                        @lang('productquickview::app.admin.settings.show_quantity')
                                    </p>

                                    <div class="flex gap-2.5 items-center">
                                        <x-admin::form.control-group>
                                            <x-admin::form.control-group.control
                                            type="switch"
                                            name="show_quantity"
                                            v-model="settings.show_quantity"
                                            ::checked="settings.show_quantity"
                                            @change="handleSwitchChange($event, 'show_quantity')"
                                            />
                                        </x-admin::form.control-group>
                                    </div>
                                </div>

                                <div class="flex gap-2.5 justify-between items-center">
                                    <p class="text-gray-800 font-medium">
                                        @lang('productquickview::app.admin.settings.show_full_description')
                                    </p>

                                    <div class="flex gap-2.5 items-center">
                                        <x-admin::form.control-group>
                                            <x-admin::form.control-group.control
                                                type="switch"
                                                name="show_full_description"
                                                v-model="settings.show_full_description"
                                                ::checked="settings.show_full_description"
                                                @change="handleSwitchChange($event, 'show_full_description')"
                                            />
                                        </x-admin::form.control-group>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </x-admin::form>
                </div>
            </div>
        </script>

        <script type="module">
            app.component('v-product-quickview-config', {
                template: '#v-product-quickview-config-template',

                data() {
                    return {
                        settings: {
                            show_full_description: true,
                            show_product_number: true,
                            show_quantity: true,
                            show_sku: true,
                        },
                        isLoading: false,
                    }
                },

                async created() {
                    await this.loadSettings();
                },

                methods: {
                    async loadSettings() {
                        try {
                            const response = await axios.get(route('admin.productquickview.getSettings'));
                            this.settings = {
                                show_full_description: Boolean(response.data.show_full_description),
                                show_product_number: Boolean(response.data.show_product_number),
                                show_quantity: Boolean(response.data.show_quantity),
                                show_sku: Boolean(response.data.show_sku),
                            };
                        } catch (error) {
                            console.error('Error loading settings:', error);
                        }
                    },

                    handleSwitchChange(event, setting) {
                        this.settings[setting] = event.target.checked;
                    },  

                    async saveSettings() {
                        this.isLoading = true;

                        try {
                            const settingsData = {
                                show_full_description: this.settings.show_full_description,
                                show_product_number: this.settings.show_product_number,
                                show_quantity: this.settings.show_quantity,
                                show_sku: this.settings.show_sku,
                            };

                            const response = await axios.post(route('admin.productquickview.update'), settingsData);

                            if (response.data.settings) {
                                this.settings = {
                                    show_full_description: Boolean(response.data.settings.show_full_description),
                                    show_product_number: Boolean(response.data.settings.show_product_number),
                                    show_quantity: Boolean(response.data.settings.show_quantity),
                                    show_sku: Boolean(response.data.settings.show_sku),
                                };
                            }

                            this.$emitter.emit('add-flash', {
                                type: 'success',
                                message: response.data.message,
                            });
                        } catch (error) {
                            console.error('Error saving settings:', error);
                        } finally {
                            this.isLoading = false;
                        }
                    },

                    async resetSettings() {
                        console.log('Resetting settings to default (all true)...');

                        // Set all settings to true
                        const defaultSettings = {
                            show_full_description: true,
                            show_product_number: true,
                            show_quantity: true,
                            show_sku: true,
                        };

                        try {
                            // Send a request to update the database
                            const response = await axios.post(route('admin.productquickview.update'), {
                                show_full_description: true,
                                show_product_number: true,
                                show_quantity: true,
                                show_sku: true,
                            });

                            // Update Vue state only after DB update succeeds
                            if (response.data.settings) {
                                this.settings = {
                                    show_full_description: response.data.settings.show_full_description === true,
                                    show_product_number: response.data.settings.show_product_number === true,
                                    show_quantity: response.data.settings.show_quantity === true,
                                    show_sku: response.data.settings.show_sku === true,
                                };
                            }

                            // Show success message
                            this.$emitter.emit('add-flash', {
                                type: 'info',
                                message: 'Settings have been reset to default.'
                            });
                        } catch (error) {
                            console.error('Error resetting settings:', error);
                            this.$emitter.emit('add-flash', {
                                type: 'error',
                                message: 'Failed to reset settings.'
                            });
                        }
                    },
                }
            });
        </script>
    @endPushOnce
    
</x-admin::layouts>