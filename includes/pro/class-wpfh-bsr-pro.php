<?php
/**
 * Pro suite: automatic backup of every rewritten value and one-click undo.
 *
 * Table and column selection is implemented in the admin screen when Pro is active
 * (see WPFH_BSR_Admin::read_request() and the selector UI in assets/js/admin.js).
 *
 * @package WPFH_Search_Replace
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WPFH_BSR_Pro {

	const UNDO_BATCH = 500;

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_init', array( $this, 'maybe_create_table' ) );
		add_action( 'wpfh_bsr_before_cell_update', array( $this, 'backup_cell' ), 10, 6 );
		add_action( 'wpfh_bsr_render_pro_panel', array( $this, 'render_panel' ) );
		add_action( 'wp_ajax_wpfh_bsr_undo', array( $this, 'ajax_undo' ) );
		add_action( 'wp_ajax_wpfh_bsr_delete_backup', array( $this, 'ajax_delete_backup' ) );
	}

	/**
	 * Backup table name.
	 *
	 * @return string
	 */
	public static function backup_table() {
		global $wpdb;
		return $wpdb->prefix . 'wpfh_bsr_backup';
	}

	/**
	 * Create the backup table on first use.
	 */
	public function maybe_create_table() {
		if ( get_option( 'wpfh_bsr_db_version' ) === WPFH_BSR_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::backup_table();
		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				run_id VARCHAR(40) NOT NULL,
				created_at DATETIME NOT NULL,
				table_name VARCHAR(190) NOT NULL,
				pk_col VARCHAR(64) NOT NULL,
				pk_val VARCHAR(190) NOT NULL,
				col VARCHAR(64) NOT NULL,
				old_value LONGTEXT NULL,
				PRIMARY KEY  (id),
				KEY run_id (run_id)
			) {$charset};"
		);
		update_option( 'wpfh_bsr_db_version', WPFH_BSR_VERSION, false );
	}

	/**
	 * Store the original value of a cell before it is rewritten.
	 *
	 * @param string $run_id Run id.
	 * @param string $table  Table.
	 * @param string $pk     Primary key column.
	 * @param mixed  $pk_val Primary key value.
	 * @param string $col    Column.
	 * @param string $old    Original value.
	 */
	public function backup_cell( $run_id, $table, $pk, $pk_val, $col, $old ) {
		global $wpdb;
		$this->maybe_create_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			self::backup_table(),
			array(
				'run_id'     => $run_id,
				'created_at' => current_time( 'mysql', true ),
				'table_name' => $table,
				'pk_col'     => $pk,
				'pk_val'     => (string) $pk_val,
				'col'        => $col,
				'old_value'  => $old,
			)
		);
	}

	/**
	 * Backed-up runs, newest first.
	 *
	 * @return array
	 */
	private function get_runs() {
		global $wpdb;
		$this->maybe_create_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT run_id, MIN(created_at) AS created_at, COUNT(*) AS cells FROM %i GROUP BY run_id ORDER BY MIN(id) DESC LIMIT 20', self::backup_table() ), ARRAY_A );
	}

	public function render_panel() {
		$runs = $this->get_runs();
		?>
		<div class="wpfh-bsr-card" id="wpfh-bsr-backups">
			<h2><?php esc_html_e( 'Backups and undo (Pro)', 'forgehub-search-replace' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Every value changed by a live run is saved before it is rewritten. Undo restores the exact original values.', 'forgehub-search-replace' ); ?></p>
			<?php if ( empty( $runs ) ) : ?>
				<p><?php esc_html_e( 'No backups yet.', 'forgehub-search-replace' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Run', 'forgehub-search-replace' ); ?></th>
						<th><?php esc_html_e( 'Date (UTC)', 'forgehub-search-replace' ); ?></th>
						<th><?php esc_html_e( 'Changed values', 'forgehub-search-replace' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $runs as $run ) : ?>
						<tr data-run="<?php echo esc_attr( $run['run_id'] ); ?>">
							<td><code><?php echo esc_html( substr( $run['run_id'], 0, 8 ) ); ?></code></td>
							<td><?php echo esc_html( $run['created_at'] ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $run['cells'] ) ); ?></td>
							<td>
								<button type="button" class="button wpfh-bsr-undo"><?php esc_html_e( 'Undo this run', 'forgehub-search-replace' ); ?></button>
								<button type="button" class="button-link-delete wpfh-bsr-del-backup"><?php esc_html_e( 'Delete backup', 'forgehub-search-replace' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<p id="wpfh-bsr-undo-status" role="status" aria-live="polite"></p>
		</div>
		<?php
	}

	/**
	 * Check capability, nonce and read the run id.
	 *
	 * @return string
	 */
	private function guard_run() {
		check_ajax_referer( 'wpfh_bsr_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Unauthorized.', 'forgehub-search-replace' ) ), 403 );
		}
		$run = isset( $_POST['run_id'] ) ? preg_replace( '/[^a-f0-9\-]/', '', sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		if ( '' === $run ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Missing run.', 'forgehub-search-replace' ) ), 400 );
		}
		return $run;
	}

	/**
	 * Restore up to UNDO_BATCH backed-up values and remove them from the backup table.
	 */
	public function ajax_undo() {
		$run = $this->guard_run();
		global $wpdb;

		$valid_tables = WPFH_BSR_Engine::list_tables();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE run_id = %s ORDER BY id DESC LIMIT %d', self::backup_table(), $run, self::UNDO_BATCH ), ARRAY_A );

		$restored = 0;
		foreach ( $rows as $row ) {
			if ( in_array( $row['table_name'], $valid_tables, true ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( $row['table_name'], array( $row['col'] => $row['old_value'] ), array( $row['pk_col'] => $row['pk_val'] ) );
				$restored++;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( self::backup_table(), array( 'id' => (int) $row['id'] ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$left = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE run_id = %s', self::backup_table(), $run ) );
		if ( 0 === $left ) {
			wp_cache_flush();
		}

		wp_send_json_success(
			array(
				'restored' => $restored,
				'left'     => $left,
				'done'     => 0 === $left,
			)
		);
	}

	/**
	 * Delete a run's backup without restoring it.
	 */
	public function ajax_delete_backup() {
		$run = $this->guard_run();
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( self::backup_table(), array( 'run_id' => $run ) );
		wp_send_json_success( array( 'done' => true ) );
	}
}
