<?php
namespace Soflyy\MarketIntel\Admin;

use Soflyy\MarketIntel\Database\Schema;

class ExportAnalysis {

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$prompt_output = null;
		if ( ! empty( $_POST['smi_action'] ) && $_POST['smi_action'] === 'build_prompt' ) {
			check_admin_referer( 'smi_build_prompt' );
			$prompt_output = $this->build_prompt_output();
		}

		$entities    = $this->get_entities_for_ui();
		$metric_keys = $this->get_metric_keys_for_ui();

		$this->render_export_form( $entities, $metric_keys );
		$this->render_prompt_form( $prompt_output );
	}

	public function handle_export(): void {
		global $wpdb;

		$format = isset( $_POST['format'] ) && in_array( $_POST['format'], [ 'csv', 'json', 'xml' ], true )
			? sanitize_key( $_POST['format'] )
			: 'csv';
		$from   = ! empty( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : null;
		$to     = ! empty( $_POST['date_to'] )   ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) )   : null;

		// Entity IDs — integers only.
		$entity_ids_str = null;
		if ( ! empty( $_POST['entity_ids'] ) && is_array( $_POST['entity_ids'] ) ) {
			$ids = array_filter( array_map( 'intval', $_POST['entity_ids'] ) );
			if ( $ids ) {
				$entity_ids_str = implode( ',', $ids );
			}
		}
		// No entities selected — stream empty file instead of full dump.
		if ( $entity_ids_str === null ) {
			$fmt  = isset( $_POST['format'] ) && in_array( $_POST['format'], [ 'csv', 'json', 'xml' ], true )
				? sanitize_key( $_POST['format'] ) : 'csv';
			$date = gmdate( 'Y-m-d' );
			match ( $fmt ) {
				'json'  => $this->stream_json( [], $date ),
				'xml'   => $this->stream_xml( [], $date ),
				default => $this->stream_csv( [], $date ),
			};
			return;
		}

		// Metric keys — whitelist-intersected against non-excluded keys from DB.
		$allowed_keys    = $this->get_metric_keys_for_ui();
		$metric_keys_raw = $allowed_keys;
		if ( ! empty( $_POST['metric_keys'] ) && is_array( $_POST['metric_keys'] ) ) {
			$selected = array_values( array_intersect(
				array_map( 'sanitize_key', wp_unslash( $_POST['metric_keys'] ) ),
				$allowed_keys
			) );
			if ( $selected ) {
				$metric_keys_raw = $selected;
			}
		}

		$latest_q    = Schema::latest_metrics_query( $entity_ids_str, null );
		$where_parts = [];

		// Selected metric keys.
		if ( $metric_keys_raw ) {
			$mk_ph         = implode( ', ', array_fill( 0, count( $metric_keys_raw ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$where_parts[] = $wpdb->prepare( "m.metric_key IN ($mk_ph)", ...$metric_keys_raw );
		}

		// Date range.
		if ( $from ) { $where_parts[] = $wpdb->prepare( 'm.period_date >= %s', $from ); }
		if ( $to )   { $where_parts[] = $wpdb->prepare( 'm.period_date <= %s', $to );   }

		// Sales data exclusion (outer alias = m).
		$where_parts[] = $this->excl_where( 'm' );

		$where = $where_parts ? 'AND ' . implode( ' AND ', $where_parts ) : '';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( "
			SELECT e.name AS entity_name, e.slug AS entity_slug, e.type AS entity_type,
			       m.metric_key, m.value, m.value_text, m.confidence, m.source, m.period_date
			FROM ( {$latest_q} ) m
			JOIN `{$wpdb->prefix}smi_entities` e ON e.id = m.entity_id
			WHERE 1=1 {$where}
			ORDER BY m.period_date DESC, e.name, m.metric_key
		", ARRAY_A ) ?: [];

		$date = gmdate( 'Y-m-d' );
		match ( $format ) {
			'json'  => $this->stream_json( $rows, $date ),
			'xml'   => $this->stream_xml( $rows, $date ),
			default => $this->stream_csv( $rows, $date ),
		};
	}

	private function stream_csv( array $rows, string $date ): void {
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="smi-export-' . $date . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, [
			'entity_name', 'entity_slug', 'entity_type', 'metric_key',
			'value', 'value_text', 'confidence', 'source', 'period_date',
		] );
		foreach ( $rows as $row ) {
			fputcsv( $out, [
				$row['entity_name'], $row['entity_slug'], $row['entity_type'],
				$row['metric_key'],  $row['value'],       (string) ( $row['value_text'] ?? '' ),
				$row['confidence'],  $row['source'],      $row['period_date'],
			] );
		}
		fclose( $out );
	}

	private function stream_json( array $rows, string $date ): void {
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="smi-export-' . $date . '.json"' );
		echo wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
	}

	private function stream_xml( array $rows, string $date ): void {
		header( 'Content-Type: application/xml; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="smi-export-' . $date . '.xml"' );

		$doc             = new \DOMDocument( '1.0', 'UTF-8' );
		$doc->formatOutput = true;
		$root            = $doc->createElement( 'metrics' );
		$doc->appendChild( $root );

		$cols = [
			'entity_name', 'entity_slug', 'entity_type', 'metric_key',
			'value', 'value_text', 'confidence', 'source', 'period_date',
		];

		foreach ( $rows as $row ) {
			$el = $doc->createElement( 'metric' );
			foreach ( $cols as $col ) {
				$child = $doc->createElement( $col );
				$child->appendChild( $doc->createTextNode( (string) ( $row[ $col ] ?? '' ) ) );
				$el->appendChild( $child );
			}
			$root->appendChild( $el );
		}

		$xml = $doc->saveXML();
		if ( $xml === false ) {
			// Cannot encode as XML — a value_text field contains XML-invalid control characters.
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Content-Disposition: inline' );
			echo 'Export failed: one or more values contain characters that cannot be represented in XML 1.0. Try CSV or JSON format instead.';
			return;
		}
		echo $xml;
	}

	private function build_prompt_output(): string {
		$format   = isset( $_POST['ai_format'] ) && wp_unslash( $_POST['ai_format'] ) === 'chatgpt' ? 'chatgpt' : 'claude';
		$question = ! empty( $_POST['custom_question'] )
			? sanitize_textarea_field( wp_unslash( $_POST['custom_question'] ) )
			: 'Based on this data, is WordPress expanding, contracting, or stable as a platform? Identify the single strongest signal, note any contradictions between signals, and give a one-sentence verdict.';

		$data = $this->build_prompt_data();

		return $format === 'chatgpt'
			? $this->build_chatgpt_prompt( $data, $question )
			: $this->build_claude_prompt( $data, $question );
	}

	private function build_prompt_data(): array {
		global $wpdb;
		$data = [];

		// All metric_key values passed to latest_metrics_query() below are hardcoded
		// non-sales keys (cms_market_share, active_installs, search_interest,
		// builtwith_store_count). Sales data exclusion is structural here, not via excl_where().

		// WordPress CMS share — all available periods, ASC.
		$wp_id = Schema::get_entity_id( 'wordpress' );
		if ( $wp_id ) {
			$q = Schema::latest_metrics_query( (string) $wp_id, 'cms_market_share' );
			$data['wp_cms'] = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT value, confidence, period_date FROM ($q) m ORDER BY m.period_date ASC",
				ARRAY_A
			) ?: [];
		}

		// None-CMS share — all available, ASC.
		$none_id = Schema::get_entity_id( 'none-cms' );
		if ( $none_id ) {
			$q = Schema::latest_metrics_query( (string) $none_id, 'cms_market_share' );
			$data['none_cms'] = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT value, confidence, period_date FROM ($q) m ORDER BY m.period_date ASC",
				ARRAY_A
			) ?: [];
		}

		// Competitor platform CMS shares — last 3 per platform, ASC.
		$data['platform_cms'] = [];
		$platform_rows = $wpdb->get_results(
			"SELECT id, name FROM `{$wpdb->prefix}smi_entities`
			 WHERE type = 'platform' AND slug NOT IN ('wordpress','none-cms')
			 ORDER BY name",
			ARRAY_A
		) ?: [];
		if ( $platform_rows ) {
			$ids_str  = implode( ',', array_column( $platform_rows, 'id' ) );
			$name_map = array_column( $platform_rows, 'name', 'id' );
			$q = Schema::latest_metrics_query( $ids_str, 'cms_market_share' );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$all_rows = $wpdb->get_results(
				"SELECT m.entity_id, m.value, m.confidence, m.period_date FROM ($q) m ORDER BY m.entity_id, m.period_date DESC",
				ARRAY_A
			) ?: [];
			// Group by entity, keep last 3 DESC, then reverse each group to ASC.
			$by_entity = [];
			foreach ( $all_rows as $row ) {
				$eid = $row['entity_id'];
				if ( ! isset( $by_entity[ $eid ] ) ) {
					$by_entity[ $eid ] = [];
				}
				if ( count( $by_entity[ $eid ] ) < 3 ) {
					$by_entity[ $eid ][] = $row;
				}
			}
			foreach ( $platform_rows as $p ) {
				$eid = $p['id'];
				if ( ! empty( $by_entity[ $eid ] ) ) {
					$data['platform_cms'][ $p['name'] ] = array_reverse( $by_entity[ $eid ] );
				}
			}
		}

		// WP All Import installs — last 5, ASC.
		$wpa_id = Schema::get_entity_id( 'wp-all-import' );
		if ( $wpa_id ) {
			$q = Schema::latest_metrics_query( (string) $wpa_id, 'active_installs' );
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT value, value_text, confidence, period_date FROM ($q) m ORDER BY m.period_date DESC LIMIT 5",
				ARRAY_A
			) ?: [];
			$data['wp_all_import_installs'] = array_reverse( $rows );
		}

		// WordPress search interest — last 3, ASC.
		if ( $wp_id ) {
			$q = Schema::latest_metrics_query( (string) $wp_id, 'search_interest' );
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT value, confidence, period_date FROM ($q) m ORDER BY m.period_date DESC LIMIT 3",
				ARRAY_A
			) ?: [];
			$data['search_interest'] = array_reverse( $rows );
		}

		// BuiltWith store counts — WooCommerce + Shopify, last 3 each, ASC.
		$data['builtwith'] = [];
		foreach ( [ 'woocommerce' => 'WooCommerce', 'shopify' => 'Shopify' ] as $slug => $label ) {
			$id = Schema::get_entity_id( $slug );
			if ( $id ) {
				$q    = Schema::latest_metrics_query( (string) $id, 'builtwith_store_count' );
				$rows = $wpdb->get_results(
					// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					"SELECT value, confidence, period_date FROM ($q) m ORDER BY m.period_date DESC LIMIT 3",
					ARRAY_A
				) ?: [];
				if ( $rows ) {
					$data['builtwith'][ $label ] = array_reverse( $rows );
				}
			}
		}

		return $data;
	}

	private function build_claude_prompt( array $data, string $question ): string {
		$e = static fn( string $s ): string => htmlspecialchars( $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );

		$context = "This data tracks WordPress and its competitors across CMS market share, plugin install\n"
			. "counts, search interest, and ecommerce store counts. All percentages are\n"
			. '"% of all websites" from W3Techs unless noted.' . "\n\n"
			. "Each series mixes data of varying confidence: live API reads, archived Wayback snapshots,\n"
			. 'bucketed install ranges (e.g. "50,000+"), and manual entries. Treat bucketed and manual'
			. "\nfigures as approximate, not exact, and weight conclusions accordingly.";

		$out  = "You are a market analyst. Analyze the following WordPress ecosystem data.\n\n";
		$out .= "<context>\n{$context}\n</context>\n\n";
		$out .= "<data>\n";

		if ( ! empty( $data['wp_cms'] ) ) {
			$out .= "  <series name=\"WordPress CMS share (% of all websites)\">\n";
			foreach ( $data['wp_cms'] as $r ) {
				$out .= sprintf(
					"    <point period=\"%s\" value=\"%s\" confidence=\"%s\" />\n",
					$e( $r['period_date'] ), $e( $r['value'] ), $e( $r['confidence'] )
				);
			}
			$out .= "  </series>\n";
		}

		if ( ! empty( $data['none_cms'] ) ) {
			$out .= "  <series name=\"Sites with no CMS (% of all websites)\">\n";
			foreach ( $data['none_cms'] as $r ) {
				$out .= sprintf(
					"    <point period=\"%s\" value=\"%s\" confidence=\"%s\" />\n",
					$e( $r['period_date'] ), $e( $r['value'] ), $e( $r['confidence'] )
				);
			}
			$out .= "  </series>\n";
		}

		if ( ! empty( $data['platform_cms'] ) ) {
			$out .= "  <series name=\"Competitor CMS shares (% of all websites, last 3 periods)\">\n";
			foreach ( $data['platform_cms'] as $name => $rows ) {
				foreach ( $rows as $r ) {
					$out .= sprintf(
						"    <point platform=\"%s\" period=\"%s\" value=\"%s\" confidence=\"%s\" />\n",
						$e( $name ), $e( $r['period_date'] ), $e( $r['value'] ), $e( $r['confidence'] )
					);
				}
			}
			$out .= "  </series>\n";
		}

		if ( ! empty( $data['wp_all_import_installs'] ) ) {
			$out .= "  <series name=\"WP All Import active installs (Wayback/WP.org bellwether)\">\n";
			foreach ( $data['wp_all_import_installs'] as $r ) {
				$display = ! empty( $r['value_text'] ) ? $r['value_text'] : $r['value'];
				$out .= sprintf(
					"    <point period=\"%s\" value=\"%s\" confidence=\"%s\" />\n",
					$e( $r['period_date'] ), $e( (string) $display ), $e( $r['confidence'] )
				);
			}
			$out .= "  </series>\n";
		}

		if ( ! empty( $data['search_interest'] ) ) {
			$out .= "  <series name=\"WordPress search interest (Google Trends, 0-100)\">\n";
			foreach ( $data['search_interest'] as $r ) {
				$out .= sprintf(
					"    <point period=\"%s\" value=\"%s\" confidence=\"%s\" />\n",
					$e( $r['period_date'] ), $e( $r['value'] ), $e( $r['confidence'] )
				);
			}
			$out .= "  </series>\n";
		}

		if ( ! empty( $data['builtwith'] ) ) {
			$out .= "  <series name=\"BuiltWith store counts\">\n";
			foreach ( $data['builtwith'] as $name => $rows ) {
				foreach ( $rows as $r ) {
					$out .= sprintf(
						"    <point platform=\"%s\" period=\"%s\" value=\"%s\" confidence=\"%s\" />\n",
						$e( $name ), $e( $r['period_date'] ), $e( $r['value'] ), $e( $r['confidence'] )
					);
				}
			}
			$out .= "  </series>\n";
		}

		$out .= "</data>\n\n";
		$out .= "<task>\n" . htmlspecialchars( $question, ENT_XML1, 'UTF-8' ) . "\n</task>";

		return $out;
	}

	private function build_chatgpt_prompt( array $data, string $question ): string {
		$context = "This data tracks WordPress and its competitors across CMS market share, plugin install\n"
			. "counts, search interest, and ecommerce store counts. All percentages are\n"
			. '"% of all websites" from W3Techs unless noted.' . "\n\n"
			. "Each series mixes data of varying confidence: live API reads, archived Wayback snapshots,\n"
			. 'bucketed install ranges (e.g. "50,000+"), and manual entries. Treat bucketed and manual'
			. "\nfigures as approximate, not exact, and weight conclusions accordingly.";

		$md  = "You are a market analyst. Analyze the following WordPress ecosystem data.\n\n";
		$md .= "## Context\n{$context}\n\n";

		// Build a combined CMS share table.
		// Collect all periods (wp_cms has all, others may be partial).
		$all_periods = [];
		foreach ( $data['wp_cms'] ?? [] as $r )    { $all_periods[ $r['period_date'] ] = true; }
		foreach ( $data['none_cms'] ?? [] as $r )   { $all_periods[ $r['period_date'] ] = true; }
		foreach ( $data['platform_cms'] ?? [] as $rows ) {
			foreach ( $rows as $r ) { $all_periods[ $r['period_date'] ] = true; }
		}
		ksort( $all_periods );
		$all_periods = array_keys( $all_periods );

		if ( $all_periods ) {
			// Index by period for quick lookups.
			$wp_idx   = array_column( $data['wp_cms'] ?? [],   null, 'period_date' );
			$none_idx = array_column( $data['none_cms'] ?? [], null, 'period_date' );

			$plat_idx = [];
			$plat_names = [];
			foreach ( $data['platform_cms'] ?? [] as $name => $rows ) {
				$plat_names[] = $name;
				$plat_idx[ $name ] = array_column( $rows, null, 'period_date' );
			}

			$headers = array_merge( [ 'Period', 'WordPress', 'None (no CMS)' ], $plat_names );
			$md .= "## CMS Market Share (% of all websites)\n\n";
			$md .= '| ' . implode( ' | ', $headers ) . " |\n";
			$md .= '| ' . str_repeat( '---------|', count( $headers ) ) . "\n";

			$fmt_cell = static function ( ?array $r ): string {
				if ( ! $r ) return '—';
				return number_format( (float) $r['value'], 1 ) . '% (' . $r['confidence'] . ')';
			};

			foreach ( $all_periods as $period ) {
				$row = [ $period, $fmt_cell( $wp_idx[ $period ] ?? null ), $fmt_cell( $none_idx[ $period ] ?? null ) ];
				foreach ( $plat_names as $name ) {
					$row[] = $fmt_cell( $plat_idx[ $name ][ $period ] ?? null );
				}
				$md .= '| ' . implode( ' | ', $row ) . " |\n";
			}
			$md .= "\n";
		}

		// Active installs.
		if ( ! empty( $data['wp_all_import_installs'] ) ) {
			$md .= "## Active Installs — WP All Import (bellwether)\n\n";
			$md .= "| Period | Installs | Confidence |\n|--------|----------|------------|\n";
			foreach ( $data['wp_all_import_installs'] as $r ) {
				$display = ! empty( $r['value_text'] ) ? $r['value_text'] : number_format( (float) $r['value'] );
				$md .= sprintf( "| %s | %s | %s |\n", $r['period_date'], $display, $r['confidence'] );
			}
			$md .= "\n";
		}

		// Search interest.
		if ( ! empty( $data['search_interest'] ) ) {
			$md .= "## Search Interest — WordPress (Google Trends, 0–100)\n\n";
			$md .= "| Period | Value | Confidence |\n|--------|-------|------------|\n";
			foreach ( $data['search_interest'] as $r ) {
				$md .= sprintf( "| %s | %d | %s |\n", $r['period_date'], (int) $r['value'], $r['confidence'] );
			}
			$md .= "\n";
		}

		// BuiltWith.
		if ( ! empty( $data['builtwith'] ) ) {
			$bw_names = array_keys( $data['builtwith'] );
			$md .= "## BuiltWith Store Counts\n\n";
			$md .= '| Period | ' . implode( ' | ', $bw_names ) . " |\n";
			$md .= '|--------|' . str_repeat( '---------|', count( $bw_names ) ) . "\n";

			$bw_idx  = [];
			$bw_pds  = [];
			foreach ( $data['builtwith'] as $name => $rows ) {
				$bw_idx[ $name ] = array_column( $rows, null, 'period_date' );
				foreach ( $rows as $r ) { $bw_pds[ $r['period_date'] ] = true; }
			}
			ksort( $bw_pds );
			foreach ( array_keys( $bw_pds ) as $period ) {
				$row = [ $period ];
				foreach ( $bw_names as $name ) {
					$r = $bw_idx[ $name ][ $period ] ?? null;
					$row[] = $r ? number_format( (float) $r['value'] ) . ' (' . $r['confidence'] . ')' : '—';
				}
				$md .= '| ' . implode( ' | ', $row ) . " |\n";
			}
			$md .= "\n";
		}

		$md .= "## Task\n\n" . $question;

		return $md;
	}

	private function render_clipboard_script(): void {
		?>
		<script>
		document.getElementById('smi-copy-prompt').addEventListener('click', function () {
			var btn = this;
			navigator.clipboard.writeText(document.getElementById('smi-prompt-output').value).then(function () {
				btn.textContent = 'Copied!';
				setTimeout(function () { btn.textContent = 'Copy to Clipboard'; }, 2000);
			});
		});
		</script>
		<?php
	}

	/**
	 * Sales data has no egress path by design — it is never exported to file
	 * and never placed in an AI prompt. This exclusion enforces that invariant.
	 */
	private function excluded_metric_keys(): array {
		return [
			'edd_new_gross', 'edd_new_count', 'edd_renewal_gross', 'edd_renewal_count',
			'edd_total_gross', 'edd_tax', 'edd_refund_amount', 'edd_refund_count',
			'edd_net', 'active_license_count',
		];
	}

	private function excl_where( string $alias = '' ): string {
		global $wpdb;
		$col  = $alias ? "{$alias}.metric_key" : 'metric_key';
		$keys = $this->excluded_metric_keys();
		$ph   = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->prepare( "{$col} NOT LIKE 'edd\\_%%' AND {$col} NOT IN ($ph)", ...$keys );
	}

	private function get_entities_for_ui(): array {
		global $wpdb;
		$rows    = $wpdb->get_results(
			"SELECT id, name, slug, type FROM `{$wpdb->prefix}smi_entities` ORDER BY type, name",
			ARRAY_A
		);
		$grouped = [ 'platform' => [], 'self' => [], 'competitor' => [] ];
		foreach ( $rows as $r ) {
			if ( isset( $grouped[ $r['type'] ] ) ) {
				$grouped[ $r['type'] ][] = $r;
			}
		}
		return $grouped;
	}

	private function get_metric_keys_for_ui(): array {
		global $wpdb;
		$excl = $this->excl_where();
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_col(
			"SELECT DISTINCT metric_key FROM `{$wpdb->prefix}smi_metrics` WHERE $excl ORDER BY metric_key"
		) ?: [];
	}

	private function render_export_form( array $entities, array $metric_keys ): void {
		$type_labels = [ 'platform' => 'Platforms', 'self' => 'Our Plugins', 'competitor' => 'Competitors' ];
		?>
		<h2>Export Data</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'smi_export' ); ?>
			<input type="hidden" name="action" value="smi_export" />

			<fieldset class="smi-fieldset">
				<legend>Format</legend>
				<?php foreach ( [ 'csv' => 'CSV', 'json' => 'JSON', 'xml' => 'XML' ] as $val => $label ) : ?>
					<label>
						<input type="radio" name="format" value="<?php echo esc_attr( $val ); ?>"
							<?php checked( $val, 'csv' ); ?>> <?php echo esc_html( $label ); ?>
					</label>&nbsp;&nbsp;
				<?php endforeach; ?>
			</fieldset>

			<fieldset class="smi-fieldset">
				<legend>Entities</legend>
				<?php foreach ( $type_labels as $type => $group_label ) : ?>
					<?php if ( ! empty( $entities[ $type ] ) ) : ?>
						<strong><?php echo esc_html( $group_label ); ?></strong><br>
						<?php foreach ( $entities[ $type ] as $entity ) : ?>
							<label>
								<input type="checkbox" name="entity_ids[]"
									value="<?php echo esc_attr( $entity['id'] ); ?>" checked>
								<?php echo esc_html( $entity['name'] ); ?>
							</label><br>
						<?php endforeach; ?>
						<br>
					<?php endif; ?>
				<?php endforeach; ?>
			</fieldset>

			<fieldset class="smi-fieldset">
				<legend>Metrics</legend>
				<?php foreach ( $metric_keys as $key ) : ?>
					<label>
						<input type="checkbox" name="metric_keys[]"
							value="<?php echo esc_attr( $key ); ?>" checked>
						<?php echo esc_html( $key ); ?>
					</label><br>
				<?php endforeach; ?>
			</fieldset>

			<fieldset class="smi-fieldset">
				<legend>Date Range</legend>
				<label>From: <input type="date" name="date_from"></label>&nbsp;&nbsp;
				<label>To: <input type="date" name="date_to"></label>
			</fieldset>

			<?php submit_button( 'Download Export' ); ?>
		</form>
		<?php
	}

	private function render_prompt_form( ?string $prompt_output ): void {
		$custom_q = isset( $_POST['custom_question'] ) ? sanitize_textarea_field( wp_unslash( $_POST['custom_question'] ) ) : '';
		?>
		<hr>
		<h2>AI Prompt Builder</h2>
		<form method="post">
			<?php wp_nonce_field( 'smi_build_prompt' ); ?>
			<input type="hidden" name="smi_action" value="build_prompt" />

			<fieldset class="smi-fieldset">
				<legend>AI Format</legend>
				<label>
					<input type="radio" name="ai_format" value="claude"
						<?php checked( wp_unslash( $_POST['ai_format'] ?? 'claude' ), 'claude' ); ?>> Claude (XML)
				</label>&nbsp;&nbsp;
				<label>
					<input type="radio" name="ai_format" value="chatgpt"
						<?php checked( wp_unslash( $_POST['ai_format'] ?? 'claude' ), 'chatgpt' ); ?>> ChatGPT (Markdown)
				</label>
			</fieldset>

			<fieldset class="smi-fieldset">
				<legend>Custom Question <span style="font-weight:normal;font-size:12px;">(leave blank for default)</span></legend>
				<textarea name="custom_question" rows="3" style="width:100%;"
					placeholder="Based on this data, is WordPress expanding, contracting, or stable as a platform? Identify the single strongest signal, note any contradictions between signals, and give a one-sentence verdict."
				><?php echo esc_textarea( $custom_q ); ?></textarea>
			</fieldset>

			<?php submit_button( 'Build Prompt' ); ?>
		</form>

		<?php if ( $prompt_output !== null ) : ?>
			<h3>Generated Prompt</h3>
			<textarea id="smi-prompt-output" rows="20" readonly><?php echo esc_textarea( $prompt_output ); ?></textarea>
			<br><button type="button" id="smi-copy-prompt" class="button button-secondary" style="margin-top:8px;">Copy to Clipboard</button>
			<?php $this->render_clipboard_script(); ?>
		<?php endif; ?>
		<?php
	}
}
