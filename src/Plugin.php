<?php
namespace Soflyy\MarketIntel;

use Soflyy\MarketIntel\Collectors\Registry;
use Soflyy\MarketIntel\Database\Schema;

class Plugin {
    private static ?self $instance = null;
    public readonly Registry $registry;

    private function __construct() {
        $this->registry = new Registry();
    }

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public function boot(): void {
        add_action( 'rest_api_init',         [ $this, 'register_rest' ] );
        add_action( 'admin_menu',            [ $this, 'register_admin' ] );
        add_action( 'admin_post_smi_export', [ $this, 'handle_smi_export' ] );

        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            \WP_CLI::add_command( 'smi', \Soflyy\MarketIntel\CLI\Command::class );
        }

        $this->register_collectors();
    }

    public function handle_smi_export(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied.', 'soflyy-market-intel' ) );
        }
        check_admin_referer( 'smi_export' );
        ( new \Soflyy\MarketIntel\Admin\ExportAnalysis() )->handle_export();
        exit;
    }

    public function register_rest(): void {
        ( new \Soflyy\MarketIntel\REST\CollectEndpoint( $this->registry ) )->register();
    }

    public function register_admin(): void {
        ( new \Soflyy\MarketIntel\Admin\Admin( $this->registry ) )->register();
    }

    private function register_collectors(): void {
        $this->registry->register( new \Soflyy\MarketIntel\Collectors\WordPressOrg() );
        $this->registry->register( new \Soflyy\MarketIntel\Collectors\Wayback() );
        $this->registry->register( new \Soflyy\MarketIntel\Collectors\W3Techs() );
        $this->registry->register( new \Soflyy\MarketIntel\Collectors\GoogleTrends() );
        $this->registry->register( new \Soflyy\MarketIntel\Collectors\GitHub() );
        $this->registry->register( new \Soflyy\MarketIntel\Collectors\BuiltWith() );
    }
}
