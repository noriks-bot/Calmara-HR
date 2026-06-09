<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes\CLI
 */

namespace AdTribes\PFP\Classes\CLI;

use AdTribes\PFP\Classes\Product_Feed_Attributes;
use WP_CLI\Utils;

/**
 * List channels known to the plugin.
 *
 * @since 13.5.4
 */
class Channel_Command extends \WP_CLI_Command {

    /**
     * List channels, optionally filtered by country.
     *
     * ## OPTIONS
     *
     * [--country=<country>]
     * : Two-letter country code (e.g. AU, US). Empty means "All countries" + "Custom Feed" only.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - yaml
     * ---
     *
     * [--fields=<fields>]
     * : Comma-separated list of fields to show.
     * ---
     * default: name,channel_hash,taxonomy,type
     * ---
     *
     * ## EXAMPLES
     *
     *     wp adt-feed channel list
     *     wp adt-feed channel list --country=AU
     *     wp adt-feed channel list --format=json --fields=name,channel_hash
     *
     * @subcommand list
     * @when after_wp_load
     *
     * @param array $args Positional args.
     * @param array $assoc_args Associative args.
     */
    public function list_channels( $args, $assoc_args ) {
        $country  = isset( $assoc_args['country'] ) ? (string) $assoc_args['country'] : '';
        $channels = Product_Feed_Attributes::get_channels( $country );

        $rows = array();
        foreach ( $channels as $channel ) {
            $rows[] = array(
                'name'         => isset( $channel['name'] ) ? (string) $channel['name'] : '',
                'channel_hash' => isset( $channel['channel_hash'] ) ? (string) $channel['channel_hash'] : '',
                'fields'       => isset( $channel['fields'] ) ? (string) $channel['fields'] : '',
                'taxonomy'     => isset( $channel['taxonomy'] ) ? (string) $channel['taxonomy'] : '',
                'type'         => isset( $channel['type'] ) ? (string) $channel['type'] : '',
                'utm_source'   => isset( $channel['utm_source'] ) ? (string) $channel['utm_source'] : '',
            );
        }

        $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
        $fields = isset( $assoc_args['fields'] )
            ? array_map( 'trim', explode( ',', $assoc_args['fields'] ) )
            : array( 'name', 'channel_hash', 'taxonomy', 'type' );

        Utils\format_items( $format, $rows, $fields );
    }
}
