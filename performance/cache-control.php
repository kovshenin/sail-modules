<?php
namespace Sail\Cache;

// Bail if cache is inactive.
if ( ! defined( '\Sail\Cache\CACHE_DIR' ) ) {
	return;
}

add_filter( 'the_posts', function( $posts ) {
	array_map( function( $ID ) { flag( 'post:' . $ID ); },
		wp_list_pluck( $posts, 'ID' ) );

	return $posts;
} );

add_action( 'clean_post_cache', function( $post_id, $post ) {
	if ( wp_is_post_revision( $post ) ) {
		return;
	}

	expire( 'post:' . $post_id );
}, 10, 2 );

add_action( 'shutdown', function() {
	$expire = expire();
	if ( empty( $expire ) ) {
		return;
	}

	$flags = null;
	$path = CACHE_DIR . '/flags.json';
	if ( file_exists( $path ) ) {
		$flags = json_decode( file_get_contents( $path ), true );
	}

	if ( ! $flags ) {
		$flags = [];
	}

	foreach ( $expire as $flag ) {
		$flags[ $flag ] = time();
	}

	file_put_contents( $path, json_encode( $flags ) );
} );
