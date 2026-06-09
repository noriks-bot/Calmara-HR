<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes\CLI
 */

namespace AdTribes\PFP\Classes\CLI;

use AdTribes\PFP\Factories\Product_Feed;
use AdTribes\PFP\Helpers\Product_Feed_Helper;
use WP_CLI\Utils;

/**
 * Manage product feeds from the command line.
 *
 * Thin wrappers around AdTribes\PFP\Factories\Product_Feed. Permission and nonce
 * checks are skipped intentionally — WP-CLI is implicitly privileged.
 *
 * @since 13.5.4
 */
class Feed_Command extends \WP_CLI_Command {

    /**
     * List product feeds.
     *
     * ## OPTIONS
     *
     * [--status=<status>]
     * : Filter by post status (publish, draft, any). Default: any.
     *
     * [--channel=<channel>]
     * : Filter by channel hash or channel name (case-insensitive).
     *
     * [--per-page=<n>]
     * : Maximum number of feeds to return. Default: 100.
     *
     * [--page=<n>]
     * : Page number for pagination. Default: 1.
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
     *   - ids
     *   - count
     * ---
     *
     * [--fields=<fields>]
     * : Comma-separated list of row fields to display.
     * ---
     * default: id,title,post_status,status,channel,file_format,products_count,last_updated
     * ---
     *
     * ## EXAMPLES
     *
     *     wp adt-feed list
     *     wp adt-feed list --status=publish --format=json
     *     wp adt-feed list --channel="OpenAI Product Feed"
     *     wp adt-feed list --format=ids
     *
     * @subcommand list
     * @when after_wp_load
     *
     * @param array $args Positional args.
     * @param array $assoc_args Associative args.
     */
    public function list_feeds( $args, $assoc_args ) {
        $status   = isset( $assoc_args['status'] ) ? (string) $assoc_args['status'] : 'any';
        $per_page = isset( $assoc_args['per-page'] ) ? absint( $assoc_args['per-page'] ) : 100;
        $page     = isset( $assoc_args['page'] ) ? max( 1, absint( $assoc_args['page'] ) ) : 1;

        $query_args = array(
            'post_type'      => Product_Feed::POST_TYPE,
            'post_status'    => $status,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'ID',
            'order'          => 'DESC',
            'fields'         => 'ids',
        );

        if ( ! empty( $assoc_args['channel'] ) ) {
            $channel = Formatter::resolve_channel( (string) $assoc_args['channel'] );
            if ( ! $channel ) {
                \WP_CLI::error( sprintf( 'Unknown channel: %s', $assoc_args['channel'] ) );
            }
            $query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                array(
                    'key'   => 'adt_channel_hash',
                    'value' => $channel['channel_hash'],
                ),
            );
        }

        $ids    = get_posts( $query_args );
        $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

        if ( 'ids' === $format ) {
            \WP_CLI::log( implode( ' ', $ids ) );
            return;
        }

        if ( 'count' === $format ) {
            \WP_CLI::log( (string) count( $ids ) );
            return;
        }

        $rows = array();
        foreach ( $ids as $id ) {
            $feed = Product_Feed_Helper::get_product_feed( (int) $id );
            if ( $feed ) {
                $rows[] = Formatter::feed_to_row( $feed );
            }
        }

        $fields = isset( $assoc_args['fields'] )
            ? array_map( 'trim', explode( ',', $assoc_args['fields'] ) )
            : array( 'id', 'title', 'post_status', 'status', 'channel', 'file_format', 'products_count', 'last_updated' );

