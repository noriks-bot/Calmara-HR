<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Traits\Singleton_Trait;
use AdTribes\PFP\Helpers\Helper;

/**
 * Handles Facebook Pixel client-side tracking.
 *
 * Outputs the Facebook Pixel snippet on the frontend for page views,
 * product views, cart events, category pages, and search results.
 *
 * @since 13.5.8
 */
class Facebook_Pixel extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Facebook Pixel ID.
     *
     * @since 13.5.8
     * @access protected
     *
     * @var string
     */
    protected $pixel_id;

    /**
     * Output the Facebook Pixel snippet on the frontend.
     *
     * @since 13.5.8
     * @access public
     *
     * @param \WC_Product|null $product Optional product object.
     * @return void
     */
    public function add_facebook_pixel( $product = null ) {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        // Pixel ID is shared by both client-side pixel and server-side CAPI.
        $this->pixel_id = get_option( 'adt_facebook_pixel_id' );

        if ( ! is_numeric( $this->pixel_id ) || $this->pixel_id <= 0 ) {
            return;
        }

        $event_id = uniqid( wp_rand(), true );
        $currency = get_woocommerce_currency();
        $event    = $this->resolve_page_event( $product, $event_id, $currency );

        // Client-side pixel (PRO feature, controlled by its own toggle).
        if ( 'yes' === get_option( 'adt_add_facebook_pixel' ) ) {
            $view_content = '';
            if ( $event ) {
                $view_content = $this->build_fbq_call( $event['method'], $event['event_name'], $event['event_data'], $event_id );
            }
            $this->output_pixel_snippet( $event_id, $view_content );
        }

        // Server-side tracking (Elite overrides for CAPI, controlled independently).
        $this->send_server_event( $event, $event_id );
    }

    /**
     * Fire an action after pixel event resolution for server-side tracking.
     *
     * Elite hooks into this to send Facebook Conversion API (CAPI) events.
     *
     * @since 13.5.8
     * @access protected
     *
     * @param array|null $event    Structured event array from resolve_page_event(), or null.
     * @param string     $event_id Unique event ID for deduplication.
     * @return void
     */
    protected function send_server_event( $event, $event_id ) {
        /**
         * Fires after a Facebook pixel event is resolved, allowing server-side
         * tracking (e.g. Conversions API) to send the same event data.
         *
         * @since 13.5.8
         *
         * @param array|null $event    Structured event array, or null for generic pages.
         * @param string     $event_id Unique event ID for deduplication.
         * @param string     $pixel_id The Facebook Pixel ID.
         */
        do_action( 'adt_pfp_after_pixel_event', $event, $event_id, $this->pixel_id );
    }

    /**
     * Detect the current page type and return structured event data.
     *
     * @since 13.5.8
     * @access protected
     *
     * @param \WC_Product|null $product  Optional product object.
     * @param string           $event_id Unique event ID.
     * @param string           $currency Store currency code.
     * @return array|null Event array with keys: method, event_name, event_data. Null if no event.
     */
    protected function resolve_page_event( $product, $event_id, $currency ) {
        $fb_pagetype = Helper::get_wc_page_type();

        if ( 'product' === $fb_pagetype ) {
            if ( ! is_object( $product ) ) {
                $post_id = apply_filters( 'adt_facebook_pixel_post_id', get_the_ID() );
                $product = function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null;
            }

            if ( $product instanceof \WC_Product ) {
                return $this->get_product_page_event( $product, $event_id, $currency );
            }
        } elseif ( 'cart' === $fb_pagetype ) {
            return $this->get_cart_page_event( $event_id, $currency );
        } elseif ( 'category' === $fb_pagetype ) {
            return $this->get_category_page_event( $event_id );
        } elseif ( 'searchresults' === $fb_pagetype ) {
            return $this->get_search_page_event( $event_id );
        }

        return null;
    }

    /**
     * Build ViewContent event data for a product page.
     *
     * @since 13.5.8
     * @access protected
     *
     * @param \WC_Product $product  The current product object.
     * @param string      $event_id Unique event ID.
     * @param string      $currency Store currency code.
     * @return array|null Structured event array, or null.
     */
    protected function get_product_page_event( \WC_Product $product, $event_id, $currency ) {
        if ( empty( $product->get_price() ) ) {
            return null;
        }

        $fb_prodid    = $product->get_id();
        $product_name = str_replace( array( '"', "'" ), '', $product->get_name() );
        $cats         = $this->get_product_categories( $fb_prodid );

        if ( empty( $fb_prodid ) ) {
            return null;
        }

        if ( $product->is_type( 'variable' ) ) {
            return $this->get_variable_product_event( $product, $event_id, $currency, $fb_prodid, $product_name, $cats );
        }

        $fb_price = $this->get_numeric_price( $product->get_price() );

        return array(
            'method'     => 'track',
            'event_name' => 'ViewContent',
            'event_data' => array(
                'content_category' => $cats,
                'content_name'     => $product_name,
                'content_type'     => 'product',
                'content_ids'      => array( (string) $fb_prodid ),
                'value'            => $fb_price,
                'currency'         => $currency,
            ),
            'product_id' => $fb_prodid,
        );
    }

    /**
     * Build ViewContent event data for a variable product page.
     *
     * @since 13.5.8
     * @access protected
     *
     * @param \WC_Product $product      The variable product object.
     * @param string      $event_id     Unique event ID.
     * @param string      $currency     Store currency code.
     * @param int         $fb_prodid    Parent product ID.
     * @param string      $product_name Sanitized product name.
     * @param string      $cats         Comma-separated category names.
     * @return array Structured event array.
     */
    protected function get_variable_product_event( \WC_Product $product, $event_id, $currency, $fb_prodid, $product_name, $cats ) {
        $variation_id = woosea_find_matching_product_variation( $product, $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $nr_get       = count( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( $nr_get > 0 && $variation_id > 0 ) {
            $variable_product = wc_get_product( $variation_id );
            $fb_prodid        = $variation_id;

            if ( is_object( $variable_product ) ) {
                $fb_price = $this->get_numeric_price( $variable_product->get_price() );
            } else {
                $fb_price = $this->get_variable_price_range( $product );
            }

            return array(
                'method'     => 'track',
                'event_name' => 'ViewContent',
                'event_data' => array(
                    'content_category' => $cats,
                    'content_name'     => $product_name,
                    'content_type'     => 'product',
                    'content_ids'      => array( (string) $fb_prodid ),
                    'value'            => $fb_price,
                    'currency'         => $currency,
                ),
                'product_id' => $fb_prodid,
            );
        }

        // Parent variable product — use child variation IDs.
        $woosea_content_ids = get_option( 'adt_facebook_pixel_content_ids', 'variation' );
        if ( 'variation' === $woosea_content_ids ) {
            $content_ids = array_map( 'strval', $product->get_children() );
        } else {
            $content_ids = array( (string) $fb_prodid );
        }

        $fb_price = $this->get_variable_price_range( $product );

        return array(
            'method'     => 'track',
            'event_name' => 'ViewContent',
            'event_data' => array(
                'content_category' => $cats,
                'content_name'     => $product_name,
                'content_type'     => 'product_group',
                'content_ids'      => $content_ids,
                'value'            => $fb_price,
                'currency'         => $currency,
            ),
            'product_id' => $fb_prodid,
        );
    }

    /**
     * Build Purchase, InitiateCheckout or AddToCart event data for cart-related pages.
     *
     * @since 13.5.8
     * @access protected
     *
     * @param string $event_id Unique event ID.
     * @param string $currency Store currency code.
     * @return array|null Structured event array, or null.
     */
    protected function get_cart_page_event( $event_id, $currency ) {
        // Order thank-you page — fire Purchase event.
        if ( isset( $_GET['key'] ) && is_wc_endpoint_url( 'order-received' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $order_string = sanitize_text_field( wp_unslash( $_GET['key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

            if ( empty( $order_string ) ) {
                return null;
            }

            $order_id = wc_get_order_id_by_order_key( $order_string );
            $order    = wc_get_order( $order_id );

            if ( ! $order ) {
                return null;
            }

            $order_items = $order->get_items();
            $contents    = array();
            $content_ids = array();
            $order_real  = $this->get_numeric_price( $order->get_total() );

            foreach ( $order_items as $order_item ) {
                $prod_id      = $order_item->get_product_id();
                $variation_id = $order_item->get_variation_id();
                if ( $variation_id > 0 ) {
                    $prod_id = $variation_id;
                }
                $content_ids[] = (string) $prod_id;
                $contents[]    = array(
                    'id'       => (string) $prod_id,
                    'quantity' => $order_item->get_quantity(),
                );
            }

            return array(
                'method'      => 'track',
                'event_name'  => 'Purchase',
                'event_data'  => array(
                    'currency'     => $currency,
                    'value'        => $order_real,
                    'content_type' => 'product',
                    'content_ids'  => $content_ids,
                    'contents'     => $contents,
                ),
                'content_ids' => $content_ids,
            );
        }

        // Cart / checkout page.
        $cart_items        = WC()->cart->get_cart();
        $cart_total_amount = $this->get_numeric_price( WC()->cart->get_cart_contents_total() );
        $checkoutpage      = wc_get_checkout_url();
        $current_url       = get_permalink( get_the_ID() );
        $content_ids       = array();
        $product_name      = '';

        if ( ! empty( $cart_items ) ) {
            foreach ( $cart_items as $cart_item ) {
                $prod_id      = $cart_item['product_id'];
                $product_name = $cart_item['data']->get_name();
                if ( $cart_item['variation_id'] > 0 ) {
                    $prod_id = $cart_item['variation_id'];
                }
                $content_ids[] = (string) $prod_id;
            }
        }

        $event_name = ( $checkoutpage === $current_url ) ? 'InitiateCheckout' : 'AddToCart';

        return array(
            'method'       => 'track',
            'event_name'   => $event_name,
            'event_data'   => array(
                'currency'     => $currency,
                'value'        => $cart_total_amount,
                'content_type' => 'product',
                'content_ids'  => $content_ids,
            ),
            'product_name' => $product_name,
        );
    }

    /**
     * Build ViewCategory event data for a product category page.
     *
     * @since 13.5.8
     * @access protected
     *
     * @param string $event_id Unique event ID.
     * @return array|null Structured event array, or null.
     */
    protected function get_category_page_event( $event_id ) {
        $term = get_queried_object();

        if ( ! $term instanceof \WP_Term ) {
            return null;
        }

        $fb_prodid     = $this->get_query_product_ids();
        $category_name = $term->name;
        $category_path = $this->get_term_parents( $term->term_id, 'product_cat' );

        return array(
            'method'     => 'trackCustom',
            'event_name' => 'ViewCategory',
            'event_data' => array(
                'content_category' => $category_path,
                'content_name'     => $category_name,
                'content_type'     => 'product',
                'content_ids'      => $fb_prodid,
            ),
        );
    }

    /**
     * Build Search event data for a search results page.
     *
     * @since 13.5.8
     * @access protected
     *
     * @param string $event_id Unique event ID.
     * @return array Structured event array.
     */
    protected function get_search_page_event( $event_id ) {
        $search_string = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $fb_prodid     = $this->get_query_product_ids();

        return array(
            'method'     => 'track',
            'event_name' => 'Search',
            'event_data' => array(
                'search_string' => $search_string,
                'content_type'  => 'product',
                'content_ids'   => $fb_prodid,
            ),
        );
    }

    /**
     * Output the Facebook Pixel HTML/JS snippet.
     *
     * @since 13.5.8
     * @access protected
     *
     * @param string $event_id     Unique event ID for deduplication.
     * @param string $view_content The fbq() event JS call to inline after PageView.
     * @return void
     */
    protected function output_pixel_snippet( $event_id, $view_content ) {
        ?>
        <!-- Facebook Pixel Code - Product Feed Pro for WooCommerce by AdTribes.io -->
        <!------------------------------------------------------------------------------
        Make sure the g:id value in your Facebook catalogue feed matched with
        the content of the content_ids parameter in the Facebook Pixel Code
        ------------------------------------------------------------------------------->
        <script type="text/javascript">
            !function(f,b,e,v,n,t,s)
            {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
            n.callMethod.apply(n,arguments):n.queue.push(arguments)};
            if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
            n.queue=[];t=b.createElement(e);t.async=!0;
            t.src=v;s=b.getElementsByTagName(e)[0];
            s.parentNode.insertBefore(t,s)}(window, document,'script',
            'https://connect.facebook.net/en_US/fbevents.js');

            fbq("init", "<?php echo esc_attr( $this->pixel_id ); ?>");
            fbq("track", "PageView");
            <?php
                if ( ! empty( $view_content ) ) {
                    echo $view_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                }
            ?>
        </script>
        <noscript>
            <img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id=<?php echo esc_attr( $this->pixel_id ); ?>&ev=PageView&noscript=1&eid=<?php echo esc_attr( $event_id ); ?>"/>
        </noscript>
        <!-- End Facebook Pixel Code -->
        <?php
    }

    /**
     * Build an fbq() JS call string.
     *
     * @since 13.5.8
     * @access protected
     *
     * @param string $method     The fbq method ('track' or 'trackCustom').
     * @param string $event_name The Facebook event name.
     * @param array  $event_data The event data payload.
     * @param string $event_id   Unique event ID for deduplication.
     * @return string The fbq() JS call.
     */
    protected function build_fbq_call( $method, $event_name, array $event_data, $event_id ) {
        return 'fbq("' . $method . '","' . $event_name . '",' . wp_json_encode( $event_data ) . ',{"eventID":' . wp_json_encode( $event_id ) . '});';
    }

    /**
     * Convert a price value to a locale-safe float.
     *
     * @since 13.5.8
     * @access protected
     *
     * @param mixed $price Raw price value.
     * @return float
     */
    protected function get_numeric_price( $price ) {
        return (float) wc_format_decimal( $price );
    }

    /**
     * Collect product IDs (including variation children) from the current WP_Query.
     *
     * @since 13.5.8
     * @access protected
     *
     * @return array Array of string product/variation IDs.
     */
    protected function get_query_product_ids() {
        global $wp_query;

        $ids       = wp_list_pluck( $wp_query->posts, 'ID' );
        $fb_prodid = array();

        foreach ( $ids as $id ) {
            $_product = wc_get_product( $id );
            if ( ! $_product ) {
                continue;
            }

            if ( $_product->is_type( 'simple' ) ) {
                $fb_prodid[] = (string) $id;
            } else {
                foreach ( $_product->get_children() as $child_id ) {
                    $fb_prodid[] = (string) $child_id;
                }
            }
        }

        return $fb_prodid;
    }

    /**
     * Get a comma-separated string of category names for a product.
     *
     * @since 13.5.8
     * @access protected
     *
     * @param int $product_id The product post ID.
     * @return string
     */
    protected function get_product_categories( $product_id ) {
        $cats     = '';
        $all_cats = get_the_terms( $product_id, 'product_cat' );

        if ( ! empty( $all_cats ) && ! is_wp_error( $all_cats ) ) {
            foreach ( $all_cats as $category ) {
                $cats .= $category->name . ',';
            }
        }

        $cats = rtrim( $cats, ',' );
        $cats = str_replace( array( '&amp;', '"', "'" ), array( '&', '', '' ), $cats );

        return $cats;
    }

    /**
     * Get the lowest price for a variable product.
     *
     * @since 13.5.8
     * @access protected
     *
     * @param \WC_Product $product The variable product object.
     * @return float
     */
    protected function get_variable_price_range( \WC_Product $product ) {
        $prices = $product->get_variation_prices();

        if ( empty( $prices['price'] ) ) {
            return 0.0;
        }

        $lowest = reset( $prices['price'] );

        return (float) $lowest;
    }

    /**
     * Build a breadcrumb-style category path string for a given term.
     *
     * @since 13.5.8
     * @access protected
     *
     * @param int    $term_id  The term ID.
     * @param string $taxonomy The taxonomy name.
     * @param array  $visited  Term IDs already visited.
     * @return string|\WP_Error
     */
    protected function get_term_parents( $term_id, $taxonomy, $visited = array() ) {
        $chain  = empty( $visited ) ? 'Home' : '';
        $parent = get_term( $term_id, $taxonomy );

        if ( is_wp_error( $parent ) ) {
            return $parent;
        }

        if ( ! $parent ) {
            return $chain;
        }

        $name = $parent->name;

        if ( $parent->parent && $parent->parent !== $parent->term_id && ! in_array( $parent->parent, $visited, true ) ) {
            $visited[] = $parent->parent;
            $chain    .= $this->get_term_parents( $parent->parent, $taxonomy, $visited );
        }

        $chain .= ' > ' . $name;

        return $chain;
    }

    /**
     * Run the class.
     *
     * @since 13.5.8
     */
    public function run() {
        add_action( 'wp_footer', array( $this, 'add_facebook_pixel' ) );
    }
}
