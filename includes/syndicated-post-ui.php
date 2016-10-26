<?php

namespace Distributor\DistributordPostUI;

/**
 * Setup actions and filters
 *
 * @since 0.8
 */
add_action( 'plugins_loaded', function() {
	add_action( 'edit_form_top', __NAMESPACE__ . '\Distributord_message', 9, 1 );
	add_action( 'admin_enqueue_scripts', __NAMESPACE__  . '\admin_enqueue_scripts' );
	add_action( 'admin_init', __NAMESPACE__  . '\unlink' );
	add_action( 'admin_init', __NAMESPACE__  . '\link' );
	add_action( 'post_submitbox_misc_actions', __NAMESPACE__ . '\syndication_date' );
	add_filter( 'admin_body_class', __NAMESPACE__ . '\add_linked_class' );
} );

/**
 * Add linked class to body
 * 
 * @param  string $classes
 * @since  0.8
 * @return string
 */
function add_linked_class( $classes ) {
	global $post, $pagenow, $sy_original_post;

	if ( 'post.php' !== $pagenow && 'post-new.php' !== $pagenow ) {
    	return;
    }

    if ( empty( $_GET['post'] ) ) {
    	return $classes;
    }

    $original_blog_id = get_post_meta( $_GET['post'], 'sy_original_blog_id', true );
	$original_post_id = get_post_meta( $_GET['post'], 'sy_original_post_id', true );
	$syndicate_time = get_post_meta( $_GET['post'], 'sy_syndicate_time', true );

	if ( empty( $original_post_id ) || empty( $original_blog_id ) ) {
		return $classes;
	}

	$unlinked = (bool) get_post_meta( $post->ID, 'sy_unlinked', true );

	if ( $unlinked ) {
		return $classes;
	}

	return $classes . ' sy-linked-post';
}

/**
 * Output syndicated on date
 * 
 * @param  WP_Post $post
 * @since  0.8
 */
function syndication_date( $post ) {
	global $sy_original_post;

	if ( ! empty( $sy_original_post ) ) {
		$syndicate_time = $sy_original_post->syndicate_time;
	} else {
		$syndicate_time = get_post_meta( $post->ID, 'sy_syndicate_time', true );
	}

	if ( empty( $syndicate_time ) ) {
		return;
	}

	?>

	<div class="misc-pub-section curtime misc-pub-curtime">
		<span id="syndicate-time"><?php echo sprintf( __( 'Syndicated on: <strong>%s</strong>' ,'syndicate' ), date( 'M j, Y @ h:i', $syndicate_time ) ); ?></span>
	</div>

	<?php
}

/**
 * Repush an already pushed post
 * 
 * @param  int $post_id
 * @since  0.8
 */
function repush( $post_id ) {
	$original_blog_id = get_post_meta( $post_id, 'sy_original_blog_id', true );
	$original_post_id = get_post_meta( $post_id, 'sy_original_post_id', true );

	$current_blog = get_current_blog_id();

	switch_to_blog( $original_blog_id );

	$connection = new \Distributor\InternalConnections\NetworkSiteConnection( get_site( $current_blog ) );
	$connection->push( $original_post_id, [
		'remote_post_id' => $post_id,
	] );

	restore_current_blog();
}

/**
 * Simple function for sideloading media and returning the media id
 * 
 * @param  string $url
 * @param  int $post_id
 * @since  0.8
 * @return int|bool
 */
function process_media( $url, $post_id ) {
	preg_match( '/[^\?]+\.(jpe?g|jpe|gif|png)\b/i', $url, $matches );
	if ( ! $matches ) {
		return false;
	}

	$file_array = array();
	$file_array['name'] = basename( $matches[0] );

	// Download file to temp location.
	$file_array['tmp_name'] = download_url( $url );

	// If error storing temporarily, return the error.
	if ( is_wp_error( $file_array['tmp_name'] ) ) {
		return false;
	}

	// Do the validation and storage stuff.
	return media_handle_sideload( $file_array, $post_id );
}


/**
 * Bring media files over to syndicated post. We copy all the images and update the featured image
 * to use the new one. We leave image urls in the post content intact as we can't guarentee the post 
 * image size in each inserted image exists.
 * 
 * @param  int $post_id
 * @since  0.8
 */
function clone_media( $post_id ) {
	$original_blog_id = get_post_meta( $post_id, 'sy_original_blog_id', true );
	$original_post_id = get_post_meta( $post_id, 'sy_original_post_id', true );
	$post = get_post( $post_id );

	$current_media_posts = get_attached_media( 'image', $post_id );
	$current_media = [];

	// Create mapping so we don't create duplicates
	foreach ( $current_media_posts as $media_post ) {
		$original = get_post_meta( $media_post->ID, 'sy_original_media_url', true );
		$current_media[ $original ] = $media_post->ID;
	}

	$current_blog = get_current_blog_id();

	// Get media of original post
	switch_to_blog( $original_blog_id );

	$original_media_posts = get_attached_media( 'image', $original_post_id );
	$original_media = [];

	foreach ( $original_media_posts as $original_media_post ) {
		$src = wp_get_attachment_image_src( $original_media_post->ID, 'full' );

		$original_media[] = $src[0];
	}

	$featured_image_url = false;
	$found_featured_image = false;

	$thumb_id = get_post_meta( $original_post_id, '_thumbnail_id', true );

	if ( ! empty( $thumb_id ) ) {
	    $thumb = wp_get_attachment_image_src( $thumb_id, 'full' );

	    if ( ! empty( $thumb ) ) {
			$featured_image_url = $thumb[0];
		}
	}

	restore_current_blog();

	foreach ( $original_media as $url ) {

		// Delete duplicate if it exists
		if ( ! empty( $current_media[ $url ] ) ) {
			wp_delete_attachment( $current_media[ $url ], true );
		}

		$image_id = process_media( $url, $post_id );

		// If error storing permanently, unlink.
		if ( ! $image_id ) {
			@unlink( $file_array['tmp_name'] );
			continue;
		}

		update_post_meta( $image_id, 'sy_original_media_url', $url );

		if ( $featured_image_url === $url ) {
			$found_featured_image = true;
			update_post_meta( $post_id, '_thumbnail_id', $image_id );
		}
	}

	if ( ! $found_featured_image && ! empty( $featured_image_url ) ) {
		$image_id = process_media( $featured_image_url, $post_id );

		if ( ! empty( $image_id ) ) {
			update_post_meta( $post_id, '_thumbnail_id', $image_id );
		}
	}
}

