<?php
/**
 * Upgrader skin that collects messages instead of printing HTML, so plugin
 * and theme installs can run inside REST and WP-CLI requests.
 *
 * @package WPChrono
 */

namespace WPChrono\Services\Blueprint;

defined( 'ABSPATH' ) || exit;

require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

final class QuietSkin extends \WP_Upgrader_Skin {

	/** @var string[] */
	public $messages = array();

	/**
	 * @param string|\WP_Error $feedback
	 * @param mixed            ...$args
	 */
	public function feedback( $feedback, ...$args ) {
		if ( is_wp_error( $feedback ) ) {
			$this->messages[] = $feedback->get_error_message();
			return;
		}
		if ( isset( $this->upgrader->strings[ $feedback ] ) ) {
			$feedback = $this->upgrader->strings[ $feedback ];
		}
		if ( $args && false !== strpos( (string) $feedback, '%' ) ) {
			$feedback = vsprintf( (string) $feedback, $args );
		}
		$this->messages[] = wp_strip_all_tags( (string) $feedback );
	}

	/**
	 * @param string|\WP_Error $errors
	 */
	public function error( $errors ) {
		$this->feedback( $errors );
	}

	public function header() {}

	public function footer() {}
}
