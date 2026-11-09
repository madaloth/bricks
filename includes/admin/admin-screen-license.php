<?php
namespace Bricks;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

$license_key            = License::$license_key;
$license_key_is_defined = License::$license_key_is_defined;
$license_status         = License::get_license_status() ?? 'no_license';
$db_license_key         = License::get_db_license_key();
$has_db_license_key     = $license_key_is_defined && $db_license_key;
?>

<div class="wrap bricks-admin-wrapper license">
	<h1 class="title"><?php esc_html_e( 'License', 'bricks' ); ?></h1>

	<div class="bricks-admin-inner">
		<form id="bricks-license-key-form" method="post">
			<?php
			if ( $license_key ) {
				$license_key_enciphered = substr_replace( $license_key, 'XXXXXXXXXXXXXXXXXXXXXXXX', 4, 24 );
				$license_status_label   = str_replace( '_', ' ', $license_status );
				?>
			<p>
				<?php
				// translators: %s = account url
				printf( __( 'You can manage your license, update payment method and view your invoices right from %s.', 'bricks' ), '<a href="' . BRICKS_REMOTE_URL . '" target="_blank">' . esc_html__( 'your account', 'bricks' ) . '</a>' );
				?>
			</p>

				<?php if ( $license_key_is_defined ) { ?>
					<div class="license-key-defined">
						<p><?php esc_html_e( 'License key is defined by the BRICKS_LICENSE_KEY constant.', 'bricks' ); ?></p>

						<input type="hidden" name="action" value="bricks_revalidate_license">

						<?php wp_nonce_field( 'bricks-nonce-admin', 'nonce' ); ?>

						<input type="button" id="bricks-revalidate-license" value="<?php esc_html_e( 'Re-validate license', 'bricks' ); ?>" class="button button-primary button-large">
					</div>

					<?php if ( $has_db_license_key ) { ?>
						<div class="license-key-defined license-db-key-defined">
							<p><?php esc_html_e( 'A license key is still saved in the database, but it is not used while BRICKS_LICENSE_KEY is active.', 'bricks' ); ?></p>

							<input type="button" id="bricks-delete-db-license-key" value="<?php esc_html_e( 'Remove saved database key', 'bricks' ); ?>" class="button button-secondary button-large">
						</div>
					<?php } ?>
				<?php } else { ?>
					<div class="form-group">
						<div class="input-wrapper">
							<input type="text" name="license_key" value="<?php echo esc_attr( $license_key_enciphered ); ?>" disabled>
						</div>

						<input type="hidden" name="action" value="bricks_deactivate_license">

						<?php wp_nonce_field( 'bricks-nonce-admin', 'nonce' ); ?>

						<input type="submit" value="<?php esc_html_e( 'Deactivate license', 'bricks' ); ?>" class="button button-secondary button-large">
						<input type="button" id="bricks-revalidate-license" value="<?php esc_html_e( 'Re-validate license', 'bricks' ); ?>" class="button button-primary button-large">
					</div>
				<?php } ?>

			<div class="status-wrapper">
				<?php esc_html_e( 'Status', 'bricks' ); ?>: <span class="status <?php echo esc_attr( $license_status ); ?>"><?php echo esc_html( $license_status_label ); ?></span>
			</div>

			<div class="error-message"></div>
			<div class="success-message"></div>

				<?php if ( $license_status === 'website_inactive' ) { ?>
					<?php if ( $license_key_is_defined ) { ?>
						<p class="license-mismatch"><?php esc_html_e( 'Your website does not match the license key defined by BRICKS_LICENSE_KEY. Update or remove the constant, then re-validate the license.', 'bricks' ); ?></p>
					<?php } else { ?>
						<p class="license-mismatch"><?php esc_html_e( 'Your website does not match your license key. Please deactivate and then reactivate your license.', 'bricks' ); ?></p>
					<?php } ?>
			<?php } elseif ( $license_status === 'license_key_invalid' ) { ?>
					<?php if ( $license_key_is_defined ) { ?>
						<p class="license-mismatch"><?php esc_html_e( 'The license key defined by BRICKS_LICENSE_KEY is invalid. Update or remove the constant, then re-validate the license.', 'bricks' ); ?></p>
					<?php } else { ?>
						<p class="license-mismatch"><?php esc_html_e( 'Your provided license key is invalid. Please deactivate and then reactivate your license.', 'bricks' ); ?></p>
					<?php } ?>
			<?php } elseif ( $license_status === 'error_remote' ) { ?>
				<p class="license-outage"><?php esc_html_e( 'The license server temporarily unavailable. Your saved license is used locally and validation will occur within 48 hours. You can also re-validate the license manually.', 'bricks' ); ?></p>
				<?php } ?>

				<?php
			} else {
				?>
				<p><?php esc_html_e( 'Activate your license to edit with Bricks, receive one-click updates, and access to all community templates.', 'bricks' ); ?></p>
				<p>
					<?php
					// translators: %s = Bricks account URL
					printf( __( 'Log in to %s to retrieve your license key or copy & paste it from your purchase confirmation email.', 'bricks' ), '<a href="' . BRICKS_REMOTE_URL . '" target="_blank">' . esc_html__( 'your account', 'bricks' ) . '</a>' );
					?>
				</p>

				<div class="form-group">
					<div class="input-wrapper">
						<input type="password" name="license_key" placeholder="<?php esc_attr_e( 'Paste your license key here', 'bricks' ); ?>" required="required">
						<?php if ( ! $license_key ) { ?>
						<i id="bricks-toggle-license-key" class="dashicons dashicons-hidden"></i>
						<?php } ?>
					</div>

					<input type="hidden" name="action" value="bricks_activate_license">

					<?php wp_nonce_field( 'bricks-nonce-admin', 'nonce' ); ?>

					<input type="submit" value="<?php esc_html_e( 'Activate license', 'bricks' ); ?>" class="button button-primary button-large">
				</div>

				<p class="desc"><?php esc_html_e( 'License key format', 'bricks' ); ?>: <code>3fb3b6622d0b3ea0d0fda6600aa66b8f</code></p>

				<div class="error-message"></div>
				<div class="success-message"></div>
			<?php } ?>
		</form>
	</div>
</div>
