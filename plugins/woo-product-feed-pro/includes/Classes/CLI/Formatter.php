<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes\CLI
 */

namespace AdTribes\PFP\Classes\CLI;

use AdTribes\PFP\Classes\Product_Feed_Attributes;
use AdTribes\PFP\Factories\Product_Feed;
use AdTribes\PFP\Helpers\Product_Feed_Helper;

/**
 * Shared helpers for CLI commands.
 *
 * @since 13.5.4
 */
class Formatter {

    /**
     * Supported file formats. Mirrors templates/edit-feed/tabs/general-tab.php:199.
     *
     * @since 13.5.4
     *
     * @return array
     */
    public static function supported_file_formats() {
        return array( 'xml', 'csv', 'txt', 'tsv', 'jsonl', 'jsonl.gz', 'csv.gz' );
    }

    /**
     * Flatten a Product_Feed into a row suitable for table/json/csv output.
     *
     * @since 13.5.4
     *
     * @param Product_Feed $feed The feed.
     * @return array
     */
    public static function feed_to_row( $feed ) {
        $channel = $feed->channel;

        return array(
            'id'               => (int) $feed->id,
            'title'            => (string) $feed->title,
            'post_status'      => (string) $feed->post_status,
            'status'           => (string) $feed->status,
            'channel'          => is_array( $channel ) && isset( $channel['name'] ) ? $channel['name'] : '',
            'channel_hash'     => (string) $feed->channel_hash,
            'country'          => (string) $feed->country,
            'file_format'      => (string) $feed->file_format,
            'file_url'         => (string) $feed->get_file_url(),
            'products_count'   => (int) $feed->products_count,
            'refresh_interval' => (string) $feed->refresh_interval,
            'last_updated'     => (string) $feed->last_updated,
        );
    }

    /**
     * Flatten a Product_Feed into the full editable property set. Used by `get` and `update --from-file`.
     *
     * @since 13.5.4
     *
     * @param Product_Feed $feed The feed.
     * @return array
     */
    public static function feed_to_full( $feed ) {
        $row                     = self::feed_to_row( $feed );
        $row['delimiter']        = (string) $feed->delimiter;
        $row['file_name']        = (string) $feed->file_name;
        $row['file_path']        = (string) $feed->get_file_path();
        $row['batch_size']       = (int) $feed->batch_size;
        $row['executed_from']    = (string) $feed->executed_from;
        $row['utm_enabled']      = (bool) $feed->utm_enabled;
        $row['utm_source']       = (string) $feed->utm_source;
        $row['utm_medium']       = (string) $feed->utm_medium;
        $row['utm_campaign']     = (string) $feed->utm_campaign;
        $row['attributes']       = (array) $feed->attributes;
        $row['mappings']         = (array) $feed->mappings;
        $row['rules']            = (array) $feed->rules;
        $row['filters']          = (array) $feed->filters;
        $row['feed_filters']     = (array) $feed->feed_filters;
        $row['feed_rules']       = (array) $feed->feed_rules;
        $row['history_products'] = (array) $feed->history_products;

        return $row;
    }

    /**
     * Resolve a channel identifier (hash or name) to the channel data array.
     *
     * Names are matched case-insensitively. Hash matches take precedence.
     *
     * @since 13.5.4
     *
     * @param string $identifier Channel hash or name.
     * @param string $country    Country code for narrowing the search (optional).
     * @return array|null
     */
    public static function resolve_channel( $identifier, $country = '' ) {
        $identifier = trim( (string) $identifier );
        if ( '' === $identifier ) {
            return null;
        }

        // Hash lookup first — cheap and unambiguous.
        $by_hash = Product_Feed_Helper::get_channel_from_legacy_channel_hash( $identifier );
        if ( $by_hash ) {
            return $by_hash;
        }

        $needle   = strtolower( $identifier );
        $channels = Product_Feed_Attributes::get_channels( $country );

        foreach ( $channels as $channel ) {
            if ( isset( $channel['name'] ) && strtolower( $channel['name'] ) === $needle ) {
                return $channel;
            }
        }

        // Fall back to a scan across every country if no country was given.
        if ( '' === $country ) {
            $all = include ADT_PFP_PLUGIN_DIR_PATH . 'includes/I18n/legacy_channel_statics.php';
            foreach ( $all as $group ) {
                foreach ( $group as $channel ) {
                    if ( isset( $channel['name'] ) && strtolower( $channel['name'] ) === $needle ) {
                        return $channel;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Read and JSON-decode a file. Errors out via WP_CLI::error on failure.
     *
     * @since 13.5.4
     *
     * @param string $path File path.
     * @return array
     */
    public static function read_json_file( $path ) {
        if ( ! is_readable( $path ) ) {
            \WP_CLI::error( sprintf( 'File not readable: %s', $path ) );
        }

        $raw     = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $decoded = json_decode( $raw, true );

        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
            \WP_CLI::error( sprintf( 'Invalid JSON in %s: %s', $path, json_last_error_msg() ) );
        }

        return $decoded;
    }
}
