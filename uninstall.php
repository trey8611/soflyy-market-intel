<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}
require_once __DIR__ . '/src/Autoloader.php';
\Soflyy\MarketIntel\Autoloader::register();
\Soflyy\MarketIntel\Database\Schema::uninstall();
