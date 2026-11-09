<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap bricks-admin-wrapper bricks-woo-setup-wizard">
	<h1 class="admin-notices-placeholder"></h1>

	<div class="page-intro">
		<div id="bricks-woo-progress" class="setup-progress" style="--ready-percent: 0%;">
			<div class="setup-progress-ring">
				<strong><span class="setup-progress-ready">0</span>/<span class="setup-progress-total">0</span></strong>
				<span><?php echo esc_html__( 'Ready', 'bricks' ); ?></span>
			</div>
		</div>

		<div class="page-intro-copy">
			<span class="page-intro-kicker">
				<?php echo esc_html__( 'Setup wizard', 'bricks' ); ?>
			</span>

			<h1><?php echo esc_html__( 'Get your WooCommerce store running in minutes.', 'bricks' ); ?></h1>
			<p><?php echo esc_html__( 'We\'ll create functional Bricks templates for the most common store areas so your site works out of the box. Treat them as a solid starting point - customise anything in the Bricks builder when you\'re ready.', 'bricks' ); ?></p>
		</div>

		<div class="page-intro-actions">
			<a class="button" href="https://academy.bricksbuilder.io/integrations/woocommerce/woo-setup-wizard/" target="_blank" rel="noopener">
				<?php echo esc_html__( 'View documentation', 'bricks' ); ?>
			</a>
		</div>
	</div>

	<!-- Loading state -->
	<div id="bricks-woo-loading" class="areas-loading">
		<p><?php echo esc_html__( 'Loading setup status…', 'bricks' ); ?></p>
	</div>

	<div class="bricks-woo-content" >
		<!-- Status cards container -->
		<div id="bricks-woo-areas" class="areas-grid is-hidden" ></div>

		<div class="wizard-note">
			<span class="dashicons dashicons-tag" aria-hidden="true"></span>
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %1$s: Woo Setup Wizard template tag. %2$s: Bricks Templates screen link. */
						__( 'Wizard templates are tagged %1$s. Delete or duplicate them at any time from the %2$s screen.', 'bricks' ),
						'<code>[BricksWooWizard]</code>',
						'<a href="' . esc_url( admin_url( 'edit.php?post_type=bricks_template' ) ) . '">' . esc_html__( 'Bricks Templates', 'bricks' ) . '</a>'
					)
				);
				?>
			</p>
		</div>
	</div>

</div>
