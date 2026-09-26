<?php

use Webkul\Customer\Models\CustomerGroup;

/*
| Helpers RT-04B1b (solo lpz_saas_testing). Telefonos ficticios y unicos por llamada.
*/

if (! function_exists('rt04b1b_movil')) {
    function rt04b1b_movil(): string
    {
        return '3'.str_pad((string) random_int(0, 999_999_999), 9, '0', STR_PAD_LEFT);
    }
}

if (! function_exists('rt04b1b_grupo')) {
    function rt04b1b_grupo(): CustomerGroup
    {
        return CustomerGroup::factory()->create([
            'code' => 'rt04b1b_'.uniqid(), 'name' => 'RT04B1b', 'is_user_defined' => 1,
        ]);
    }
}
