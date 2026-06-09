<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes\Admin_Pages
 */

namespace AdTribes\PFP\Classes\Admin_Pages;

use AdTribes\PFP\Abstracts\Admin_Page;
use AdTribes\PFP\Helpers\Helper;
use AdTribes\PFP\Traits\Singleton_Trait;

/**
 * Manage_License_Page class.
 *
 * Provides an extensible license management page that Elite and addons
 * can register their license tabs into via filters and actions.
 *
 * @since 13.4.4
 */
class License_Page extends Admin_Page {

    use Singleton_Trait;

    const MENU_SLUG = 'adt_license_settings_page';

    /**
     * Holds the class instance object
     *
     * @since 13.4.4
     * @access protected
     *
     * @var Singleton_Trait $instance object
     */
    protected static $instance;

    /**
     * Initialize the class.
     *
     * @since 13.4.4
     */
    public function init() {
        $this->parent_slug = 'woo-product-feed';
        $this->page_title  = __( 'License', 'woo-product-feed-pro' );
        $this->menu_title  = __( 'License', 'woo-product-feed-pro' );
        $this->capability  = is_multisite() ? 'manage_sites' : apply_filters( 'adt_pfp_admin_capability', 'manage_options' );
        $this->menu_slug   = self::MENU_SLUG;
        $this->template    = 'license-page.php';
        $this->position    = 40;
    }

    /**
     * Get the admin menu priority.
     *
     * @since 13.4.4
     * @return int
     */
    protected function get_priority() {
        return 40;
    }

    /**
     * Get the license page tabs.
     *
     * Builds a filterable array of tabs. PRO registers its own tab,
     * and Elite/addons can add theirs via the `adt_license_page_tabs` filter.
     *
     * @since 13.5.0
     * @return array
     */
    public function get_license_tabs() {
        $base_url = is_multisite()
            ? network_admin_url( 'admin.php?page=' . self::MENU_SLUG )
            : admin_url( 'admin.php?page=' . self::MENU_SLUG );

        /**
         * Filter the license page tabs.
         *
         * Allows Elite and addons to register their own license tabs.
         * PRO starts with an empty array — the "Product Feed" upsell tab
         * is only added as a fallback when no plugins register tabs.
         *
         * @since 13.5.0
         *
         * @param array  $tabs     The registered tabs array.
         * @param string $base_url The base URL for building tab URLs.
         */
        $tabs = apply_filters( 'adt_license_page_tabs', array(), $base_url );

        // Fallback: show upgrade CTA tab when no premium plugins registered tabs.
        if ( empty( $tabs ) ) {
            $tabs['product-feed'] = array(
                'id'       => 'product-feed',
                'title'    => __( 'Product Feed', 'woo-product-feed-pro' ),
                'url'      => add_query_arg( 'tab', 'product-feed', $base_url ),
                'priority' => 10,
            );
        }

        // Sort tabs by priority (lower number = further left).
        uasort(
            $tabs,
            function ( $a, $b ) {
                $pa = isset( $a['priority'] ) ? (int) $a['priority'] : 10;
                $pb = isset( $b['priority'] ) ? (int) $b['priority'] : 10;
                return $pa - $pb;
            }
        );

        return $tabs;
    }

    /**
     * Load the admin page with tabs data.
     *
     * @since 13.5.0
     */
    public function load_admin_page() {
        $this->template_args = array(
            'tabs' => $this->get_license_tabs(),
        );

        parent::load_admin_page();
    }

    /**
     * Enqueue scripts and styles for the license page.
     *
     * @since 13.5.0
     */
    protected function enqueue_scripts() {
        wp_enqueue_style(
            'adt-license-settings-css',
            ADT_PFP_CSS_URL . 'license-settings.css',
            array(),
            WOOCOMMERCESEA_PLUGIN_VERSION
        );

        wp_enqueue_script(
            'adt-license-settings-js',
            ADT_PFP_JS_URL . 'license-settings.js',
            array( 'jquery' ),
            WOOCOMMERCESEA_PLUGIN_VERSION,
            true
        );

        wp_localize_script(
            'adt-license-settings-js',
            'adt_pfp_license_args',
            array(
                'i18n' => array(
                    'inactive' => __( 'Inactive', 'woo-product-feed-pro' ),
                    'active'   => __( 'Active', 'woo-product-feed-pro' ),
                    'expired'  => __( 'Expired', 'woo-product-feed-pro' ),
                ),
            )
        );

        /**
         * Fires when the license page enqueues scripts.
         *
         * Allows Elite and addons to enqueue their own scripts/styles.
         *
         * @since 13.5.0
         */
        do_action( 'adt_license_page_enqueue_scripts' );
    }

    /**
     * Render the PRO "Product Feed" tab content.
     *
     * @since 13.5.0
     *
     * @param string $current_tab The currently active tab ID.
     * @param array  $_tabs       All registered tabs (unused, required by action signature).
     */
    public function render_pro_tab_content( $current_tab, $_tabs ) {
        if ( 'product-feed' !== $current_tab ) {
            return;
        }

        Helper::locate_admin_template( 'license-product-feed-tab.php', true, true );
    }

    /**
     * Run the license page hooks.
     *
     * @since 13.4.4
     */
    public function run() {
        // Register menu for single-site.
        add_action( 'admin_menu', array( $this, 'admin_menu' ), $this->get_priority() );

        // Register menu for multisite network admin.
        if ( is_multisite() && is_main_site() ) {
            add_action( 'network_admin_menu', array( $this, 'admin_menu' ), $this->get_priority() );
        }

        add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );

        // Hook PRO's own tab content renderer.
        add_action( 'adt_license_page_content', array( $this, 'render_pro_tab_content' ), 10, 2 );
    }
}
