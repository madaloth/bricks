<?php
namespace Bricks\Integrations\Form\Actions;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Redirect extends Base {
	/**
	 * Redirect action
	 *
	 * @since 1.0
	 */
	public function run( $form ) {
		$redirect_to   = false;
		$redirect_path = '';

		$form_settings = $form->get_settings();

		if ( isset( $form_settings['redirect'] ) ) {
			$redirect_path = $form->render_data( $form_settings['redirect'] );
			$redirect_to   = $redirect_path;
		}

		// Redirect to admin area
		if ( isset( $form_settings['redirectAdminUrl'] ) ) {
			// Single site
			$redirect_to = admin_url( $redirect_path );

			// Multisite
			if ( is_multisite() && is_user_logged_in() ) {
				// Use get_current_user_id() instead of $login_response (#86c5v5qjg)
				$user_id = get_current_user_id();

				// @see https://developer.wordpress.org/reference/functions/get_dashboard_url/
				$redirect_to = get_dashboard_url( $user_id, $redirect_path );
			}
		}

		if ( $redirect_to ) {
			$form->set_result(
				[
					'action'               => $this->name,
					'type'                 => 'redirect',
					'redirectTo'           => $redirect_to,
					'redirectRendered'     => true,
					// Admin URLs already have a trusted host, which can differ from the site host on multisite.
					'redirectValidateHost' => ! isset( $form_settings['redirectAdminUrl'] ) && \Bricks\Integrations\Form\Init::redirect_template_controls_host( $form_settings['redirect'] ?? '' ),
					'redirectTimeout'      => isset( $form_settings['redirectTimeout'] ) ? intval( $form_settings['redirectTimeout'] ) : 0
				]
			);
		}
	}
}
