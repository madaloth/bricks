<?php
/**
 * Empty view used while capturing Woo's prepared password-reset arguments.
 *
 * The account element renders the selected view after preparation. Keeping
 * this view empty leaves notices queued for the configured Notice element.
 * wc_get_template() still includes a real file after its location filter runs,
 * so this file must remain present even though it intentionally emits nothing.
 *
 * @since 2.4.3
 */

if ( ! defined( 'ABSPATH' ) ) exit;
