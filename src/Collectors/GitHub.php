<?php
namespace Soflyy\MarketIntel\Collectors;

use Soflyy\MarketIntel\Database\Schema;
use Soflyy\MarketIntel\Enums\Confidence;

class GitHub extends Collector {

    private const API_BASE = 'https://api.github.com';

    public function slug(): string     { return 'github'; }
    public function schedule(): string { return 'weekly'; }

    protected function collect(): array {
        $entities = Schema::get_entities_with_github_repo();
        $rows     = [];
        $today    = new \DateTimeImmutable( 'today' );

        foreach ( $entities as $entity ) {
            [ $owner, $repo ] = explode( '/', $entity['github_repo'], 2 );

            // Check rate limit before each entity.
            if ( ! $this->rate_limit_ok() ) {
                $this->flag_manual_fallback( $entity['slug'], 'github_stars', 'GitHub rate limit approaching', 'Manual Entry → General metric' );
                break;
            }

            $info = $this->api_get( "/repos/{$owner}/{$repo}" );
            if ( ! $info ) continue;

            $id = (int) $entity['id'];

            $rows[] = new MetricRow( $id, 'github_stars',       (float) ( $info['stargazers_count'] ?? 0 ), null, Confidence::High, 'github-api', $today );
            $rows[] = new MetricRow( $id, 'github_forks',       (float) ( $info['forks_count']      ?? 0 ), null, Confidence::High, 'github-api', $today );
            $rows[] = new MetricRow( $id, 'github_open_issues', (float) ( $info['open_issues_count'] ?? 0 ), null, Confidence::High, 'github-api', $today );

            // Release cadence
            $releases = $this->api_get( "/repos/{$owner}/{$repo}/releases?per_page=10" );
            if ( $releases && count( $releases ) >= 2 ) {
                $dates   = array_column( $releases, 'published_at' );
                $latest  = $dates[0];
                $cadence = $this->avg_days_between( array_slice( $dates, 0, 3 ) );

                $rows[] = new MetricRow( $id, 'github_latest_release',   null,     $latest,  Confidence::High, 'github-api', $today );
                $rows[] = new MetricRow( $id, 'github_release_cadence',  $cadence, null,     Confidence::High, 'github-api', $today );
            }

            // Contributor count (GitHub caches this endpoint)
            $contribs = $this->api_get( "/repos/{$owner}/{$repo}/stats/contributors" );
            if ( is_array( $contribs ) ) {
                $rows[] = new MetricRow( $id, 'github_contributors', (float) count( $contribs ), null, Confidence::High, 'github-api', $today );
            }
        }

        return $rows;
    }

    private function api_get( string $path ): ?array {
        $response = $this->http_get( self::API_BASE . $path, [
            'headers' => [ 'Accept' => 'application/vnd.github+json' ],
        ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $data ) ? $data : null;
    }

    private function rate_limit_ok(): bool {
        $response = $this->http_get( self::API_BASE . '/rate_limit' );
        if ( is_wp_error( $response ) ) return true;
        $data      = json_decode( wp_remote_retrieve_body( $response ), true );
        $remaining = $data['rate']['remaining'] ?? 999;
        return $remaining > 5;
    }

    private function avg_days_between( array $iso_dates ): float {
        $timestamps = array_map( 'strtotime', $iso_dates );
        $diffs      = [];
        for ( $i = 0; $i < count( $timestamps ) - 1; $i++ ) {
            $diffs[] = abs( $timestamps[ $i ] - $timestamps[ $i + 1 ] ) / 86400;
        }
        return $diffs ? array_sum( $diffs ) / count( $diffs ) : 0.0;
    }
}
