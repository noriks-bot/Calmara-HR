<?php
/**
 * Author: Rymera Web Co.
 *
 * @package AdTribes\PFP\Classes\CLI
 */

namespace AdTribes\PFP\Classes\CLI;

use AdTribes\PFP\Abstracts\Abstract_Class;
use AdTribes\PFP\Traits\Singleton_Trait;

/**
 * Registers WP-CLI commands for managing product feeds from the terminal.
 *
 * @since 13.5.4
 */
class CLI_Bootstrap extends Abstract_Class {

    use Singleton_Trait;

    /**
     * Run the class.
     *
     * @since 13.5.4
     */
    public function run() {
        if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            return;
        }

        \WP_CLI::add_command( 'adt-feed', Feed_Command::class );
        \WP_CLI::add_command( 'adt-feed channel', Channel_Command::class );
        \WP_CLI::add_command( 'adt-feed format', Format_Command::class );
    }
}
