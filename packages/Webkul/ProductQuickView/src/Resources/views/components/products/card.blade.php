<v-product-card
    {{ $attributes }}
    :product="product"
>
</v-product-card>

@pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-product-card-template"
    >
        <!-- Grid Card -->
        <div
            class="1180:transtion-all group w-full rounded-md 1180:relative 1180:grid 1180:content-start 1180:overflow-hidden 1180:duration-300 1180:hover:shadow-[0_5px_10px_rgba(0,0,0,0.1)]"
            v-if="mode != 'list'"
        >
            <div class="relative max-h-[300px] max-w-[291px] overflow-hidden max-md:max-h-60 max-md:max-w-full max-md:rounded-lg max-sm:max-h-[200px] max-sm:max-w-full">
                {!! view_render_event('bagisto.shop.components.products.card.image.before') !!}

                <!-- Product Image -->
                <a
                    :href="`{{ route('shop.product_or_category.index', '') }}/${product.url_key}`"
                    :aria-label="product.name + ' '"
                >
                    <x-shop::media.images.lazy
                        class="after:content-[' '] relative bg-zinc-100 transition-all duration-300 after:block after:pb-[calc(100%+9px)] group-hover:scale-105"
                        ::src="product.base_image.medium_image_url"
                        ::key="product.id"
                        ::index="product.id"
                        width="291"
                        height="300"
                        ::alt="product.name"
                    />
                </a>

                {!! view_render_event('bagisto.shop.components.products.card.image.after') !!}
                
                <!-- Product Ratings -->
                {!! view_render_event('bagisto.shop.components.products.card.average_ratings.before') !!}

                @if (core()->getConfigData('catalog.products.review.summary') == 'star_counts')
                    <x-shop::products.ratings
                        class="absolute bottom-1.5 items-center !border-white bg-white/80 !px-2 !py-1 text-xs max-sm:!px-1.5 max-sm:!py-0.5 ltr:left-1.5 rtl:right-1.5"
                        ::average="product.ratings.average"
                        ::total="product.ratings.total"
                        ::rating="false"
                        v-if="product.ratings.total"
                    />
                @else
                    <x-shop::products.ratings
                        class="absolute bottom-1.5 items-center !border-white bg-white/80 !px-2 !py-1 text-xs max-sm:!px-1.5 max-sm:!py-0.5 ltr:left-1.5 rtl:right-1.5"
                        ::average="product.ratings.average"
                        ::total="product.reviews.total"
                        ::rating="false"
                        v-if="product.reviews.total"
                    />
                @endif

                {!! view_render_event('bagisto.shop.components.products.card.average_ratings.after') !!}

                <div class="action-items bg-black">
                    <!-- Product Sale Badge -->
                    <p
                        class="absolute top-1.5 inline-block rounded-[44px] bg-red-600 px-2.5 text-sm text-white max-sm:rounded-l-none max-sm:rounded-r-xl max-sm:px-2 max-sm:py-0.5 max-sm:text-xs ltr:left-1.5 max-sm:ltr:left-0 rtl:right-5 max-sm:rtl:right-0"
                        v-if="product.on_sale"
                    >
                        @lang('shop::app.components.products.card.sale')
                    </p>

                    <!-- Product New Badge -->
                    <p
                        class="absolute top-1.5 inline-block rounded-[44px] bg-navyBlue px-2.5 text-sm text-white max-sm:rounded-l-none max-sm:rounded-r-xl max-sm:px-2 max-sm:py-0.5 max-sm:text-xs ltr:left-1.5 max-sm:ltr:left-0 rtl:right-1.5 max-sm:rtl:right-0"
                        v-else-if="product.is_new"
                    >
                        @lang('shop::app.components.products.card.new')
                    </p>

                    <div class="opacity-0 transition-all duration-300 group-hover:bottom-0 group-hover:opacity-100 max-lg:opacity-100 max-sm:opacity-100">

                        {!! view_render_event('bagisto.shop.components.products.card.wishlist_option.before') !!}

                        @if (core()->getConfigData('customer.settings.wishlist.wishlist_option'))
                            <span
                                class="absolute top-2.5 flex h-6 w-6 items-center justify-center rounded-full border border-zinc-200 bg-white text-lg md:hidden ltr:right-1.5 rtl:left-1.5"
                                role="button"
                                aria-label="@lang('shop::app.components.products.card.add-to-wishlist')"
                                tabindex="0"
                                :class="product.is_wishlist ? 'icon-heart-fill text-red-500' : 'icon-heart'"
                                @click="addToWishlist()"
                            >
                            </span>
                        @endif

                        {!! view_render_event('bagisto.shop.components.products.card.wishlist_option.after') !!}

                        {!! view_render_event('bagisto.shop.components.products.card.compare_option.before') !!}

                        @if (core()->getConfigData('catalog.products.settings.compare_option'))
                            <span
                                class="icon-compare absolute top-10 flex h-6 w-6 items-center justify-center rounded-full border border-zinc-200 bg-white text-lg sm:hidden ltr:right-1.5 rtl:left-1.5"
                                role="button"
                                aria-label="@lang('shop::app.components.products.card.add-to-compare')"
                                tabindex="0"
                                @click="addToCompare(product.id)"
                            >
                            </span>
                        @endif

                        {!! view_render_event('bagisto.shop.components.products.card.compare_option.after') !!}

                        <span 
                            class="absolute top-10 flex h-6 w-6 items-center justify-center rounded-full border border-zinc-200 bg-white text-lg sm:hidden ltr:right-1.5 rtl:left-1.5"
                            @click="openQuickView(product)"
                            aria-label="Quick View"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path d="M12 4.5c-7.5 0-10 7.5-10 7.5s2.5 7.5 10 7.5 10-7.5 10-7.5-2.5-7.5-10-7.5z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                        </span>

                        <!-- Product Preview Modal - Moved outside the product cards -->
                        <Teleport to="body">
                            <div 
                                v-if="showModal" 
                                class="fixed inset-0 z-[60] flex items-center justify-center p-3"
                                role="dialog"
                                aria-modal="true"
                            >
                                <!-- Modal Backdrop -->
                                <div 
                                    class="fixed inset-0 bg-black bg-opacity-50 transition-opacity"
                                    @click="closeQuickView"
                                ></div>
                            
                                <!-- Modal Container -->
                                <div 
                                    class="relative w-auto max-w-4xl bg-white rounded-lg overflow-hidden sm:h-[300px]"
                                    style="width: 850px; height: 650px;" 
                                    @click.stop
                                >
                                    <!-- Close Button -->
                                    <button 
                                        class="absolute top-2 right-2 z-10 flex h-8 w-8 items-center justify-center rounded-full"
                                        style="background-color:#e5e7eb;"
                                        @click="closeQuickView"
                                        aria-label="Close preview"
                                    >
                                    <img src="https://cdn.jsdelivr.net/npm/heroicons@2.0.16/24/outline/x-mark.svg" alt="Close" class="h-4 w-4">
                                    </button>

                        
                                    <!-- Modal Content - Using flex instead of grid -->
                                    <div class="flex flex-row h-[600px]">
                                        <!-- Left Side - Image -->
                                        <div class="w-1/2 bg-gray-50 p-6 flex items-center justify-center" style="min-height: 100%; height: 100%;">
                                            <x-shop::media.images.lazy
                                                class="max-h-[300px] md:max-h-[500px] w-auto object-contain"
                                                style="max-height: 500px; width: 100%;"
                                                ::src="selectedProduct?.base_image?.medium_image_url"
                                                ::alt="selectedProduct?.name"
                                            />
                                        </div>
                    
                                        <!-- Right Side - Product Details -->
                                        <div class="w-1/2 p-4 overflow-y-auto flex flex-col">
                                            <h2 class="mb-2 text-2xl font-semibold text-gray-900">
                                                @{{ selectedProduct?.name }}
                                            </h2>
                    
                                            <div class="mb-4 text-xl font-semibold text-gray-900" v-html="selectedProduct?.price_html"></div>
                    
                                            <div class="mb-4 border-t border-b py-4 space-y-2">
                                                <!-- SKU -->
                                                <div v-if="settings.show_sku === true && selectedProduct?.sku" 
                                                    class="flex items-center gap-2 text-sm text-blue-700">
                                                    <span class="icon-product text-black font-bold" style="font-size: x-large;"></span>                
                                                    <span>@lang('productquickview::app.productquickview.sku'): @{{ selectedProduct?.sku }}</span>
                                                </div>
                                        
                                                <!-- Availability -->
                                                <div class="mt-3 font-semibold flex items-center gap-1 text-sm">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-black" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                        <path d="M5 8h14l1 12H4L5 8z" />
                                                        <path d="M9 11V6a3 3 0 0 1 6 0v5" />
                                                    </svg>                                    

                                                    <span 
                                                        :class="[
                                                            selectedProduct?.is_saleable ? 'text-emerald-600' : 'text-red-600',
                                                            'icon-check text-lg'
                                                        ]"
                                                    ></span>

                                                    <span :class="selectedProduct?.is_saleable ? 'text-emerald-600' : 'text-red-600'">
                                                        @{{ selectedProduct?.is_saleable ? '@lang('productquickview::app.productquickview.instock')' : '@lang('productquickview::app.productquickview.outofstock')' }}
                                                    </span>

                                                    <!-- Display Quantity if Available -->
                                                    <span v-if="selectedProduct?.is_saleable && settings.show_quantity" class="text-gray-700 text-sm">
                                                        (@{{ selectedProduct?.qty }} available)
                                                    </span>
                                                </div>
                    
                                                <!-- Product Number -->
                                                <div v-if="settings.show_product_number === true && selectedProduct?.product_number" 
                                                    class="mt-3 flex items-center gap-2 text-sm text-red-600">
                                                    <span class="icon-product text-black font-bold" style="font-size: x-large;"></span>                
                                                    <span>@lang('productquickview::app.productquickview.product_number'): @{{ selectedProduct?.product_number }}</span>
                                                </div>
                                            </div>
                    
                                                <!-- Short Description -->
                                                <div v-if="selectedProduct?.short_description" class="mb-4 text-sm text-gray-600 prose prose-sm"
                                                    style="max-height: 100px; overflow-y: auto;"
                                                    v-html="formattedShortDescription">
                                                </div>

                                                <!-- Full Description (Expandable) -->
                                                <div v-if="settings.show_full_description && selectedProduct?.description" class="mb-4">
                                                    <details class="border border-gray-300 rounded-lg">
                                                        <summary class="px-4 py-2 text-lg font-semibold cursor-pointer bg-gray-200 hover:bg-gray-300">
                                                            @lang('productquickview::app.productquickview.description')
                                                        </summary>
                                                        <div 
                                                            v-html="formattedFullDescription" 
                                                            class="p-4 text-sm text-gray-600 overflow-y-auto"
                                                            style="max-height: 200px;"
                                                        ></div>
                                                    </details>
                                                </div>
                    
                                            <!-- Add to Cart Button -->
                                            <button class="secondary-button w-full max-w-full p-2.5 text-sm font-medium"
                                                    :disabled="!selectedProduct?.is_saleable || isAddingToCart"
                                                    @click="addToCart(selectedProduct)">
                                                <span v-if="isAddingToCart"></span>
                                                <span v-else>@lang('productquickview::app.productquickview.addtocart')</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </Teleport>
                    </div>
                </div>
            </div>

            <!-- Product Information Section -->
            <div class="-mt-9 grid max-w-[291px] translate-y-9 content-start gap-2.5 bg-white p-2.5 transition-transform duration-300 ease-out group-hover:-translate-y-0 group-hover:rounded-t-lg max-md:relative max-md:mt-0 max-md:translate-y-0 max-md:gap-0 max-md:px-0 max-md:py-1.5 max-sm:min-w-[170px] max-sm:max-w-[192px]">

                {!! view_render_event('bagisto.shop.components.products.card.name.before') !!}
                    
                <p class="text-base font-medium max-md:mb-1.5 max-md:max-w-56 max-md:whitespace-break-spaces max-md:leading-6 max-sm:max-w-[192px] max-sm:text-sm max-sm:leading-4">
                    @{{ product.name }}
                </p>

                {!! view_render_event('bagisto.shop.components.products.card.name.after') !!}

                <!-- Pricing -->
                {!! view_render_event('bagisto.shop.components.products.card.price.before') !!}

                <div
                    class="flex items-center gap-2.5 text-lg font-semibold max-sm:text-sm max-sm:leading-6"
                    v-html="product.price_html"
                >
                </div>

                {!! view_render_event('bagisto.shop.components.products.card.price.before') !!}

                <!-- Product Actions Section -->
                <div class="action-items flex items-center justify-between opacity-0 transition-all duration-300 ease-in-out group-hover:opacity-100 max-md:hidden">
                    @if (core()->getConfigData('sales.checkout.shopping_cart.cart_page'))
                        {!! view_render_event('bagisto.shop.components.products.card.add_to_cart.before') !!}

                        <button
                            class="secondary-button w-full max-w-full p-2.5 text-sm font-medium max-sm:rounded-xl max-sm:p-2"
                            :disabled="! product.is_saleable || isAddingToCart"
                            @click="addToCart()"
                        >
                            @lang('shop::app.components.products.card.add-to-cart')
                        </button>

                        {!! view_render_event('bagisto.shop.components.products.card.add_to_cart.after') !!}
                    @endif
                    
                    {!! view_render_event('bagisto.shop.components.products.card.wishlist_option.before') !!}

                    @if (core()->getConfigData('customer.settings.wishlist.wishlist_option'))
                        <span
                            class="cursor-pointer p-2.5 text-2xl max-sm:hidden"
                            role="button"
                            aria-label="@lang('shop::app.components.products.card.add-to-wishlist')"
                            tabindex="0"
                            :class="product.is_wishlist ? 'icon-heart-fill text-red-600' : 'icon-heart'"
                            @click="addToWishlist()"
                        >
                        </span>
                    @endif

                    {!! view_render_event('bagisto.shop.components.products.card.wishlist_option.after') !!}

                    {!! view_render_event('bagisto.shop.components.products.card.compare_option.before') !!}

                    @if (core()->getConfigData('catalog.products.settings.compare_option'))
                        <span
                            class="icon-compare cursor-pointer p-2.5 text-2xl max-sm:hidden"
                            role="button"
                            aria-label="@lang('shop::app.components.products.card.add-to-compare')"
                            tabindex="0"
                            @click="addToCompare(product.id)"
                        >
                        </span>
                    @endif

                    {!! view_render_event('bagisto.shop.components.products.card.compare_option.after') !!}
                    <span 
                        class="cursor-pointer p-2.5 text-2xl max-sm:hidden"
                        @click="openQuickView(product)"
                        aria-label="Quick View"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path d="M12 4.5c-7.5 0-10 7.5-10 7.5s2.5 7.5 10 7.5 10-7.5 10-7.5-2.5-7.5-10-7.5z" />
                            <circle cx="12" cy="12" r="3" />
                        </svg>
                    </span>
                </div>
            </div>
        </div>

        <!-- List Card -->
        
        <div
            class="relative flex max-w-max grid-cols-2 gap-4 overflow-hidden rounded max-sm:flex-wrap"
            v-else
        >
            <div class="group relative max-h-[258px] max-w-[250px] overflow-hidden"> 

                {!! view_render_event('bagisto.shop.components.products.card.image.before') !!}

                <a :href="`{{ route('shop.product_or_category.index', '') }}/${product.url_key}`">
                    <x-shop::media.images.lazy
                        class="after:content-[' '] relative min-w-[250px] bg-zinc-100 transition-all duration-300 after:block after:pb-[calc(100%+9px)] group-hover:scale-105"
                        ::src="product.base_image.medium_image_url"
                        ::key="product.id"
                        ::index="product.id"
                        width="291"
                        height="300"
                        ::alt="product.name"
                    />
                </a>

                {!! view_render_event('bagisto.shop.components.products.card.image.after') !!}

                <div class="action-items bg-black">
                    <p
                        class="absolute top-5 inline-block rounded-[44px] bg-red-500 px-2.5 text-sm text-white ltr:left-5 max-sm:ltr:left-2 rtl:right-5"
                        v-if="product.on_sale"
                    >
                        @lang('shop::app.components.products.card.sale')
                    </p>

                    <p
                        class="absolute top-5 inline-block rounded-[44px] bg-navyBlue px-2.5 text-sm text-white ltr:left-5 max-sm:ltr:left-2 rtl:right-5"
                        v-else-if="product.is_new"
                    >
                        @lang('shop::app.components.products.card.new')
                    </p>

                    <div class="opacity-0 transition-all duration-300 group-hover:bottom-0 group-hover:opacity-100 max-sm:opacity-100">

                        {!! view_render_event('bagisto.shop.components.products.card.wishlist_option.before') !!}

                        @if (core()->getConfigData('customer.settings.wishlist.wishlist_option'))
                            <span 
                                class="absolute top-5 flex h-[30px] w-[30px] cursor-pointer items-center justify-center rounded-md bg-white text-2xl ltr:right-5 rtl:left-5"
                                role="button"
                                aria-label="@lang('shop::app.components.products.card.add-to-wishlist')"
                                tabindex="0"
                                :class="product.is_wishlist ? 'icon-heart-fill text-red-600' : 'icon-heart'"
                                @click="addToWishlist()"
                            >
                            </span>
                        @endif

                        {!! view_render_event('bagisto.shop.components.products.card.wishlist_option.after') !!}

                        {!! view_render_event('bagisto.shop.components.products.card.compare_option.before') !!}

                        @if (core()->getConfigData('catalog.products.settings.compare_option'))
                            <span
                                class="icon-compare absolute top-16 flex h-[30px] w-[30px] cursor-pointer items-center justify-center rounded-md bg-white text-2xl ltr:right-5 rtl:left-5"
                                role="button"
                                aria-label="@lang('shop::app.components.products.card.add-to-compare')"
                                tabindex="0"
                                @click="addToCompare(product.id)"
                            >
                            </span>
                        @endif

                        {!! view_render_event('bagisto.shop.components.products.card.compare_option.after') !!}

                        <span 
                            class="absolute top-16 flex h-[30px] w-[30px] cursor-pointer items-center justify-center rounded-md bg-white text-2xl ltr:right-5 rtl:left-5"
                            @click="openQuickView(product)"
                            aria-label="Quick View"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path d="M12 4.5c-7.5 0-10 7.5-10 7.5s2.5 7.5 10 7.5 10-7.5 10-7.5-2.5-7.5-10-7.5z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                        </span>
                    </div>
                </div>
            </div>

            <div class="grid content-start gap-4">

                {!! view_render_event('bagisto.shop.components.products.card.name.before') !!}

                <p class="text-base">
                    @{{ product.name }}
                </p>

                {!! view_render_event('bagisto.shop.components.products.card.name.after') !!}

                {!! view_render_event('bagisto.shop.components.products.card.price.before') !!}

                <div
                    class="flex gap-2.5 text-lg font-semibold"
                    v-html="product.price_html"
                >
                </div>

                {!! view_render_event('bagisto.shop.components.products.card.price.after') !!}

                <!-- Needs to implement that in future -->
                <div class="flex hidden gap-4">
                    <span class="block h-[30px] w-[30px] rounded-full bg-[#B5DCB4]">
                    </span>

                    <span class="block h-[30px] w-[30px] rounded-full bg-zinc-500">
                    </span>
                </div>

                {!! view_render_event('bagisto.shop.components.products.card.price.after') !!}

                {!! view_render_event('bagisto.shop.components.products.card.average_ratings.before') !!}

                <p class="text-sm text-zinc-500">
                    <template  v-if="! product.ratings.total">
                        <p class="text-sm text-zinc-500">
                            @lang('shop::app.components.products.card.review-description')
                        </p>
                    </template>

                    <template v-else>
                        @if (core()->getConfigData('catalog.products.review.summary') == 'star_counts')
                            <x-shop::products.ratings
                                ::average="product.ratings.average"
                                ::total="product.ratings.total"
                                ::rating="false"
                            />
                        @else
                            <x-shop::products.ratings
                                ::average="product.ratings.average"
                                ::total="product.reviews.total"
                                ::rating="false"
                            />
                        @endif
                    </template>
                </p>

                {!! view_render_event('bagisto.shop.components.products.card.average_ratings.after') !!}

                @if (core()->getConfigData('sales.checkout.shopping_cart.cart_page'))

                    {!! view_render_event('bagisto.shop.components.products.card.add_to_cart.before') !!}

                    <x-shop::button
                        class="primary-button whitespace-nowrap px-8 py-2.5"
                        :title="trans('shop::app.components.products.card.add-to-cart')"
                        ::loading="isAddingToCart"
                        ::disabled="! product.is_saleable || isAddingToCart"
                        @click="addToCart()"
                    />

                    {!! view_render_event('bagisto.shop.components.products.card.add_to_cart.after') !!}

                @endif

            </div>
        </div>

    </script>

    <script type="module">
        const SETTINGS_URL = "{{ route('admin.productquickview.getSettings') }}";

        app.component('v-product-card', {
            template: '#v-product-card-template',

            props: ['mode', 'product'],

            data() {
                return {

                    showModal: false,
                    
                    selectedProduct: null,

                    isCustomer: '{{ auth()->guard('customer')->check() }}',

                    isAddingToCart: false,

                    settings: {
                        show_full_description: true,
                        show_product_number: true,
                        show_quantity: true,
                        show_sku: true,
                    },

                }
            },

            async created() {
                await this.loadSettings();
            },

            computed: {
                formattedShortDescription() {
                    if (this.selectedProduct?.short_description) {
                        return this.selectedProduct.short_description.replace(/(\. )/g, '.<br>');
                    }
                    return '';
                },
                formattedFullDescription() {
                    if (this.selectedProduct?.description) {
                        return this.selectedProduct.description.replace(/(\. )/g, '.<br>');
                    }
                    return '';
                }
            },

            methods: {
                async loadSettings() {
                    try {
                        const response = await this.$axios.get(SETTINGS_URL);
                        
                        // Explicitly convert to boolean values
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
                async saveSettings() {
                    this.isLoading = true;
                    try {
                        const response = await axios.post(route('admin.productquickview.update'), this.settings);
                        
                        if (response.data.settings) {
                            this.$set(this, 'settings', { ...response.data.settings });
                        }
                        
                        // Re-fetch settings to update frontend state
                        await this.loadSettings();

                        this.$emitter.emit('add-flash', {
                            type: 'success',
                            message: response.data.message
                        });
                    } catch (error) {
                        console.error('Error saving settings:', error);
                        this.$emitter.emit('add-flash', {
                            type: 'error',
                            message: 'Failed to save settings'
                        });
                    } finally {
                        this.isLoading = false;
                    }
                },
                openQuickView(product) {
                    this.selectedProduct = product;
                    this.showModal = true;
                },
                closeQuickView() {
                    this.showModal = false;
                    this.selectedProduct = null;
                },
                addToCart(product) {
                    this.isAddingToCart = true;
                    
                    // Add to cart logic here
                    alert(`${this.quantity} item(s) added to cart`);
                    
                    // Close the modal immediately
                    this.closeQuickView();
                    
                    // Reset the adding to cart state
                    this.isAddingToCart = false;
                },
                addToWishlist() {
                    if (this.isCustomer) {
                        this.$axios.post(`{{ route('shop.api.customers.account.wishlist.store') }}`, {
                                product_id: this.product.id
                            })
                            .then(response => {
                                this.product.is_wishlist = ! this.product.is_wishlist;

                                this.$emitter.emit('add-flash', { type: 'success', message: response.data.data.message });
                            })
                            .catch(error => {});
                        } else {
                            window.location.href = "{{ route('shop.customer.session.index')}}";
                        }
                },

                addToCompare(productId) {
                    /**
                     * This will handle for customers.
                     */
                    if (this.isCustomer) {
                        this.$axios.post('{{ route("shop.api.compare.store") }}', {
                                'product_id': productId
                            })
                            .then(response => {
                                this.$emitter.emit('add-flash', { type: 'success', message: response.data.data.message });
                            })
                            .catch(error => {
                                if ([400, 422].includes(error.response.status)) {
                                    this.$emitter.emit('add-flash', { type: 'warning', message: error.response.data.data.message });

                                    return;
                                }

                                this.$emitter.emit('add-flash', { type: 'error', message: error.response.data.message});
                            });

                        return;
                    }

                    /**
                     * This will handle for guests.
                     */
                    let items = this.getStorageValue() ?? [];

                    if (items.length) {
                        if (! items.includes(productId)) {
                            items.push(productId);

                            localStorage.setItem('compare_items', JSON.stringify(items));

                            this.$emitter.emit('add-flash', { type: 'success', message: "@lang('shop::app.components.products.card.add-to-compare-success')" });
                        } else {
                            this.$emitter.emit('add-flash', { type: 'warning', message: "@lang('shop::app.components.products.card.already-in-compare')" });
                        }
                    } else {
                        localStorage.setItem('compare_items', JSON.stringify([productId]));

                        this.$emitter.emit('add-flash', { type: 'success', message: "@lang('shop::app.components.products.card.add-to-compare-success')" });

                    }
                },

                getStorageValue(key) {
                    let value = localStorage.getItem('compare_items');

                    if (! value) {
                        return [];
                    }

                    return JSON.parse(value);
                },

                addToCart() {
                    this.isAddingToCart = true;

                    this.$axios.post('{{ route("shop.api.checkout.cart.store") }}', {
                            'quantity': 1,
                            'product_id': this.product.id,
                        })
                        .then(response => {
                            if (response.data.message) {
                                this.$emitter.emit('update-mini-cart', response.data.data );

                                this.$emitter.emit('add-flash', { type: 'success', message: response.data.message });
                            } else {
                                this.$emitter.emit('add-flash', { type: 'warning', message: response.data.data.message });
                            }

                            this.isAddingToCart = false;
                        })
                        .catch(error => {
                            this.$emitter.emit('add-flash', { type: 'error', message: error.response.data.message });

                            if (error.response.data.redirect_uri) {
                                window.location.href = error.response.data.redirect_uri;
                            }
                            
                            this.isAddingToCart = false;
                        });
                },
                watch: {
                settings: {
                    handler(newSettings) {
                        console.log('Settings updated:', newSettings);
                    },
                    deep: true
                }
            }
            },
        });
    </script>
@endpushOnce