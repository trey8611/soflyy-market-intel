<?php
namespace Soflyy\MarketIntel\REST;

use Soflyy\MarketIntel\Collectors\Registry;

class CollectEndpoint {

    public function __construct( private readonly Registry $registry ) {}

    public function register(): void {
        register_rest_route( 'smi/v1', '/collect', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle' ],
            'permission_callback' => '__return_false', // auth is bearer-token only, not WP cap
        ] );
    }

    public function handle( \WP_REST_Request $request ): \WP_REST_Response {
        // Security: This route writes collected metrics only. No read route exists.
        // Sales/revenue data has no code path to this endpoint — it is entered via
        // the admin manual-entry form and never transmitted externally.

        if ( ! is_ssl() ) {
            return new \WP_REST_Response( [ 'error' => 'HTTPS required' ], 403 );
        }

        if ( ! $this->authenticate( $request ) ) {
            return new \WP_REST_Response( [ 'error' => 'Unauthorized' ], 401 );
        }

        $collector = sanitize_key( $request->get_param( 'collector' ) ?: '' );
        $results   = $this->registry->dispatch( $collector ?: null );

        // Return status summary only — no stored metric values ever appear here.
        $summary = [];
        foreach ( $results as $slug => $r ) {
            $summary[ $slug ] = [ 'rows_written' => $r['rows'], 'error' => $r['error'] ];
        }

        return new \WP_REST_Response( [
            'dispatched'   => array_keys( $results ),
            'results'      => $summary,
        ], 200 );
    }

    private function authenticate( \WP_REST_Request $request ): bool {
        if ( ! defined( 'SMI_CRON_KEY' ) ) {
            return false;
        }

        $header = $request->get_header( 'authorization' );
        if ( ! $header || ! str_starts_with( $header, 'Bearer ' ) ) {
            return false;
        }

        $token = substr( $header, 7 );

        // hash_equals prevents timing attacks.
        return hash_equals( SMI_CRON_KEY, $token );
    }
}