        Utils\format_items( $format, $rows, $fields );
    }

    /**
     * Show a single feed's properties.
     *
     * ## OPTIONS
     *
     * <id>
     * : Feed ID or legacy project hash.
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: json
     * options:
     *   - json
     *   - yaml
     *   - table
     * ---
     *
     * ## EXAMPLES
     *
     *     wp adt-feed get 4945
     *     wp adt-feed get 4945 --format=yaml
     *
     * @when after_wp_load
     *
     * @param array $args Positional args.
     * @param array $assoc_args Associative args.
     */
    public function get( $args, $assoc_args ) {
        $feed = $this->must_get_feed( $args[0] );
        $data = Formatter::feed_to_full( $feed );

        $format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'json';

        if ( 'json' === $format ) {
            \WP_CLI::log( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
            return;
        }

        if ( 'yaml' === $format ) {
            $yaml = function_exists( 'yaml_emit' ) ? yaml_emit( $data ) : wp_json_encode( $data, JSON_PRETTY_PRINT );
            \WP_CLI::log( $yaml );
            return;
        }

        // Table: flatten nested arrays for readability.
        $flat = array();
        foreach ( $data as $k => $v ) {
            $flat[] = array(
                'field' => $k,
                'value' => is_scalar( $v ) ? (string) $v : wp_json_encode( $v ),
            );
        }
        Utils\format_items( 'table', $flat, array( 'field', 'value' ) );
    }

    /**
     * Create a product feed.
     *
     * Minimum required properties are title, channel, country, and file-format.
     * For complex configurations (attribute mappings, rules, filters) pass a JSON
     * file with --from-file that contains a dict of Product_Feed properties.
     *
     * ## OPTIONS
     *
     * [--title=<title>]
     * : Feed title.
     *
     * [--channel=<channel>]
     * : Channel hash or name (case-insensitive).
     *
     * [--country=<country>]
     * : Two-letter country code (e.g. AU, US).
     *
     * [--file-format=<format>]
     * : File format: xml, csv, txt, tsv, jsonl, jsonl.gz, csv.gz.
     *
     * [--refresh-interval=<interval>]
     * : Refresh interval: empty, hourly, twicedaily, daily.
     *
     * [--post-status=<status>]
     * : Initial post status. Default: draft.
     * ---
     * default: draft
     * options:
     *   - publish
     *   - draft
     * ---
     *
     * [--from-file=<path>]
     * : Path to a JSON file whose keys map to Product_Feed properties. Flag values override file values.
     *
     * [--porcelain]
     * : Output only the new feed ID.
     *
     * ## EXAMPLES
     *
     *     wp adt-feed create --title="My Feed" --channel="Google Shopping" --country=AU --file-format=xml
     *     wp adt-feed create --from-file=./feed.json --post-status=publish
     *
     * @when after_wp_load
     *
     * @param array $args Positional args.
     * @param array $assoc_args Associative args.
     */
    public function create( $args, $assoc_args ) {
        $props = array();

        if ( ! empty( $assoc_args['from-file'] ) ) {
            $props = Formatter::read_json_file( (string) $assoc_args['from-file'] );
        }

        $flag_props = array(
            'title'            => isset( $assoc_args['title'] ) ? (string) $assoc_args['title'] : null,
            'country'          => isset( $assoc_args['country'] ) ? (string) $assoc_args['country'] : null,
            'file_format'      => isset( $assoc_args['file-format'] ) ? (string) $assoc_args['file-format'] : null,
            'refresh_interval' => isset( $assoc_args['refresh-interval'] ) ? (string) $assoc_args['refresh-interval'] : null,
            'post_status'      => isset( $assoc_args['post-status'] ) ? (string) $assoc_args['post-status'] : null,
        );

        foreach ( $flag_props as $key => $value ) {
            if ( null !== $value ) {
                $props[ $key ] = $value;
            }
        }

        if ( ! empty( $assoc_args['channel'] ) ) {
            $channel = Formatter::resolve_channel( (string) $assoc_args['channel'], $props['country'] ?? '' );
            if ( ! $channel ) {
                \WP_CLI::error( sprintf( 'Unknown channel: %s', $assoc_args['channel'] ) );
            }
            $props['channel_hash'] = $channel['channel_hash'];
            if ( empty( $props['utm_source'] ) && ! empty( $channel['utm_source'] ) ) {
                $props['utm_source'] = $channel['utm_source'];
            }
        }

        $this->validate_create_props( $props );

        $feed = new Product_Feed();

        // Title / post_status are not in $data, they are top-level setters — set_props handles both.
        $title       = $props['title'] ?? '';
        $post_status = $props['post_status'] ?? 'draft';
        unset( $props['title'], $props['post_status'] );

        // File name mirrors clone handler logic.
        if ( empty( $props['file_name'] ) ) {
            $props['file_name']           = Product_Feed_Helper::generate_legacy_project_hash();
            $props['legacy_project_hash'] = $props['file_name'];
        }

        if ( empty( $props['status'] ) ) {
            $props['status'] = 'not run yet';
        }

        try {
            $feed->title       = $title;
            $feed->post_status = $post_status;
            $feed->set_props( $props );
            $feed->save();

            if ( 'publish' === $post_status ) {
                $feed->register_action();
            }
        } catch ( \Exception $e ) {
            \WP_CLI::error( $e->getMessage() );
        }

        if ( ! empty( $assoc_args['porcelain'] ) ) {
            \WP_CLI::log( (string) $feed->id );
            return;
        }

        \WP_CLI::success( sprintf( 'Created feed %d: %s', $feed->id, $feed->title ) );
    }

    /**
     * Update a product feed.
     *
     * ## OPTIONS
     *
     * <id>
     * : Feed ID or legacy project hash.
     *
     * [--title=<title>]
     * : Feed title.
     *
     * [--channel=<channel>]
     * : Channel hash or name.
     *
     * [--country=<country>]
     * : Country code.
     *
     * [--file-format=<format>]
     * : File format.
     *
     * [--refresh-interval=<interval>]
     * : Refresh interval.
     *
     * [--post-status=<status>]
     * : publish|draft.
     *
     * [--from-file=<path>]
     * : JSON file with Product_Feed properties.
     *
     * ## EXAMPLES
     *
     *     wp adt-feed update 4945 --title="Renamed"
     *     wp adt-feed update 4945 --refresh-interval=hourly
     *     wp adt-feed update 4945 --from-file=./partial.json
     *
     * @when after_wp_load
     *
     * @param array $args Positional args.
     * @param array $assoc_args Associative args.
     */
    public function update( $args, $assoc_args ) {
        $feed = $this->must_get_feed( $args[0] );

        $props = array();
        if ( ! empty( $assoc_args['from-file'] ) ) {
            $props = Formatter::read_json_file( (string) $assoc_args['from-file'] );
        }

        $flag_map = array(
            'title'            => 'title',
            'country'          => 'country',
            'file-format'      => 'file_format',
            'refresh-interval' => 'refresh_interval',
            'post-status'      => 'post_status',
        );
        foreach ( $flag_map as $flag => $prop ) {
            if ( isset( $assoc_args[ $flag ] ) ) {
                $props[ $prop ] = (string) $assoc_args[ $flag ];
            }
        }

        if ( ! empty( $assoc_args['channel'] ) ) {
            $country = $props['country'] ?? $feed->country;
            $channel = Formatter::resolve_channel( (string) $assoc_args['channel'], $country );
            if ( ! $channel ) {
                \WP_CLI::error( sprintf( 'Unknown channel: %s', $assoc_args['channel'] ) );
            }
            $props['channel_hash'] = $channel['channel_hash'];
        }

        if ( empty( $props ) ) {
            \WP_CLI::error( 'Nothing to update. Pass at least one flag or --from-file.' );
        }

        $reschedule = array_key_exists( 'refresh_interval', $props ) || array_key_exists( 'post_status', $props );

        try {
            if ( isset( $props['title'] ) ) {
                $feed->title = (string) $props['title'];
                unset( $props['title'] );
            }
            if ( isset( $props['post_status'] ) ) {
                $feed->post_status = (string) $props['post_status'];
                unset( $props['post_status'] );
            }

            $feed->set_props( $props );
            $feed->save();

            if ( $reschedule ) {
                if ( 'publish' === $feed->post_status ) {
                    $feed->register_action();
                } else {
                    $feed->unregister_action();
                }
            }
        } catch ( \Exception $e ) {
            \WP_CLI::error( $e->getMessage() );
        }

        \WP_CLI::success( sprintf( 'Updated feed %d.', $feed->id ) );
    }

    /**
     * Delete one or more product feeds.
     *
     * ## OPTIONS
     *
     * <id>...
     * : One or more feed IDs.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp adt-feed delete 4945
     *     wp adt-feed delete 4945 4955 --yes
     *
     * @when after_wp_load
     *
     * @param array $args Positional args.
     * @param array $assoc_args Associative args.
     */
    public function delete( $args, $assoc_args ) {
        Utils\get_flag_value( $assoc_args, 'yes', false ) || \WP_CLI::confirm(
            sprintf( 'Delete %d feed(s)?', count( $args ) ),
            $assoc_args
        );

        foreach ( $args as $id ) {
            $feed = $this->must_get_feed( $id );

            do_action( 'adt_before_delete_product_feed', $feed );
            $feed->delete();
            do_action( 'adt_after_delete_product_feed', $feed );

            \WP_CLI::log( sprintf( 'Deleted feed %d.', $feed->id ) );
        }

        \WP_CLI::success( sprintf( 'Deleted %d feed(s).', count( $args ) ) );
    }

    /**
     * Duplicate a product feed.
     *
     * Mirrors ajax_clone_product_feed in Product_Feed_Admin.php.
     *
     * ## OPTIONS
     *
     * <id>
     * : Source feed ID or project hash.
     *
     * [--porcelain]
     * : Output only the new feed ID.
     *
     * ## EXAMPLES
     *
     *     wp adt-feed duplicate 4945
     *
     * @when after_wp_load
     *
     * @param array $args Positional args.
     * @param array $assoc_args Associative args.
     */
    public function duplicate( $args, $assoc_args ) {
        $original = $this->must_get_feed( $args[0] );

        $project_hash = Product_Feed_Helper::generate_legacy_project_hash();

        $new_feed     = clone $original;
        $new_feed->id = 0;
        /* translators: %s is the original feed title. */
        $new_feed->title               = sprintf( __( 'Copy of %s', 'woo-product-feed-pro' ), $original->title );
        $new_feed->post_status         = 'draft';
        $new_feed->status              = 'not run yet';
        $new_feed->legacy_project_hash = $project_hash;
        $new_feed->file_name           = $project_hash;
        $new_feed->last_updated        = '';

        do_action( 'adt_clone_product_feed_before_save', $new_feed, $original );

        try {
            $new_feed->save();
            $new_feed->register_action();
        } catch ( \Exception $e ) {
            \WP_CLI::error( $e->getMessage() );
        }

        do_action( 'adt_after_clone_product_feed', $new_feed, $original );

        if ( ! empty( $assoc_args['porcelain'] ) ) {
            \WP_CLI::log( (string) $new_feed->id );
            return;
        }

        \WP_CLI::success( sprintf( 'Duplicated feed %d → %d.', $original->id, $new_feed->id ) );
    }

    /**
     * Refresh (regenerate) one or more product feeds.
     *
     * By default the refresh kicks off Action Scheduler batches immediately, in-process.
     * With --async, the refresh is queued via adt_pfp_as_generate_product_feed so the
     * current terminal returns quickly and the feed is generated on the Action Scheduler worker.
     *
     * ## OPTIONS
     *
     * <id>...
     * : One or more feed IDs.
     *
     * [--async]
     * : Schedule the refresh instead of running it inline.
     *
     * ## EXAMPLES
     *
     *     wp adt-feed refresh 4945
     *     wp adt-feed refresh 4945 4955 --async
     *
     * @when after_wp_load
     *
     * @param array $args Positional args.
     * @param array $assoc_args Associative args.
     */
    public function refresh( $args, $assoc_args ) {
        $async = ! empty( $assoc_args['async'] );

        foreach ( $args as $id ) {
            $feed = $this->must_get_feed( $id );

            if ( $async ) {
                as_schedule_single_action(
                    time() + 1,
                    ADT_PFP_AS_GENERATE_PRODUCT_FEED,
                    array( 'feed_id' => $feed->id )
                );
                \WP_CLI::log( sprintf( 'Scheduled refresh for feed %d.', $feed->id ) );
                continue;
            }

            Product_Feed_Helper::disable_cache();

            try {
                $feed->generate( 'manual' );
            } catch ( \Exception $e ) {
                \WP_CLI::warning( sprintf( 'Feed %d: %s', $feed->id, $e->getMessage() ) );
                continue;
            }

            \WP_CLI::log( sprintf( 'Refresh started for feed %d.', $feed->id ) );
        }

        \WP_CLI::success( sprintf( '%d feed(s) processed.', count( $args ) ) );
    }

    /**
     * Cancel an in-progress feed generation.
     *
     * Mirrors ajax_cancel_product_feed in Product_Feed_Admin.php.
     *
     * ## OPTIONS
     *
     * <id>
     * : Feed ID.
     *
     * ## EXAMPLES
     *
     *     wp adt-feed cancel 4945
     *
     * @when after_wp_load
     *
     * @param array $args Positional args.
     * @param array $assoc_args Associative args.
     */
    public function cancel( $args, $assoc_args ) {
        $feed = $this->must_get_feed( $args[0] );

        do_action( 'adt_before_cancel_product_feed', $feed );

        as_unschedule_all_actions( '', array(), 'adt_pfp_as_generate_product_feed_batch_' . $feed->id );

        $feed->total_products_processed = 0;
        $feed->batch_size               = 0;
        $feed->executed_from            = '';
        $feed->status                   = 'stopped';
        $feed->last_updated             = gmdate( 'd M Y H:i:s' );
        $feed->save();

        as_schedule_single_action(
            time() + 1,
            ADT_PFP_AS_PRODUCT_FEED_UPDATE_STATS,
            array( 'feed_id' => $feed->id )
        );

        do_action( 'adt_after_cancel_product_feed', $feed );

        \WP_CLI::success( sprintf( 'Cancelled feed %d.', $feed->id ) );
    }

    /**
     * Activate one or more feeds (set post_status=publish and re-register the refresh schedule).
     *
     * ## OPTIONS
     *
     * <id>...
     * : One or more feed IDs.
     *
     * ## EXAMPLES
     *
     *     wp adt-feed activate 4945 4955
     *
     * @when after_wp_load
     *
     * @param array $args Positional args.
     * @param array $assoc_args Associative args.
     */
    public function activate( $args, $assoc_args ) {
        foreach ( $args as $id ) {
            $feed = $this->must_get_feed( $id );

            try {
                $feed->post_status = 'publish';
                $feed->save();
                $feed->register_action();
                \WP_CLI::log( sprintf( 'Activated feed %d.', $feed->id ) );
            } catch ( \Exception $e ) {
                \WP_CLI::warning( sprintf( 'Feed %d: %s', $feed->id, $e->getMessage() ) );
            }
        }

        \WP_CLI::success( sprintf( '%d feed(s) processed.', count( $args ) ) );
    }

    /**
     * Deactivate one or more feeds (set post_status=draft and cancel the refresh schedule).
     *
     * ## OPTIONS
     *
     * <id>...
     * : One or more feed IDs.
     *
     * ## EXAMPLES
     *
     *     wp adt-feed deactivate 4945
     *
     * @when after_wp_load
     *
     * @param array $args Positional args.
     * @param array $assoc_args Associative args.
     */
    public function deactivate( $args, $assoc_args ) {
        foreach ( $args as $id ) {
            $feed = $this->must_get_feed( $id );

            try {
                $feed->post_status = 'draft';
                $feed->save();
                $feed->unregister_action();
                \WP_CLI::log( sprintf( 'Deactivated feed %d.', $feed->id ) );
            } catch ( \Exception $e ) {
                \WP_CLI::warning( sprintf( 'Feed %d: %s', $feed->id, $e->getMessage() ) );
            }
        }

        \WP_CLI::success( sprintf( '%d feed(s) processed.', count( $args ) ) );
    }

    /**
     * Run the stats-history update once for a feed (counts products in the generated file).
     *
     * ## OPTIONS
     *
     * <id>
     * : Feed ID.
     *
     * ## EXAMPLES
     *
     *     wp adt-feed update-stats 4945
     *
     * @subcommand update-stats
     * @when after_wp_load
     *
     * @param array $args Positional args.
     * @param array $assoc_args Associative args.
     */
    public function update_stats( $args, $assoc_args ) {
        $feed = $this->must_get_feed( $args[0] );

        do_action( ADT_PFP_AS_PRODUCT_FEED_UPDATE_STATS, $feed->id );

        \WP_CLI::success( sprintf( 'Stats updated for feed %d.', $feed->id ) );
    }

    /**
     * Resolve a feed by ID or project hash, or error out.
     *
     * @since 13.5.4
     *
     * @param mixed $identifier Feed ID or legacy project hash.
     * @return Product_Feed
     */
    private function must_get_feed( $identifier ) {
        $feed = Product_Feed_Helper::get_product_feed( is_numeric( $identifier ) ? (int) $identifier : (string) $identifier );
        if ( ! $feed ) {
            \WP_CLI::error( sprintf( 'Feed not found: %s', $identifier ) );
        }
        return $feed;
    }

    /**
     * Ensure create() has the minimum required properties.
     *
     * @since 13.5.4
     *
     * @param array $props Properties.
     */
    private function validate_create_props( array $props ) {
        $required = array( 'title', 'channel_hash', 'country', 'file_format' );
        $missing  = array();
        foreach ( $required as $key ) {
            if ( empty( $props[ $key ] ) ) {
                $missing[] = $key;
            }
        }
        if ( ! empty( $missing ) ) {
            \WP_CLI::error( sprintf( 'Missing required properties: %s', implode( ', ', $missing ) ) );
        }

        $allowed_formats = Formatter::supported_file_formats();
        if ( ! in_array( $props['file_format'], $allowed_formats, true ) ) {
            \WP_CLI::error( sprintf( 'Unsupported file format: %s. Allowed: %s', $props['file_format'], implode( ', ', $allowed_formats ) ) );
        }
    }
}
