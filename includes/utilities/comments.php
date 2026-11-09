<?php
/**
 * Comments list
 *
 * @since 1.0
 */
function bricks_list_comments( $comment, $args, $depth ) {
	$GLOBALS['comment'] = $comment; // phpcs:ignore

	if ( $args['style'] === 'div' ) {
		$tag       = 'div';
		$add_below = 'comment';
	} else {
		$tag       = 'li';
		$add_below = 'div-comment';
	}
	?>

	<<?php echo esc_html( $tag ); ?> <?php comment_class( empty( $args['has_children'] ) ? '' : 'parent' ); ?> id="comment-<?php comment_ID(); ?>">
		<?php if ( $args['style'] !== 'div' ) { ?>
		<div id="div-comment-<?php comment_ID(); ?>" class="comment-body">
		<?php } ?>

			<?php if ( $args['bricks_avatar'] == true ) { ?>
			<div class="comment-avatar">
				<?php
				$avatar_size = 96;

				if ( is_numeric( $args['avatar_size'] ) ) {
					$avatar_size = absint( $args['avatar_size'] );

					if ( $args['avatar_size'] > 0 && $avatar_size === 0 ) {
						$avatar_size = 1;
					}
				} elseif ( is_string( $args['avatar_size'] ) && preg_match( '/^\s*(\d+(?:\.\d+)?)px\s*$/i', $args['avatar_size'], $matches ) ) {
					$avatar_size = absint( $matches[1] );

					if ( $matches[1] > 0 && $avatar_size === 0 ) {
						$avatar_size = 1;
					}
				} elseif ( is_string( $args['avatar_size'] ) && preg_match( '/^\s*0(?:\.0+)?[a-z%]*\s*$/i', $args['avatar_size'] ) ) {
					$avatar_size = 0;
				}

				// CSS-relative values have no reliable pixel equivalent before the browser resolves them.
				// Use the WordPress default to avoid oversized source images.
				if ( $avatar_size ) {
					echo get_avatar(
						$comment,
						$avatar_size,
						'',
						'',
						[ 'class' => 'css-filter' ]
					);
				}
				?>

				<?php
				$commentator             = get_comment();
				$comment_author_is_admin = false;

				if ( ! empty( $commentator->user_id ) ) {
					$comment_author = get_userdata( $commentator->user_id );

					if ( $comment_author instanceof WP_User ) {
						$comment_author_is_admin = in_array( 'administrator', $comment_author->roles, true );

						if ( is_multisite() && ! $comment_author_is_admin ) {
							$comment_author_is_admin = is_super_admin( $comment_author->ID );
						}
					}
				}

				if ( $comment_author_is_admin ) {
					?>
				<div class="administrator-badge" data-balloon="<?php esc_attr_e( 'Admin', 'bricks' ); ?>">A</div>
				<?php } ?>
			</div>
			<?php } ?>

			<div class="comment-data">
				<div class="comment-author vcard">
					<?php
					// NOTE: Undocumented (@since 1.10)
					$comment_author_tag = esc_html( apply_filters( 'bricks/comments/author_tag', 'h5' ) );
					echo "<$comment_author_tag class=\"fn\">" . get_comment_author_link() . "</$comment_author_tag>";
					?>

					<?php
					if ( $comment->comment_approved == '0' ) {
						$commenter = wp_get_current_commenter();

						if ( $commenter['comment_author_email'] ) {
							$moderation_note = esc_html__( 'Your comment is awaiting moderation.', 'bricks' );
						} else {
							$moderation_note = esc_html__( 'Your comment is awaiting moderation. This is a preview; your comment will be visible after it has been approved.', 'bricks' );
						}

						echo '<em class="comment-awaiting-moderation">' . $moderation_note . '</em>';
					}
					?>

					<div class="comment-meta">
						<?php
						// translators: %s: Human time diff
						$timestamp = sprintf( __( '%s ago', 'bricks' ), human_time_diff( get_comment_time( 'U' ), current_time( 'timestamp' ) ) );

						// NOTE: Undocumented
						$timestamp = apply_filters( 'bricks/comments/timestamp', $timestamp, $comment );

						echo '<a href="' . get_comment_link() . '"><span>' . $timestamp . '</span></a>';
						?>

						<?php if ( comments_open() ) { ?>
						<span class="reply">
							<?php
							comment_reply_link(
								array_merge(
									$args,
									[
										'add_below' => $add_below,
										'depth'     => $depth,
										'max_depth' => $args['max_depth']
									]
								)
							);
							?>
						</span>
						<?php } ?>
					</div>
				</div>

				<div class="comment-content">
					<?php comment_text(); ?>
				</div>
			</div>
		<?php if ( $args['style'] !== 'div' ) { ?>
		</div>
			<?php
		}
}

/**
 * Move comment form textarea to the bottom
 *
 * @since 1.0
 */
function bricks_comment_form_fields_order( $fields ) {
	if ( isset( $fields['comment'] ) ) {
		$comment_field = $fields['comment'];

		unset( $fields['comment'] );

		$fields['comment'] = $comment_field;
	}

	return $fields;
}
add_filter( 'comment_form_fields', 'bricks_comment_form_fields_order' );
