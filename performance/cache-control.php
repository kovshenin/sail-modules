<?php
namespace Sail\Cache;

// Runs in a CLI/Cron context, likely by-passing advanced-cache.php
add_action( 'sail_cache_clean', function() {
	$cache_dir = WP_CONTENT_DIR . '/cache/sail';
	$start = microtime( true );
	$keys = [];
	$deleted = 0;
	$time = time();

	$levels = scandir( $cache_dir );
	foreach ( $levels as $level ) {
		if ( $level == '.' || $level == '..' ) {
			continue;
		}

		if ( $level == 'flags.json' ) {
			continue;
		}

		$items = scandir( "{$cache_dir}/{$level}" );
		foreach ( $items as $item ) {
			if ( $item == '.' || $item == '..' ) {
				continue;
			}

			if ( substr( $item, -5 ) != '.meta' ) {
				continue;
			}

			$cache_key = substr( $item, 0, -5 );
			$keys[] = $cache_key;
		}
	}

	foreach ( $keys as $cache_key ) {
		$level = substr( $cache_key, -2 );

		$f = fopen( "{$cache_dir}/{$level}/{$cache_key}.meta", 'r' );
		if ( ! flock( $f, LOCK_EX ) ) {
			// Could not acquire a lock.
			fclose( $f );
			continue;
		}

		$contents = '';
		while ( ! feof( $f ) ) {
			$contents .= fread( $f, 8192 );
		}

		$meta = json_decode( $contents, true );

		// This cache entry is still valid.
		if ( $meta && ! empty( $meta['expries'] ) && $meta['expires'] > $time ) {
			fclose( $f );
			continue;
		}

		// Delete the cache entry and release the lock.
		unlink( "{$cache_dir}/{$level}/{$cache_key}.data" );
		unlink( "{$cache_dir}/{$level}/{$cache_key}.meta" );
		fclose( $f );
		$deleted++;
	}

	$end = microtime( true );
	$elapsed = $end - $start;
	// printf( 'Deleted %d/%d keys in %.4f seconds' . PHP_EOL, $deleted, count( $keys ), $elapsed );
} );

// Bail if cache is inactive.
if ( ! defined( '\Sail\Cache\CACHE_DIR' ) ) {
	return;
}

add_filter( 'the_posts', function( $posts ) {
	$post_ids = wp_list_pluck( $posts, 'ID' );
	$blog_id = get_current_blog_id();

	foreach ( $post_ids as $id ) {
		flag( sprintf( 'post:%d:%d', $blog_id, $id ) );
	}

	return $posts;
} );

add_action( 'clean_post_cache', function( $post_id, $post ) {
	if ( wp_is_post_revision( $post ) ) {
		return;
	}

	$blog_id = get_current_blog_id();
	expire( sprintf( 'post:%d:%d', $blog_id, $post_id ) );
}, 10, 2 );

add_action( 'shutdown', function() {
	if ( ! wp_next_scheduled( 'sail_cache_clean' ) ) {
		wp_schedule_event( time(), 'hourly', 'sail_cache_clean' );
	}

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
