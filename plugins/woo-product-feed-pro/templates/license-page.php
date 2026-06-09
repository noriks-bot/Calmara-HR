<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use AdTribes\PFP\Helpers\Helper;

$current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

// Default to first tab if no tab specified.
if ( empty( $current_tab ) && ! empty( $tabs ) ) {
    $first_tab   = reset( $tabs );
    $current_tab = $first_tab['id'];
}
?>
<div class="wrap adt-tw-wrapper adt-license-settings">
    <div class="adt-container adt-license-settings-container lg:adt-tw-px-8 sm:adt-tw-py-4 adt-tw-py-0">
        <?php Helper::locate_admin_template( 'header.php', true ); ?>
        <h1 class="adt-tw-text-[32px] adt-tw-font-semibold adt-tw-text-gray-800 adt-tw-mb-2">
            <?php esc_html_e( 'Licenses', 'woo-product-feed-pro' ); ?>
            <p class="adt-tw-text-base adt-tw-mt-2 adt-tw-font-normal">
                <?php esc_html_e( 'Enter your license keys below to enjoy full access, plugin updates, and support.', 'woo-product-feed-pro' ); ?>
            </p>
        </h1>

        <div class="adt-license-notification">
            <div class="adt-notice">
                <p class="message"></p>
                <button type="button" class="notice-dismiss">
                    <span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.', 'woo-product-feed-pro' ); ?></span>
                </button>
            </div>
        </div>

        <div class="postbox license-box">
            <ul class="license-nav-tabs">
                <?php foreach ( $tabs as $license_tab ) : ?>
                    <li class="<?php echo ( $license_tab['id'] === $current_tab ) ? 'active' : ''; ?>">
                        <a href="<?php echo esc_url( $license_tab['url'] ); ?>" class="tab-link">
                            <?php echo esc_html( $license_tab['title'] ); ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="tab">
                <?php
                /**
                 * Render license page tab content.
                 *
                 * Each plugin/addon hooks into this action and renders its content
                 * only when $current_tab matches its registered tab ID.
                 *
                 * @since 13.5.0
                 *
                 * @param string $current_tab The current active tab ID.
                 * @param array  $tabs        All registered tabs.
                 */
                do_action( 'adt_license_page_content', $current_tab, $tabs );
                ?>
            </div>
        </div>
    </div>
</div>
