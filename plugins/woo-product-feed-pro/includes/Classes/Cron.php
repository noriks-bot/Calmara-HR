<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes
 */

namespace AdTribes\PFP\Classes;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Factories\Product_Feed;
use AdTribes\PFP\Helpers\Product_Feed_Helper;
use AdTribes\PFP\Traits\Singleton_Trait;
/**
 * Product Feed Cron class.
 *
 * @since 13.3.5
 */
class Cron extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Get the amount of products in the feed file.
     *
     * @param string       $file        The file path.
     * @param string       $file_format The file format.
     * @param Product_Feed $feed        The feed data object.
     *
     * @return int The amount of products in the feed file.
     */
    private function get_product_counts_from_file( $file, $file_format, $feed ) {
        $products_count = 0;

        // Check if file exists.
        if ( ! file_exists( $file ) ) {
            return $products_count;
        }

        switch ( $file_format ) {
            case 'xml':
                $xml          = simplexml_load_file( $file, 'SimpleXMLElement', LIBXML_NOCDATA );
                $feed_channel = $feed->get_channel();

                if ( 'Yandex' === $feed_channel['name'] ) {
                    $products_count = isset( $xml->offers->offer ) && is_countable( $xml->offers->offer ) ? count( $xml->offers->offer ) : 0;
                } elseif ( 'none' === $feed_channel['taxonomy'] ) {
                    $products_count = isset( $xml->product ) && is_countable( $xml->product ) ? count( $xml->product ) : 0;
                } else {
                    $products_count = isset( $xml->channel->item ) && is_countable( $xml->channel->item ) ? count( $xml->channel->item ) : 0;
                }

                break;
            case 'csv':
            case 'txt':
            case 'tsv':
                $products_count = count( file( $file ) ) - 1; // -1 for the header.
                break;
            case 'jsonl':
                $products_count = $this->count_non_empty_lines( $file, false );
                break;
            case 'jsonl.gz':
                $products_count = $this->count_non_empty_lines( $file, true );
                break;
            case 'csv.gz':
            case 'tsv.gz':
                $line_count     = $this->count_non_empty_lines( $file, true );
                $products_count = $line_count > 0 ? $line_count - 1 : 0; // -1 for the header.
                break;
        }

        /**
         * Filter the amount of history products in the system report.
         *
         * @since 13.3.5
         *
         * @param int          $products_count The amount of products in the feed file.
         * @param string       $file           The file path.
         * @param string       $file_format    The file format.
         * @param Product_Feed $feed           The feed data object.
         */
        return apply_filters( 'adt_product_feed_history_count', $products_count, $file, $file_format, $feed );
    }

    /**
     * Count non-empty lines in a file, streaming plain or gzip-compressed input.
     *
     * Streams line-by-line so large feed files do not need to be loaded
     * entirely into memory.
     *
     * @since 13.5.4
     *
     * @param string $file     The file path.
     * @param bool   $is_gzip  Whether the file is gzip-compressed.
     * @return int
     */
    private function count_non_empty_lines( $file, $is_gzip ) {
        $count  = 0;
        $handle = $is_gzip ? gzopen( $file, 'rb' ) : fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

        if ( false === $handle ) {
            return 0;
        }

        while ( ! ( $is_gzip ? gzeof( $handle ) : feof( $handle ) ) ) {
            $line = $is_gzip ? gzgets( $handle ) : fgets( $handle );
            if ( false === $line ) {
                break;
            }
            if ( '' !== trim( $line ) ) {
                ++$count;
            }
        }

        if ( $is_gzip ) {
            gzclose( $handle );
        } else {
            fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        }

        return $count;
    }

    /**
     * Schedule the next batch.
     *
     * @since 13.4.1
     * @access public
     *
     * @param int $feed_id    The feed ID.
     * @param int $offset     The offset of the batch.
     * @param int $batch_size The batch size.
     * @return int The action ID.
     */
    public static function schedule_next_batch( $feed_id, $offset, $batch_size ) {
        // Set the next scheduled event.
        $action_id = as_schedule_single_action(
            time() + 1,
            ADT_PFP_AS_GENERATE_PRODUCT_FEED_BATCH,
            array(
                'feed_id'    => $feed_id,
                'offset'     => $offset,
                'batch_size' => $batch_size,
            ),
            'adt_pfp_as_generate_product_feed_batch_' . $feed_id,
            false,
            5
        );

        return $action_id;
    }

    /***************************************************************************
     * Action Scheduler
     * **************************************************************************
     */

    /**
     * Generate product feed callback.
     *
     * @since 13.3.9
     * @access public
     *
     * @param int $feed_id The feed ID.
     */
    public function as_generate_product_feed_callback( $feed_id ) {
        $feed = Product_Feed_Helper::get_product_feed( $feed_id );
        if ( ! $feed ) {
            return;
        }

        // Guard: if feed is still processing, check if it's stuck or has active batches.
        if ( 'processing' === $feed->status ) {
            $pending_batches = $this->query_pending_batch_actions( $feed_id );

            if ( ! empty( $pending_batches ) ) {
                // Previous run still has pending batches — skip this scheduled run.
                if ( function_exists( 'wc_get_logger' ) ) {
                    wc_get_logger()->warning(
                        'Skipping scheduled feed generation: previous run still has pending batches',
                        array(
                            'source'          => 'woo-product-feed-pro',
                            'feed_id'         => $feed_id,
                            'pending_batches' => count( $pending_batches ),
                        )
                    );
                }
                return;
            }

            // No pending batches but status is processing — feed is stuck, allow reset.
            if ( function_exists( 'wc_get_logger' ) ) {
                wc_get_logger()->info(
                    'Feed appears stuck (processing with no pending batches), allowing reset',
                    array(
                        'source'  => 'woo-product-feed-pro',
                        'feed_id' => $feed_id,
                    )
                );
            }

            // Reset status so generate() won't be blocked by the guard.
            $feed->status = 'ready';
            $feed->save();
        }

        Product_Feed_Helper::disable_cache();

        $feed->generate( 'schedule' );
    }

    /**
     * Process product feed in batch.
     *
     * @since 13.3.9
     * @access public
     *
     * @param int $feed_id    The feed ID.
     * @param int $offset     The offset of the batch.
     * @param int $batch_size The batch size.
     */
    public function as_generate_product_feed_batch_callback( $feed_id, $offset = 0, $batch_size = 0 ) {
        $feed = Product_Feed_Helper::get_product_feed( $feed_id );
        if ( ! $feed ) {
            return;
        }

        /**
         * Check if the feed is still in processing status.
         *
         * Skip if the feed was stopped by the user, or if it's no longer processing
         * (e.g., a stale batch from a previous run arriving after a new run completed).
         */
        if ( 'processing' !== $feed->status ) {
            return;
        }

        $feed->run_batch_event( $offset, $batch_size, 'cron' );
    }

    /**
     * Set project history: amount of products in the feed.
     *
     * @since 13.3.5
     * @access public
     *
     * @param int $feed_id The Feed ID.
     **/
    public function as_product_feed_update_stats( $feed_id ) {
        $feed = Product_Feed_Helper::get_product_feed( $feed_id );
        if ( ! $feed ) {
            return;
        }

        $products_count = 0;
        $file           = $feed->get_file_path();
        $file_format    = $feed->file_format;
        $products_count = file_exists( $file ) ? $this->get_product_counts_from_file( $file, $file_format, $feed ) : 0;

        $feed->add_history_product( $products_count );
        $feed->save();
    }


    /**
     * Query pending batch actions for a given feed.
     *
     * @since 13.5.4
     * @access private
     *
     * @param int $feed_id Feed ID.
     * @return array
     */
    private function query_pending_batch_actions( $feed_id ) {
        return as_get_scheduled_actions(
            array(
                'hook'     => ADT_PFP_AS_GENERATE_PRODUCT_FEED_BATCH,
                'status'   => \ActionScheduler_Store::STATUS_PENDING,
                'args'     => array( 'feed_id' => intval( $feed_id ) ),
                'per_page' => 1,
            ),
            'ids'
        );
    }

    /**
     * Run the class
     *
     * @codeCoverageIgnore
     * @since 13.3.5
     */
    public function run() {
        // Action Scheduler.
        add_action( ADT_PFP_AS_GENERATE_PRODUCT_FEED, array( $this, 'as_generate_product_feed_callback' ), 1, 1 );
        add_action( ADT_PFP_AS_GENERATE_PRODUCT_FEED_BATCH, array( $this, 'as_generate_product_feed_batch_callback' ), 1, 3 );
        add_action( ADT_PFP_AS_PRODUCT_FEED_UPDATE_STATS, array( $this, 'as_product_feed_update_stats' ), 1, 1 );
    }
}
