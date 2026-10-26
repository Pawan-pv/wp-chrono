<?php
/**
 * Registers all REST controllers under /wp-json/wp-chrono/v1/.
 *
 * @package WPChrono
 */

namespace WPChrono\API;

defined( 'ABSPATH' ) || exit;

final class Router {

	public static function register(): void {
		$controllers = array(
			new SystemController(),
			new ResetController(),
			new SnapshotController(),
			new RecoveryController(),
			new BlueprintController(),
			new CloudController(),
			new LicenseController(),
			new OnboardingController(),
		);

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}
}
