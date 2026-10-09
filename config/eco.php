<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Eco Points Conversion Rate
    |--------------------------------------------------------------------------
    |
    | Rupiah per 1 eco point. Voucher points_cost selalu dihitung
    | server-side: points_cost = ceil(rupiah_value / rupiah_per_point).
    | Client tidak boleh mengirim points_cost agar tidak inflasi.
    | Default 40 mengikuti voucher existing (Rp10rb = 250 poin).
    |
    */

    'rupiah_per_point' => (int) env('ECO_RUPIAH_PER_POINT', 40),
];
