<?php
/**
 * Plugin Name:       Calucon Third-Party Embed Gate
 * Plugin URI:        https://calucon.de/third-party-embed-gate/
 * Description:       Two-click embeds: third-party iframes load only after the visitor asks for them. No banner, no consent platform, no third-party request before the click.
 * Version:           0.10.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            Calucon
 * Author URI:        https://calucon.de
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       calucon-third-party-embed-gate
 * Domain Path:       /languages
 *
 * @package CaluconEmbedGate
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CALUCON_EMBED_GATE_VERSION', '0.10.0' );
define( 'CALUCON_EMBED_GATE_FILE', __FILE__ );
define( 'CALUCON_EMBED_GATE_DIR', __DIR__ );

// Cloudflare's official plugin builds its purge-trigger list through ITS
// 'cloudflare_purge_everything_actions' filter, applied while that plugin
// loads — registering here at include time (this plugin sorts before
// "cloudflare" in the load order) is the only reliably-early spot. Effect:
// when this plugin fires its own calucon_embed_gate_flush_caches action
// (settings change, deactivation), Cloudflare purges its cache too.
add_filter(
	'cloudflare_purge_everything_actions',
	static function ( $actions ) {
		$actions   = is_array( $actions ) ? $actions : array();
		$actions[] = 'calucon_embed_gate_flush_caches';
		return $actions;
	}
);

spl_autoload_register(
	static function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'CaluconEmbedGate\\' ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( 'CaluconEmbedGate\\' ) );
		$path     = CALUCON_EMBED_GATE_DIR . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_file( $path ) ) {
			require $path;
		}
	}
);

add_action( 'plugins_loaded', array( 'CaluconEmbedGate\\Plugin', 'boot' ) );
