<?php
/**
 * Admin screen and AJAX endpoints.
 *
 * @package WPFH_Search_Replace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPFH_BSR_Admin {

	const PAGE = 'forgehub-search-replace';

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WPFH_BSR_FILE ), array( $this, 'add_action_links' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wpfh_bsr_tables', array( $this, 'ajax_tables' ) );
		add_action( 'wp_ajax_wpfh_bsr_batch', array( $this, 'ajax_batch' ) );

		if ( is_admin() && class_exists( 'WPFH_Review_Nudge' ) ) {
			$nudge = new WPFH_Review_Nudge(
				array(
					'plugin_slug'   => 'forgehub-search-replace',
					'plugin_name'   => 'ForgeHub Search & Replace',
					'option_prefix' => 'wpfh_bsr',
					'wporg_slug'    => 'forgehub-search-replace',
					'github_url'    => 'https://github.com/WpForgeHub/forgehub-search-replace',
					'min_actions'   => 2,
					'min_days'      => 7,
					'trigger_mode'  => 'or',
					'screens'       => array( 'tools_page_forgehub-search-replace', 'plugins' ),
					'strings'       => array(
						/* translators: %s: Plugin name. */
						'heading'     => sprintf( __( 'Enjoying %s?', 'forgehub-search-replace' ), 'ForgeHub Search & Replace' ),
						/* translators: %s: Plugin name. */
						'message'     => sprintf( __( 'If %s has saved you time on database migrations, a quick 5-star review helps other site owners discover it and keeps development active.', 'forgehub-search-replace' ), 'ForgeHub Search & Replace' ),
						'rate_cta'    => __( 'Rate 5 Stars on WordPress.org', 'forgehub-search-replace' ),
						'github_cta'  => __( 'Star on GitHub', 'forgehub-search-replace' ),
						'later_cta'   => __( 'Maybe later', 'forgehub-search-replace' ),
						'dismiss_cta' => __( 'Already did / Dismiss', 'forgehub-search-replace' ),
					),
				)
			);
			$nudge->register();
		}
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function add_action_links( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'tools.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Settings', 'forgehub-search-replace' ) . '</a>';
		if ( ! wpfh_bsr_is_pro() ) {
			$pro_link = '<a href="' . esc_url( wpfh_bsr_fs()->get_upgrade_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Priority Support & Pro', 'forgehub-search-replace' ) . '</a>';
			array_unshift( $links, $settings_link, $pro_link );
		} else {
			array_unshift( $links, $settings_link );
		}
		return $links;
	}

	public function register_menu() {
		add_management_page(
			esc_html__( 'Search & Replace', 'forgehub-search-replace' ),
			esc_html__( 'Search & Replace', 'forgehub-search-replace' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	public function enqueue_assets( $hook ) {
		if ( 'tools_page_' . self::PAGE !== $hook ) {
			return;
		}
		$css = WPFH_BSR_PATH . 'assets/css/admin.css';
		$js  = WPFH_BSR_PATH . 'assets/js/admin.js';
		wp_enqueue_style( 'wpfh-bsr-admin', WPFH_BSR_URL . 'assets/css/admin.css', array(), (string) filemtime( $css ) );
		wp_enqueue_script( 'wpfh-bsr-admin', WPFH_BSR_URL . 'assets/js/admin.js', array(), (string) filemtime( $js ), true );
		wp_localize_script(
			'wpfh-bsr-admin',
			'wpfhBsr',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wpfh_bsr_ajax' ),
				'isPro'   => wpfh_bsr_is_pro(),
				'i18n'    => array(
					'running'     => __( 'Scanning…', 'forgehub-search-replace' ),
					'dryDone'     => __( 'Dry run complete. Nothing was changed. Review the preview, then run the replacement.', 'forgehub-search-replace' ),
					'liveDone'    => __( 'Replacement complete.', 'forgehub-search-replace' ),
					'needSearch'  => __( 'Enter a search string first.', 'forgehub-search-replace' ),
					'confirmLive' => __( 'This will permanently modify your database. Make sure you have a backup. Continue?', 'forgehub-search-replace' ),
					'error'       => __( 'Request failed. Please try again.', 'forgehub-search-replace' ),
					'noMatches'   => __( 'No matches found.', 'forgehub-search-replace' ),
					'tables'      => __( 'Tables', 'forgehub-search-replace' ),
				),
			)
		);
	}

	/**
	 * Verify capability and nonce for AJAX requests.
	 */
	private function guard() {
		check_ajax_referer( 'wpfh_bsr_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized.', 'forgehub-search-replace' ) ), 403 );
		}
	}

	/**
	 * Clean a free-form string. Search/replace values are arbitrary data (HTML, URLs), so tags are kept;
	 * only invalid UTF-8 and NUL bytes are removed.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private function clean_string( $value ) {
		$value = wp_check_invalid_utf8( (string) $value );
		return str_replace( "\0", '', $value );
	}

	/**
	 * Read and normalise the request parameters.
	 *
	 * @return array
	 */
	private function read_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in guard().
		$search  = isset( $_POST['search'] ) ? $this->clean_string( wp_unslash( $_POST['search'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see clean_string().
		$replace = isset( $_POST['replace'] ) ? $this->clean_string( wp_unslash( $_POST['replace'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see clean_string().

		$tables  = array();
		$columns = array();
		if ( wpfh_bsr_is_pro() ) {
			if ( ! empty( $_POST['tables'] ) && is_array( $_POST['tables'] ) ) {
				$tables = array_values( array_filter( array_map( 'sanitize_text_field', wp_unslash( $_POST['tables'] ) ) ) );
			}
			if ( ! empty( $_POST['columns'] ) && is_array( $_POST['columns'] ) ) {
				foreach ( wp_unslash( $_POST['columns'] ) as $table => $cols ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
					if ( is_array( $cols ) ) {
						$columns[ sanitize_text_field( $table ) ] = array_values( array_filter( array_map( 'sanitize_text_field', $cols ) ) );
					}
				}
			}
		}

		$run_id = isset( $_POST['run_id'] ) ? preg_replace( '/[^a-f0-9\-]/', '', sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) ) : '';

		$p = array(
			'search'          => $search,
			'replace'         => $replace,
			'ci'              => ! empty( $_POST['ci'] ),
			'guid'            => ! empty( $_POST['guid'] ),
			'dry'             => ! empty( $_POST['dry'] ),
			'tables'          => $tables,
			'tables_explicit' => wpfh_bsr_is_pro() && ! empty( $_POST['tables_explicit'] ),
			'columns'         => $columns,
			'table_index'     => isset( $_POST['table_index'] ) ? absint( $_POST['table_index'] ) : 0,
			'offset'          => isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0,
			'run_id'          => $run_id,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return $p;
	}

	/**
	 * List tables and their replaceable columns (used by the Pro selector and the summary).
	 */
	public function ajax_tables() {
		$this->guard();
		$out = array();
		foreach ( WPFH_BSR_Engine::list_tables() as $table ) {
			$info  = WPFH_BSR_Engine::describe_table( $table, false );
			$out[] = array(
				'name'    => $table,
				'pk'      => $info['pk'],
				'columns' => $info['columns'],
			);
		}
		wp_send_json_success( array( 'tables' => $out ) );
	}

	/**
	 * Process one batch. A live run is refused unless a dry run with identical parameters completed first.
	 */
	public function ajax_batch() {
		$this->guard();
		$p = $this->read_request();

		if ( '' === $p['search'] ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Search string is empty.', 'forgehub-search-replace' ) ), 400 );
		}

		if ( ! empty( $p['tables_explicit'] ) && empty( $p['tables'] ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Select at least one table first.', 'forgehub-search-replace' ) ), 400 );
		}

		$fp = WPFH_BSR_Engine::fingerprint( $p );

		if ( empty( $p['dry'] ) ) {
			if ( get_transient( WPFH_BSR_Engine::proof_key() ) !== $fp ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Run a dry run with the same settings first.', 'forgehub-search-replace' ) ), 409 );
			}
			if ( '' === $p['run_id'] ) {
				$p['run_id'] = wp_generate_uuid4();
			}
		}

		$result           = WPFH_BSR_Engine::process_batch( $p );
		$result['run_id'] = $p['run_id'];

		if ( $result['done'] ) {
			if ( ! empty( $p['dry'] ) ) {
				set_transient( WPFH_BSR_Engine::proof_key(), $fp, HOUR_IN_SECONDS );
			} else {
				delete_transient( WPFH_BSR_Engine::proof_key() );
				// Object caches may hold stale rows after a rewrite.
				wp_cache_flush();
				if ( class_exists( 'WPFH_Review_Nudge' ) ) {
					WPFH_Review_Nudge::track_success( 'wpfh_bsr' );
				}
			}
		}

		wp_send_json_success( $result );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'forgehub-search-replace' ) );
		}
		$is_pro = wpfh_bsr_is_pro();
		?>
		<div class="wrap wpfh-bsr" id="wpfh-bsr-app">
			<h1><?php esc_html_e( 'ForgeHub Search & Replace', 'forgehub-search-replace' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Serialize-safe database search and replace. Always preview with a dry run first; the live run unlocks only after a dry run with identical settings.', 'forgehub-search-replace' ); ?></p>

			<div class="notice notice-warning inline"><p>
				<strong><?php esc_html_e( 'Back up your database before running a live replacement.', 'forgehub-search-replace' ); ?></strong>
				<?php
				if ( ! $is_pro ) {
					esc_html_e( 'Pro creates a restorable backup of every changed value automatically.', 'forgehub-search-replace' );
				}
				?>
			</p></div>

			<div class="wpfh-bsr-card">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wpfh-bsr-search"><?php esc_html_e( 'Search for', 'forgehub-search-replace' ); ?></label></th>
						<td><input type="text" id="wpfh-bsr-search" class="large-text" autocomplete="off" placeholder="https://old-domain.com"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wpfh-bsr-replace"><?php esc_html_e( 'Replace with', 'forgehub-search-replace' ); ?></label></th>
						<td><input type="text" id="wpfh-bsr-replace" class="large-text" autocomplete="off" placeholder="https://new-domain.com"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Options', 'forgehub-search-replace' ); ?></th>
						<td>
							<label><input type="checkbox" id="wpfh-bsr-ci"> <?php esc_html_e( 'Case-insensitive', 'forgehub-search-replace' ); ?></label><br>
							<label><input type="checkbox" id="wpfh-bsr-guid"> <?php esc_html_e( 'Also replace in the guid column (not recommended)', 'forgehub-search-replace' ); ?></label>
							<p class="description"><?php esc_html_e( 'GUIDs are permanent post identifiers. Changing them can make feed readers show old posts as new. They are skipped by default.', 'forgehub-search-replace' ); ?></p>
						</td>
					</tr>
				</table>

				<?php if ( $is_pro ) : ?>
					<div id="wpfh-bsr-selector" class="wpfh-bsr-selector">
						<h2><?php esc_html_e( 'Tables and columns (Pro)', 'forgehub-search-replace' ); ?></h2>
						<p>
							<button type="button" class="button-link" id="wpfh-bsr-all"><?php esc_html_e( 'Select all', 'forgehub-search-replace' ); ?></button> |
							<button type="button" class="button-link" id="wpfh-bsr-none"><?php esc_html_e( 'Select none', 'forgehub-search-replace' ); ?></button>
						</p>
						<div id="wpfh-bsr-table-list"></div>
					</div>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Free scans every table of this site (all text columns). Table and column selection is available in Pro.', 'forgehub-search-replace' ); ?></p>
				<?php endif; ?>

				<p class="submit">
					<button type="button" class="button button-secondary" id="wpfh-bsr-dry"><?php esc_html_e( 'Dry run (preview)', 'forgehub-search-replace' ); ?></button>
					<button type="button" class="button button-primary" id="wpfh-bsr-live" disabled><?php esc_html_e( 'Run replacement', 'forgehub-search-replace' ); ?></button>
				</p>

				<div id="wpfh-bsr-progress" class="wpfh-bsr-progress" hidden>
					<div class="wpfh-bsr-bar"><span id="wpfh-bsr-bar-fill"></span></div>
					<p id="wpfh-bsr-status" role="status" aria-live="polite"></p>
				</div>
			</div>

			<div id="wpfh-bsr-results" class="wpfh-bsr-card" hidden>
				<h2><?php esc_html_e( 'Results', 'forgehub-search-replace' ); ?></h2>
				<p id="wpfh-bsr-summary"></p>
				<table class="widefat striped" id="wpfh-bsr-samples" hidden>
					<thead><tr>
						<th><?php esc_html_e( 'Table', 'forgehub-search-replace' ); ?></th>
						<th><?php esc_html_e( 'Column / ID', 'forgehub-search-replace' ); ?></th>
						<th><?php esc_html_e( 'Before', 'forgehub-search-replace' ); ?></th>
						<th><?php esc_html_e( 'After', 'forgehub-search-replace' ); ?></th>
					</tr></thead>
					<tbody></tbody>
				</table>
			</div>

			<?php
			if ( $is_pro ) {
				/**
				 * Pro panel: backups and undo.
				 */
				do_action( 'wpfh_bsr_render_pro_panel' );
			}
			?>
		</div>
		<?php
	}
}
