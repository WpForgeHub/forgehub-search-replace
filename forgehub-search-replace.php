<?php
/**
 * Plugin Name:       ForgeHub Search & Replace
 * Plugin URI:        https://wpforgehub.com/plugins/forgehub-search-replace/
 * Description:       Serialize-safe database search and replace with a mandatory dry-run preview and batched AJAX processing. No bloat, no external services.
 * Version:           1.0.0
 * Author:            WPForgeHub
 * Author URI:        https://wpforgehub.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       forgehub-search-replace
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPFH_BSR_VERSION', '1.0.0' );
define( 'WPFH_BSR_FILE', __FILE__ );
define( 'WPFH_BSR_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPFH_BSR_URL', plugin_dir_url( __FILE__ ) );

/**
 * Freemius SDK helper wrapper (falls back to a Free-only shim when the SDK is not bundled).
 */
if ( ! function_exists( 'wpfh_bsr_fs' ) ) {
	function wpfh_bsr_fs() {
		global $wpfh_bsr_fs;

		if ( ! isset( $wpfh_bsr_fs ) ) {
			$fs_path = WPFH_BSR_PATH . 'freemius/start.php';
			$fs_product_id = '0'; // TODO: set the real Freemius Product ID + public key; the SDK must not init with placeholders (fatal).
			if ( '0' !== $fs_product_id && file_exists( $fs_path ) ) {
				require_once $fs_path;
				$wpfh_bsr_fs = fs_dynamic_init( array(
					'id'             => $fs_product_id,
					'slug'           => 'forgehub-search-replace',
					'type'           => 'plugin',
					'public_key'     => 'pk_PLACEHOLDER',
					'is_premium'     => false,
					'has_addons'     => false,
					'has_paid_plans' => true,
					'menu'           => array(
						'slug'       => 'forgehub-search-replace',
						'first-path' => 'tools.php?page=forgehub-search-replace',
						'parent'     => array(
							'slug' => 'tools.php',
						),
					),
				) );
				// Freemius forbids uninstall.php in premium builds; use its after_uninstall hook there.
				$wpfh_bsr_fs->add_action( 'after_uninstall', 'wpfh_bsr_uninstall_cleanup' );
			} else {
				$wpfh_bsr_fs = new class {
					public function can_use_premium_code() {
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
						return (bool) apply_filters( 'fs_can_use_premium_code__forgehub-search-replace', false );
					}
					public function is__premium_only() { return false; }
					public function is_plan( $plan ) {
						// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
						return (bool) apply_filters( 'fs_is_plan__forgehub-search-replace', false, $plan );
					}
					public function get_upgrade_url() {
						return 'https://wpforgehub.com/plugins/forgehub-search-replace/';
					}
				};
			}
		}

		return $wpfh_bsr_fs;
	}

	wpfh_bsr_fs();
}

/**
 * Removes plugin data on uninstall (Freemius premium build; the free build uses uninstall.php).
 */
function wpfh_bsr_uninstall_cleanup() {
	global $wpdb;
	delete_option( 'wpfh_bsr_db_version' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'wpfh_bsr_backup' ) );
}

/**
 * Whether the Pro suite is loaded and licensed.
 *
 * @return bool
 */
function wpfh_bsr_is_pro() {
	return class_exists( 'WPFH_BSR_Pro' ) && wpfh_bsr_fs()->can_use_premium_code();
}

/**
 * Bootstrap.
 */
function wpfh_bsr_boot() {
	require_once WPFH_BSR_PATH . 'includes/class-wpfh-bsr-engine.php';
	require_once WPFH_BSR_PATH . 'includes/class-wpfh-bsr-admin.php';

	$pro_file = WPFH_BSR_PATH . 'includes/pro/class-wpfh-bsr-pro.php';
	if ( file_exists( $pro_file ) && wpfh_bsr_fs()->can_use_premium_code() ) {
		require_once $pro_file;
		WPFH_BSR_Pro::get_instance();
	}

	WPFH_BSR_Admin::get_instance();
}
add_action( 'plugins_loaded', 'wpfh_bsr_boot' );
