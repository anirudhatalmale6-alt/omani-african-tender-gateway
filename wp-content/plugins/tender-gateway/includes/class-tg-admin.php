<?php
/**
 * Settings screen: point the gateway at the real tender API without touching code.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_tg_save_settings', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_tg_test_api', array( __CLASS__, 'test' ) );
	}

	public static function menu() {
		add_menu_page(
			'Tender Gateway',
			'Tender Gateway',
			'manage_options',
			'tender-gateway',
			array( __CLASS__, 'screen' ),
			'dashicons-portfolio',
			26
		);
	}

	private static function redirect_back( $args ) {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=tender-gateway' ) ) );
		exit;
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'tg_save_settings' );

		$post = wp_unslash( $_POST );
		$map  = array();

		foreach ( array_keys( tg_default_settings()['map'] ) as $field ) {
			$map[ $field ] = isset( $post['map'][ $field ] ) ? sanitize_text_field( $post['map'][ $field ] ) : '';
		}

		update_option( 'tg_settings', array(
			'source'         => isset( $post['source'] ) && 'remote' === $post['source'] ? 'remote' : 'sample',
			'endpoint'       => isset( $post['endpoint'] ) ? esc_url_raw( trim( $post['endpoint'] ) ) : '',
			'api_key'        => isset( $post['api_key'] ) ? sanitize_text_field( $post['api_key'] ) : '',
			'auth_style'     => isset( $post['auth_style'] ) && in_array( $post['auth_style'], array( 'bearer', 'header', 'query' ), true ) ? $post['auth_style'] : 'bearer',
			'auth_header'    => isset( $post['auth_header'] ) ? sanitize_text_field( $post['auth_header'] ) : 'X-API-Key',
			'auth_query_key' => isset( $post['auth_query_key'] ) ? sanitize_text_field( $post['auth_query_key'] ) : 'api_key',
			'results_path'   => isset( $post['results_path'] ) ? sanitize_text_field( $post['results_path'] ) : '',
			'cache_minutes'  => isset( $post['cache_minutes'] ) ? max( 1, (int) $post['cache_minutes'] ) : 30,
			'map'            => $map,
		) );

		delete_transient( TG_API::CACHE_KEY );

		self::redirect_back( array( 'saved' => 1 ) );
	}

	public static function test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'tg_test_api' );

		$settings = tg_settings();

		if ( empty( $settings['endpoint'] ) ) {
			set_transient( 'tg_test_result', array( 'ok' => false, 'message' => 'No endpoint configured yet.' ), 60 );
			self::redirect_back( array( 'tested' => 1 ) );
		}

		$result = TG_API::fetch_remote( $settings );

		if ( is_wp_error( $result ) ) {
			set_transient( 'tg_test_result', array( 'ok' => false, 'message' => $result->get_error_message() ), 60 );
		} else {
			$sample  = $result ? $result[0] : array();
			$message = sprintf( 'Connected. %d tenders returned.', count( $result ) );
			if ( $sample ) {
				$message .= ' First record mapped to: "' . ( $sample['title'] ? $sample['title'] : '(empty title - check your field mapping)' ) . '".';
			}
			set_transient( 'tg_test_result', array( 'ok' => true, 'message' => $message ), 60 );
			delete_transient( TG_API::CACHE_KEY );
		}

		self::redirect_back( array( 'tested' => 1 ) );
	}

	public static function screen() {
		$settings = tg_settings();
		$test     = get_transient( 'tg_test_result' );
		if ( $test ) {
			delete_transient( 'tg_test_result' );
		}
		$last_sync = (int) get_option( 'tg_last_sync', 0 );
		?>
		<div class="wrap">
			<h1>Tender Gateway</h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Settings saved and the tender cache was cleared.</p></div>
			<?php endif; ?>

			<?php if ( $test ) : ?>
				<div class="notice notice-<?php echo $test['ok'] ? 'success' : 'error'; ?>"><p><?php echo esc_html( $test['message'] ); ?></p></div>
			<?php endif; ?>

			<p>
				Currently showing <strong><?php echo 'remote' === $settings['source'] ? 'live API data' : 'bundled demonstration tenders'; ?></strong>
				&mdash; <?php echo esc_html( number_format_i18n( count( TG_API::all() ) ) ); ?> tenders.
				<?php if ( $last_sync ) : ?>
					Last successful sync: <?php echo esc_html( date_i18n( 'j M Y H:i', $last_sync ) ); ?>.
				<?php endif; ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tg_save_settings">
				<?php wp_nonce_field( 'tg_save_settings' ); ?>

				<h2>Tender source</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Data source</th>
						<td>
							<label><input type="radio" name="source" value="sample" <?php checked( $settings['source'], 'sample' ); ?>> Bundled demonstration tenders</label><br>
							<label><input type="radio" name="source" value="remote" <?php checked( $settings['source'], 'remote' ); ?>> Live tender API</label>
							<p class="description">Switch to the live API once the endpoint and key below are filled in.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tg-endpoint">API endpoint</label></th>
						<td><input type="url" class="regular-text code" id="tg-endpoint" name="endpoint" value="<?php echo esc_attr( $settings['endpoint'] ); ?>" placeholder="https://api.example.com/v1/tenders"></td>
					</tr>
					<tr>
						<th scope="row"><label for="tg-key">API key</label></th>
						<td><input type="text" class="regular-text code" id="tg-key" name="api_key" value="<?php echo esc_attr( $settings['api_key'] ); ?>" autocomplete="off"></td>
					</tr>
					<tr>
						<th scope="row">Authentication</th>
						<td>
							<select name="auth_style">
								<option value="bearer" <?php selected( $settings['auth_style'], 'bearer' ); ?>>Authorization: Bearer &lt;key&gt;</option>
								<option value="header" <?php selected( $settings['auth_style'], 'header' ); ?>>Custom request header</option>
								<option value="query" <?php selected( $settings['auth_style'], 'query' ); ?>>Query string parameter</option>
							</select>
							<p>
								Header name: <input type="text" class="code" name="auth_header" value="<?php echo esc_attr( $settings['auth_header'] ); ?>">
								&nbsp; Query parameter: <input type="text" class="code" name="auth_query_key" value="<?php echo esc_attr( $settings['auth_query_key'] ); ?>">
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tg-path">Results path</label></th>
						<td>
							<input type="text" class="regular-text code" id="tg-path" name="results_path" value="<?php echo esc_attr( $settings['results_path'] ); ?>" placeholder="data.tenders">
							<p class="description">Dot path to the array of tenders inside the response. Leave blank to auto-detect a bare list or a <code>data</code>/<code>results</code>/<code>items</code> wrapper.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tg-cache">Cache for</label></th>
						<td><input type="number" min="1" id="tg-cache" name="cache_minutes" value="<?php echo esc_attr( $settings['cache_minutes'] ); ?>" class="small-text"> minutes</td>
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

				<?php submit_button( 'Save settings' ); ?>
			</form>

			<hr>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tg_test_api">
				<?php wp_nonce_field( 'tg_test_api' ); ?>
				<?php submit_button( 'Test API connection', 'secondary', 'submit', false ); ?>
				<span class="description" style="margin-left:8px;">Calls the endpoint with the saved settings and reports what came back.</span>
			</form>
		</div>
		<?php
	}
}
