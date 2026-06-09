<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes\CLI
 */

namespace AdTribes\PFP\Classes\CLI;

use WP_CLI\Utils;

/**
 * List supported feed file formats.
 *
 * @since 13.5.4
 */
class Format_Command extends \WP_CLI_Command {

    /**
     * List supported feed file formats.
     *
     * ## OPTIONS
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
     * ## EXAMPLES
     *
     *     wp adt-feed format list
     *
     * @subcommand list
     * @when after_wp_load
     *
     * @param array $args Positional args.
     * @param array $assoc_args Associative args.
     */
    public function list_formats( $args, $assoc_args ) {
        $rows = array_map(
            function ( $f ) {
                return array( 'file_format' => $f );
            },
            Formatter::supported_file_formats()
        );

        $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
        Utils\format_items( $format, $rows, array( 'file_format' ) );
    }
}