/**
 * Unlink post
 *
 * @since  0.8
 */
function unlink() {
	if ( empty( $_GET['action'] ) || 'unlink' !== $_GET['action'] || empty( $_GET['post'] ) ) {
		return;
	}

	if ( empty( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'unlink-post_' . $_GET['post'] ) ) {
		return;
	}

	update_post_meta( $_GET['post'], 'sy_unlinked', true );

	repush( $_GET['post'] );

	clone_media( $_GET['post'] );

	wp_redirect( admin_url( 'post.php?action=edit&post=' . $_GET['post'] ) );
	exit;
}

/**
 * Restore post link
 *
 * @since  0.8
 */
function link() {
	if ( empty( $_GET['action'] ) || 'link' !== $_GET['action'] || empty( $_GET['post'] ) ) {
		return;
	}

	if ( empty( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'link-post_' . $_GET['post'] ) ) {
		return;
	}

	update_post_meta( $_GET['post'], 'sy_unlinked', false );

	repush( $_GET['post'] );

	wp_redirect( admin_url( 'post.php?action=edit&post=' . $_GET['post'] ) );
	exit;
}

/**
 * Show syndicated post message
 *
 * @param  WP_Post $post
 * @since  0.8
 */
function syndicated_message( $post ) {

	$original_blog_id = get_post_meta( $post->ID, 'sy_original_blog_id', true );
	$original_post_id = get_post_meta( $post->ID, 'sy_original_post_id', true );

	if ( empty( $original_post_id ) || empty( $original_blog_id ) ) {
		return;
	}

	$unlinked = (bool) get_post_meta( $post->ID, 'sy_unlinked', true );

	switch_to_blog( $original_blog_id );
	$post_url = get_permalink( $original_post_id );
	$blog_name = get_bloginfo( 'name' );
	restore_current_blog();

	if ( empty( $blog_name ) ) {
		$blog_name = sprintf( esc_html__( 'Blog #%d', 'distributor' ), $original_blog_id );
	}

	$post_type_object = get_post_type_object( $post->post_type );

	?>
	<div class="updated syndicate-status">
		<?php if ( ! $unlinked ) : ?>
			<p>
				<?php echo sprintf( __( 'Syndicated from <a href="%s">%s</a>.', 'distributor' ), esc_url( $post_url ), esc_html( $blog_name ) ); ?> 
				<span><?php echo sprintf( __( 'The original post will update this version unless you <a href="%s">unlink from the original.</a>', 'distributor' ), wp_nonce_url( add_query_arg( 'action', 'unlink', admin_url( sprintf( $post_type_object->_edit_link, $post->ID ) ) ), "unlink-post_{$post->ID}" ) ); ?></span>
			</p>
		<?php else : ?>
			<p>
				<?php echo sprintf( __( 'Originally syndicated from <a href="%s">%s</a>.', 'distributor' ), esc_url( $post_url ), esc_html( $blog_name ) ); ?> 
				<span><?php echo sprintf( __( "This post has been forked from it's original. However, you can always <a href='%s'>restore it.</a>", 'distributor' ), wp_nonce_url( add_query_arg( 'action', 'link', admin_url( sprintf( $post_type_object->_edit_link, $post->ID ) ) ), "link-post_{$post->ID}" ) ); ?></span>
			</p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Enqueue admin scripts for external connection editor
 *
 * @param  string $hook
 * @since  0.8
 */
function admin_enqueue_scripts( $hook ) {
	if ( 'post-new.php' !== $hook && 'post.php' !== $hook ) {
		return;
	}

	global $post;

	$original_blog_id = get_post_meta( $post->ID, 'sy_original_blog_id', true );
	$original_post_id = get_post_meta( $post->ID, 'sy_original_post_id', true );

	if ( empty( $original_post_id ) || empty( $original_blog_id ) ) {
		return;
	}

	if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
		$css_path = '/assets/css/admin-syndicated-post.css';
	} else {
		$css_path = '/assets/css/admin-syndicated-post.min.css';
	}

	wp_enqueue_style( 'sy-admin-syndicated-post', plugins_url( $css_path, __DIR__ ), array(), SY_VERSION );

	$unlinked = (bool) get_post_meta( $post->ID, 'sy_unlinked', true );

	if ( ! $unlinked ) {
		wp_dequeue_script( 'autosave' );
	}
}
