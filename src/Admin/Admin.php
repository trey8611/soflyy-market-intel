<?php
namespace Soflyy\MarketIntel\Admin;

use Soflyy\MarketIntel\Collectors\Registry;

class Admin {

    public function __construct( private readonly Registry $registry ) {}

    public function register(): void {
        add_menu_page(
            'Market Intel',
            'Market Intel',
            'manage_options',
            'soflyy-market-intel',
            [ $this, 'render' ],
            'dashicons-chart-line',
            75
        );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Access denied.', 'soflyy-market-intel' ) );
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';
        $page_url = menu_page_url( 'soflyy-market-intel', false );

        wp_enqueue_style( 'smi-admin', plugins_url( 'assets/css/admin.css', SMI_FILE ), [], SMI_VERSION );

        if ( $tab === 'dashboard' ) {
            wp_enqueue_script( 'smi-apexcharts', plugins_url( 'assets/js/apexcharts.min.js', SMI_FILE ), [], SMI_VERSION, true );
            wp_enqueue_script( 'smi-dashboard',  plugins_url( 'assets/js/dashboard.js',       SMI_FILE ), [ 'smi-apexcharts' ], SMI_VERSION, true );
        }

        ?>
        <div class="wrap">
            <h1>Market Intel</h1>
            <div class="smi-tabs">
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'dashboard',    $page_url ) ); ?>"
                   class="<?php echo $tab === 'dashboard'    ? 'active' : ''; ?>">Dashboard</a>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'manual-entry', $page_url ) ); ?>"
                   class="<?php echo $tab === 'manual-entry' ? 'active' : ''; ?>">Manual Entry</a>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'edd-snippet',  $page_url ) ); ?>"
                   class="<?php echo $tab === 'edd-snippet'  ? 'active' : ''; ?>">EDD Snippet</a>
                <a href="<?php echo esc_url( add_query_arg( 'tab', 'export-analysis', $page_url ) ); ?>"
                   class="<?php echo $tab === 'export-analysis' ? 'active' : ''; ?>">Export &amp; Analysis</a>
            </div>
            <?php
            match ( $tab ) {
                'manual-entry'   => ( new ManualEntry() )->render(),
                'edd-snippet'    => ( new EddSnippet() )->render(),
                'export-analysis'=> ( new ExportAnalysis() )->render(),
                default          => ( new Dashboard( $this->registry ) )->render(),
            };
            ?>
        </div>
        <?php
    }
}
