<?php
/**
 * Plugin Name: Market Intel (Internal)
 * Description: Internal market-intelligence dashboard. Not for distribution.
 * Version:     1.0.0
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SMI_FILE',    __FILE__ );
define( 'SMI_DIR',     plugin_dir_path( __FILE__ ) );
define( 'SMI_VERSION', '1.0.3' );

require_once SMI_DIR . 'src/Autoloader.php';
\Soflyy\MarketIntel\Autoloader::register();

register_activation_hook( __FILE__, [ \Soflyy\MarketIntel\Database\Schema::class, 'install' ] );
register_uninstall_hook( __FILE__, [ \Soflyy\MarketIntel\Database\Schema::class, 'uninstall' ] );

add_action( 'plugins_loaded', static function () {
    if ( get_option( 'smi_db_version' ) !== SMI_VERSION ) {
        \Soflyy\MarketIntel\Database\Schema::install();
    }
    \Soflyy\MarketIntel\Plugin::instance()->boot();
} );
