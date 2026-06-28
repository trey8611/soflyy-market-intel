<?php
namespace Soflyy\MarketIntel;

class Autoloader {
    public static function register(): void {
        spl_autoload_register( static function ( string $class ): void {
            $prefix = 'Soflyy\\MarketIntel\\';
            if ( ! str_starts_with( $class, $prefix ) ) {
                return;
            }
            $relative = substr( $class, strlen( $prefix ) );
            $file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
            if ( is_file( $file ) ) {
                require $file;
            }
        } );
    }
}
