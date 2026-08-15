<?php
/**
 * Admin screens.
 *
 * Two of them: Settings, which points the gateway at the live tender API
 * without touching code, and API Data Flow, which shows the request, the raw
 * response, the field mapping and the stored records so the integration can be
 * walked through in front of stakeholders rather than merely asserted.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Admin {

	public static function init() {
		// Covers upgrades of an install that predates the tender table, where
		// the activation hook has already run and will not run again.
		add_action( 'admin_init', array( 'TG_Store', 'maybe_install' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_tg_save_settings', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_tg_sync_now', array( __CLASS__, 'sync_now' ) );
		add_action( 'admin_post_tg_clear_live', array( __CLASS__, 'clear_live' ) );
	}

	public static function menu() {
		add_menu_page(
			'Tender Gateway',
			'Tender Gateway',
			'manage_options',
			'tender-gateway',
			array( __CLASS__, 'settings_screen' ),
			'dashicons-portfolio',
			26
		);

		add_submenu_page( 'tender-gateway', 'Settings', 'Settings', 'manage_options', 'tender-gateway', array( __CLASS__, 'settings_screen' ) );
		add_submenu_page( 'tender-gateway', 'API Data Flow', 'API Data Flow', 'manage_options', 'tender-gateway-flow', array( __CLASS__, 'flow_screen' ) );
	}

	private static function redirect_back( $args, $page = 'tender-gateway' ) {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . $page ) ) );
		exit;
	}

	/**
	 * Known-good starting point for a TendersOnTime account. The endpoint and
	 * key still have to come from them; this just saves guessing at the rest.
	 */
	private static function tendersontime_preset() {
		return array(
			'format'       => 'auto',
			'auth_style'   => 'query',
			'auth_query_key' => 'api_key',
			'date_param'   => 'posting_date',
			'date_format'  => 'Y-m-d',
			'sync_days'    => 3,
			'results_path' => '',
			'map'          => array(
				'id'          => 'tender_id',
				'reference'   => 'tender_no',
				'title'       => 'tender_title',
				'buyer'       => 'organisation',
				'country'     => 'country',
				'sector'      => 'category',
				'summary'     => 'tender_brief',
				'description' => 'tender_description',
				'value'       => 'tender_value',
				'currency'    => 'currency',
				'published'   => 'posting_date',
				'deadline'    => 'closing_date',
				'method'      => 'tender_type',
				'source_name' => 'source',
			),
		);
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'tg_save_settings' );

		$post     = wp_unslash( $_POST );
		$settings = tg_settings();

		if ( ! empty( $post['apply_preset'] ) ) {
			$preset   = self::tendersontime_preset();
			$settings = array_merge( $settings, $preset );
			$settings['map'] = array_merge( $settings['map'], $preset['map'] );

			update_option( 'tg_settings', $settings );
			self::redirect_back( array( 'preset' => 1 ) );
		}

		$map = array();
		foreach ( array_keys( tg_default_settings()['map'] ) as $field ) {
			$map[ $field ] = isset( $post['map'][ $field ] ) ? sanitize_text_field( $post['map'][ $field ] ) : '';
		}

		$was_live = 'remote' === $settings['source'];
		$now_live = isset( $post['source'] ) && 'remote' === $post['source'];

		update_option( 'tg_settings', array(
			'source'         => $now_live ? 'remote' : 'sample',
			// esc_url_raw strips the {date} placeholder's braces, so keep the raw
			// string and only validate that it is an http(s) URL.
			'endpoint'       => isset( $post['endpoint'] ) ? self::clean_endpoint( $post['endpoint'] ) : '',
			'api_key'        => isset( $post['api_key'] ) ? sanitize_text_field( $post['api_key'] ) : '',
			'auth_style'     => isset( $post['auth_style'] ) && in_array( $post['auth_style'], array( 'bearer', 'header', 'query' ), true ) ? $post['auth_style'] : 'bearer',
			'auth_header'    => isset( $post['auth_header'] ) ? sanitize_text_field( $post['auth_header'] ) : 'X-API-Key',
			'auth_query_key' => isset( $post['auth_query_key'] ) ? sanitize_text_field( $post['auth_query_key'] ) : 'api_key',
			'format'         => isset( $post['format'] ) && in_array( $post['format'], array( 'auto', 'json', 'xml' ), true ) ? $post['format'] : 'auto',
			'results_path'   => isset( $post['results_path'] ) ? sanitize_text_field( $post['results_path'] ) : '',
			'date_param'     => isset( $post['date_param'] ) ? sanitize_text_field( $post['date_param'] ) : 'posting_date',
			'date_format'    => isset( $post['date_format'] ) ? sanitize_text_field( $post['date_format'] ) : 'Y-m-d',
			'sync_days'      => isset( $post['sync_days'] ) ? max( 1, min( 30, (int) $post['sync_days'] ) ) : 3,
			'cache_minutes'  => isset( $post['cache_minutes'] ) ? max( 1, (int) $post['cache_minutes'] ) : 30,
			'map'            => $map,
		) );

		if ( $now_live ) {
			TG_Store::maybe_install();
			TG_Sync::activate_schedule();
		} elseif ( $was_live ) {
			TG_Sync::clear_schedule();
		}

		self::redirect_back( array( 'saved' => 1 ) );
	}

	private static function clean_endpoint( $url ) {
		$url = trim( wp_strip_all_tags( $url ) );

		if ( '' === $url ) {
			return '';
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		return in_array( $scheme, array( 'http', 'https' ), true ) ? $url : '';
	}

	public static function sync_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'tg_sync_now' );

		$settings = tg_settings();

		if ( empty( $settings['endpoint'] ) ) {
			set_transient( 'tg_flash', array( 'ok' => false, 'message' => 'No API endpoint configured yet.' ), 60 );
			self::redirect_back( array(), 'tender-gateway-flow' );
		}

		$days = isset( $_POST['days'] ) ? (int) $_POST['days'] : (int) $settings['sync_days'];
		$log  = TG_Sync::run( $days, 'manual' );

		set_transient( 'tg_flash', array(
			'ok'      => ! empty( $log['ok'] ),
			'message' => empty( $log['ok'] )
				? 'Sync failed: ' . ( isset( $log['error'] ) ? $log['error'] : 'unknown error' )
				: sprintf(
					'Sync complete. %d received, %d new, %d updated. %d tenders stored.',
					$log['totals']['received'],
					$log['totals']['inserted'],
					$log['totals']['updated'],
					$log['stored']
				),
		), 60 );

		self::redirect_back( array(), 'tender-gateway-flow' );
	}

	public static function clear_live() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'tg_clear_live' );

		$removed = TG_Store::clear( 'live' );

		set_transient( 'tg_flash', array( 'ok' => true, 'message' => sprintf( '%d synced tenders removed.', $removed ) ), 60 );
		self::redirect_back( array(), 'tender-gateway-flow' );
	}

	private static function flash() {
		$flash = get_transient( 'tg_flash' );

		if ( $flash ) {
			delete_transient( 'tg_flash' );
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				empty( $flash['ok'] ) ? 'error' : 'success',
				esc_html( $flash['message'] )
			);
		}
	}

	/* ------------------------------------------------------------ settings */

	public static function settings_screen() {
		$settings = tg_settings();
		?>
		<div class="wrap">
			<h1>Tender Gateway settings</h1>

			<?php self::flash(); ?>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['preset'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>TendersOnTime defaults applied. Add the endpoint and key, then run a sync from the API Data Flow screen and correct any field names that did not line up.</p></div>
			<?php endif; ?>

			<?php self::status_panel( $settings ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tg_save_settings">
				<?php wp_nonce_field( 'tg_save_settings' ); ?>

				<h2>Tender source</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Data source</th>
						<td>
							<label><input type="radio" name="source" value="sample" <?php checked( $settings['source'], 'sample' ); ?>> Bundled demonstration tenders</label><br>
							<label><input type="radio" name="source" value="remote" <?php checked( $settings['source'], 'remote' ); ?>> Live tender API (TendersOnTime)</label>
							<p class="description">Switching to live turns on the 3-hourly sync. Until the first sync brings records in, the demonstration feed keeps the site populated.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tg-endpoint">API endpoint</label></th>
						<td>
							<input type="text" class="large-text code" id="tg-endpoint" name="endpoint" value="<?php echo esc_attr( $settings['endpoint'] ); ?>" placeholder="https://api.tendersontime.com/v1/tenders">
							<p class="description">If the posting date belongs in the path rather than the query string, put <code>{date}</code> where it goes and it will be substituted.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tg-key">API key</label></th>
						<td>
							<input type="text" class="regular-text code" id="tg-key" name="api_key" value="<?php echo esc_attr( $settings['api_key'] ); ?>" autocomplete="off">
							<p class="description">Masked everywhere it would otherwise be printed, including the data flow screen.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Authentication</th>
						<td>
							<select name="auth_style">
								<option value="query" <?php selected( $settings['auth_style'], 'query' ); ?>>Query string parameter</option>
								<option value="bearer" <?php selected( $settings['auth_style'], 'bearer' ); ?>>Authorization: Bearer &lt;key&gt;</option>
								<option value="header" <?php selected( $settings['auth_style'], 'header' ); ?>>Custom request header</option>
							</select>
							<p>
								Header name: <input type="text" class="code" name="auth_header" value="<?php echo esc_attr( $settings['auth_header'] ); ?>">
								&nbsp; Query parameter: <input type="text" class="code" name="auth_query_key" value="<?php echo esc_attr( $settings['auth_query_key'] ); ?>">
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Response format</th>
						<td>
							<select name="format">
								<option value="auto" <?php selected( $settings['format'], 'auto' ); ?>>Detect automatically</option>
								<option value="json" <?php selected( $settings['format'], 'json' ); ?>>JSON</option>
								<option value="xml" <?php selected( $settings['format'], 'xml' ); ?>>XML</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tg-path">Results path</label></th>
						<td>
							<input type="text" class="regular-text code" id="tg-path" name="results_path" value="<?php echo esc_attr( $settings['results_path'] ); ?>" placeholder="data.tenders">
							<p class="description">Dot path to the array of tenders inside the response. Leave blank to auto-detect a bare list or a <code>tenders</code> / <code>data</code> / <code>results</code> / <code>items</code> wrapper.</p>
						</td>
					</tr>
				</table>

				<h2>Synchronisation</h2>
				<p class="description" style="max-width:70em;">
					TendersOnTime accepts one request filter, the tender posting date, and refreshes its backend every 3 hours.
					So the gateway walks a short window of posting dates on a 3-hourly schedule and keeps the records locally.
					Search and filtering then run against this site's own database, and nothing a visitor does triggers a
					third-party request.
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tg-date-param">Date parameter</label></th>
						<td><input type="text" class="regular-text code" id="tg-date-param" name="date_param" value="<?php echo esc_attr( $settings['date_param'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="tg-date-format">Date format</label></th>
						<td>
							<input type="text" class="regular-text code" id="tg-date-format" name="date_format" value="<?php echo esc_attr( $settings['date_format'] ); ?>">
							<p class="description">PHP date format. <code>Y-m-d</code> produces <?php echo esc_html( gmdate( 'Y-m-d' ) ); ?>; <code>d-m-Y</code> produces <?php echo esc_html( gmdate( 'd-m-Y' ) ); ?>.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tg-sync-days">Posting dates per sync</label></th>
						<td>
							<input type="number" min="1" max="30" id="tg-sync-days" name="sync_days" value="<?php echo esc_attr( $settings['sync_days'] ); ?>" class="small-text">
							<p class="description">How many days back from today to walk on each run. 3 covers a weekend without re-pulling the archive every time.</p>
						</td>
					</tr>
				</table>

				<h2>Field mapping</h2>
				<p class="description">Which key in each API record supplies each field on the gateway. Dot notation is supported, e.g. <code>authority.name</code>.</p>
				<table class="form-table" role="presentation">
					<?php
					$labels = array(
						'id'          => 'Tender ID',
						'reference'   => 'Reference number',
						'title'       => 'Title',
						'buyer'       => 'Contracting authority',
						'country'     => 'Country',
						'sector'      => 'Sector',
						'summary'     => 'Short summary',
						'description' => 'Full description',
						'value'       => 'Estimated value',
						'currency'    => 'Currency',
						'published'   => 'Published date',
						'deadline'    => 'Deadline date',
						'method'      => 'Procurement method',
						'source_name' => 'Source portal',
					);
					foreach ( $labels as $field => $label ) :
						?>
						<tr>
							<th scope="row"><label for="tg-map-<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td><input type="text" class="regular-text code" id="tg-map-<?php echo esc_attr( $field ); ?>" name="map[<?php echo esc_attr( $field ); ?>]" value="<?php echo esc_attr( $settings['map'][ $field ] ); ?>"></td>
						</tr>
					<?php endforeach; ?>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary">Save settings</button>
					<button type="submit" name="apply_preset" value="1" class="button">Apply TendersOnTime defaults</button>
				</p>
				<p class="description">The preset fills in the sync and mapping fields with sensible TendersOnTime names. Run one sync, then correct anything that did not line up against the raw response on the API Data Flow screen.</p>
			</form>
		</div>
		<?php
	}

	private static function status_panel( $settings ) {
		$live_count   = TG_Store::count( 'live' );
		$last_synced  = TG_Store::last_synced( 'live' );
		$next_run     = TG_Sync::next_run();
		$is_live      = 'remote' === $settings['source'];
		?>
		<div class="notice notice-info" style="padding:12px 14px;">
			<p style="margin:0 0 6px;">
				<strong>Showing:</strong>
				<?php if ( $is_live && $live_count ) : ?>
					live tenders from the API &mdash; <?php echo esc_html( number_format_i18n( $live_count ) ); ?> stored
				<?php elseif ( $is_live ) : ?>
					live source selected, but nothing synced yet &mdash; the demonstration feed is standing in
				<?php else : ?>
					bundled demonstration tenders (<?php echo esc_html( number_format_i18n( count( TG_Sample_Data::tenders() ) ) ); ?>)
				<?php endif; ?>
			</p>
			<p style="margin:0;">
				<strong>Last sync:</strong> <?php echo $last_synced ? esc_html( human_time_diff( $last_synced ) . ' ago' ) : 'never'; ?>
				&nbsp;&middot;&nbsp;
				<strong>Next scheduled:</strong> <?php echo $next_run ? esc_html( human_time_diff( time(), $next_run ) . ' from now' ) : 'not scheduled'; ?>
				&nbsp;&middot;&nbsp;
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=tender-gateway-flow' ) ); ?>">Open API Data Flow</a>
			</p>
		</div>
		<?php
	}

	/* ----------------------------------------------------------- data flow */

	public static function flow_screen() {
		$settings = tg_settings();
		$log      = TG_Sync::last_log();
		?>
		<div class="wrap">
			<h1>API Data Flow</h1>
			<p style="max-width:70em;">How a tender travels from TendersOnTime to a page on this website. Each step below shows what actually happened on the last sync, not an illustration.</p>

			<?php self::flash(); ?>

			<p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
					<input type="hidden" name="action" value="tg_sync_now">
					<input type="hidden" name="days" value="<?php echo esc_attr( $settings['sync_days'] ); ?>">
					<?php wp_nonce_field( 'tg_sync_now' ); ?>
					<button type="submit" class="button button-primary">Sync now</button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin-left:6px;">
					<input type="hidden" name="action" value="tg_clear_live">
					<?php wp_nonce_field( 'tg_clear_live' ); ?>
					<button type="submit" class="button">Clear synced tenders</button>
				</form>
				<a class="button" style="margin-left:6px;" href="<?php echo esc_url( admin_url( 'admin.php?page=tender-gateway' ) ); ?>">Settings</a>
			</p>

			<?php if ( ! $log ) : ?>
				<div class="notice notice-warning"><p>No sync has run yet. Configure the endpoint and key on the Settings screen, then press <strong>Sync now</strong>.</p></div>
				<?php
				echo '</div>';

				return;
			endif;
			?>

			<p>
				Last run <strong><?php echo esc_html( human_time_diff( $log['time'] ) ); ?> ago</strong>
				(<?php echo esc_html( isset( $log['trigger'] ) ? $log['trigger'] : 'scheduled' ); ?>)
				&mdash;
				<?php if ( empty( $log['ok'] ) ) : ?>
					<span style="color:#b32d2e;font-weight:600;">failed</span>
				<?php else : ?>
					<span style="color:#1a7f37;font-weight:600;">succeeded</span>
				<?php endif; ?>
			</p>

			<?php if ( ! empty( $log['error'] ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $log['error'] ); ?></p></div>
			<?php endif; ?>

			<h2>Step 1 &mdash; The request</h2>
			<p class="description">One request per posting date. The API key is masked.</p>
			<table class="widefat striped" style="max-width:none;">
				<thead>
					<tr><th>Posting date</th><th>Request URL</th><th>Status</th><th>Time</th><th>Size</th><th>Records</th></tr>
				</thead>
				<tbody>
				<?php foreach ( (array) ( isset( $log['requests'] ) ? $log['requests'] : array() ) as $request ) : ?>
					<tr>
						<td><code><?php echo esc_html( $request['date'] ); ?></code></td>
						<td style="word-break:break-all;"><code><?php echo esc_html( $request['url'] ); ?></code></td>
						<td>
							<?php
							$status = isset( $request['status'] ) ? (int) $request['status'] : 0;
							$colour = ( $status >= 200 && $status < 300 ) ? '#1a7f37' : '#b32d2e';
							printf( '<strong style="color:%s;">%s</strong>', esc_attr( $colour ), esc_html( $status ? $status : 'no response' ) );
							?>
						</td>
						<td><?php echo esc_html( isset( $request['ms'] ) ? $request['ms'] . ' ms' : '-' ); ?></td>
						<td><?php echo esc_html( isset( $request['bytes'] ) ? size_format( $request['bytes'] ) : '-' ); ?></td>
						<td><?php echo esc_html( isset( $request['records'] ) ? $request['records'] : '-' ); ?></td>
					</tr>
					<?php if ( ! empty( $request['excerpt'] ) ) : ?>
						<tr><td colspan="6"><strong>Unparsed response begins:</strong> <code><?php echo esc_html( $request['excerpt'] ); ?></code></td></tr>
					<?php endif; ?>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h2>Step 2 &mdash; The raw record that came back</h2>
			<?php if ( empty( $log['first_raw'] ) ) : ?>
				<p>No records were returned, so there is nothing to show here.</p>
			<?php else : ?>
				<p class="description">The first tender in the response, exactly as TendersOnTime sent it.</p>
				<pre style="background:#1d2327;color:#e6edf3;padding:14px;border-radius:4px;overflow:auto;max-height:360px;"><?php echo esc_html( wp_json_encode( $log['first_raw'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre>
			<?php endif; ?>

			<h2>Step 3 &mdash; How each field was mapped</h2>
			<?php if ( empty( $log['first_raw'] ) ) : ?>
				<p>Nothing to map until a record comes back.</p>
			<?php else : ?>
				<?php
				$mapped = TG_API::normalise( $log['first_raw'], $settings['map'] );
				$labels = array(
					'id'          => 'Tender ID',
					'reference'   => 'Reference number',
					'title'       => 'Title',
					'buyer'       => 'Contracting authority',
					'country'     => 'Country',
					'sector'      => 'Sector',
					'summary'     => 'Short summary',
					'value'       => 'Estimated value',
					'currency'    => 'Currency',
					'published'   => 'Published date',
					'deadline'    => 'Deadline date',
					'method'      => 'Procurement method',
					'source_name' => 'Source portal',
				);
				?>
				<table class="widefat striped" style="max-width:none;">
					<thead>
						<tr><th style="width:22%;">Website field</th><th style="width:24%;">Reads API key</th><th>Value it produced</th></tr>
					</thead>
					<tbody>
					<?php foreach ( $labels as $field => $label ) : ?>
						<?php
						$key   = isset( $settings['map'][ $field ] ) ? $settings['map'][ $field ] : $field;
						$value = isset( $mapped[ $field ] ) ? $mapped[ $field ] : '';
						$empty = ( '' === $value || 0 === $value || '0' === $value );
						?>
						<tr>
							<td><strong><?php echo esc_html( $label ); ?></strong></td>
							<td><code><?php echo esc_html( $key ); ?></code></td>
							<td<?php echo $empty ? ' style="color:#b32d2e;"' : ''; ?>>
								<?php echo $empty ? 'empty &mdash; check this mapping' : esc_html( wp_trim_words( (string) $value, 24, '...' ) ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2>Step 4 &mdash; What was stored</h2>
			<table class="widefat striped" style="max-width:52em;">
				<tbody>
					<tr><td>Records received</td><td><strong><?php echo esc_html( isset( $log['totals']['received'] ) ? $log['totals']['received'] : 0 ); ?></strong></td></tr>
					<tr><td>New tenders inserted</td><td><strong><?php echo esc_html( isset( $log['totals']['inserted'] ) ? $log['totals']['inserted'] : 0 ); ?></strong></td></tr>
					<tr><td>Existing tenders updated</td><td><strong><?php echo esc_html( isset( $log['totals']['updated'] ) ? $log['totals']['updated'] : 0 ); ?></strong></td></tr>
					<tr><td>Skipped (no usable ID)</td><td><strong><?php echo esc_html( isset( $log['totals']['skipped'] ) ? $log['totals']['skipped'] : 0 ); ?></strong></td></tr>
					<tr><td>Total tenders now in the local database</td><td><strong><?php echo esc_html( TG_Store::count( 'live' ) ); ?></strong></td></tr>
				</tbody>
			</table>

			<h2>Step 5 &mdash; On the website</h2>
			<p>
				Visitors browse and filter these records without any request reaching TendersOnTime.
				<a class="button" href="<?php echo esc_url( tg_page_url( 'tenders' ) ); ?>" target="_blank" rel="noopener">Open the tender listing</a>
			</p>
		</div>
		<?php
	}
}
