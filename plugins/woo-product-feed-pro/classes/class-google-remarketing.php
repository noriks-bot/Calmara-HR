<?php
/**
 * This class sets Google Remarketing functions.
 *
 * @deprecated 13.5.3 Will be removed in the future. Use \AdTribes\PFP\Helpers\Helper::get_wc_page_type(); instead.
 */
class WooSEA_Google_Remarketing {

    public static function woosea_google_remarketing_pagetype() { // phpcs:ignore
        $ecomm_pagetype = 'other'; // set a default.

        if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) ) {
            if ( is_product() ) {
                $ecomm_pagetype = 'product';
            } elseif ( is_cart() ) {
                $ecomm_pagetype = 'cart';
            } elseif ( is_checkout() ) {
                $ecomm_pagetype = 'cart';
            } elseif ( is_product_category() ) {
                $ecomm_pagetype = 'category';
            } elseif ( is_front_page() ) {
                $ecomm_pagetype = 'home';
            } elseif ( is_search() ) {
                $ecomm_pagetype = 'searchresults';
            } else {
                $ecomm_pagetype = 'other';
            }
        }
        return $ecomm_pagetype;
    }
}
