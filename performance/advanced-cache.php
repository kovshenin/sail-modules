<?php
namespace Sail\Cache;

const CACHE_DIR = WP_CONTENT_DIR . '/cache/sail';

/**
 * Caching configuration settings.
 *
 * @param string $key Configuration key
 *
 * @return mixed The config value for the supplied key.
 */
function config( $key ) {
	return [
		'ttl' => 600,
		'ignore_cookies' => [ 'wordpress_test_cookie' ],
		'ignore_query_vars' => [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ],
	][ $key ];
}

/**
 * Generate a cache key array.
 *
 * @return array
 */
function key() {
	$cookies = [];
	$headers = [];

	// Clean up and normalize cookies.
	foreach ( $_COOKIE as $key => $value ) {
		if ( ! in_array( $key, config( 'ignore_cookies' ) ) ) {
			$cookies[ $key ] = $value;
		}
	}

	// Clean the URL/query vars
	$parsed = parse_url( 'http://example.org' . $_SERVER['REQUEST_URI'] );
	$path = $parsed['path'];
	$query = $parsed['query'] ?? '';

	parse_str( $query, $query_vars );
	foreach ( $query_vars as $key => $value ) {
		if ( in_array( $key, config( 'ignore_query_vars' ) ) ) {
			unset( $query_vars[ $key ] );
		}
	}

	return [
		'https' => $_SERVER['HTTPS'] ?? '',
		'method' => $_SERVER['REQUEST_METHOD'] ?? '',
		'host' => strtolower( $_SERVER['HTTP_HOST'] ?? '' ),

		'path' => $path,
		'query_vars' => $query_vars,
		'cookies' => $cookies,
		'headers' => $headers,
	];
}

/**
 * Get a cached item by key.
 *
 * @param array $key The array.
 *
 * @return bool|array The metadata array of a cached object or false if not found.
 */
function get( $key ) {
	$cache_key = md5( json_encode( $key ) );
	$level = substr( $cache_key, -2 );
	$meta_filename = CACHE_DIR . "/{$level}/{$cache_key}.meta";

	if ( ! file_exists( $meta_filename ) ) {
		return false;
	}

	$meta = json_decode( file_get_contents( $meta_filename ), true );

	if ( ! $meta ) {
		return false;
	}

	$cache = $meta;
	$cache['filename'] = CACHE_DIR . "/{$level}/{$cache_key}.data";
	return $cache;
}

/**
 * Store a cache item.
 *
 * @param array $key The request key.
 * @param mixed $value The cache item to store.
 *
 * @return bool True on success.
 */
function set( $key, $value ) {
	$contents = $value['contents'];
	unset( $value['contents'] );
	$meta = json_encode( $value );

	$cache_key = md5( json_encode( $key ) );
	$level = substr( $cache_key, -2 );

	if ( ! wp_mkdir_p( CACHE_DIR . "/{$level}/" ) ) {
		return false;
	}

	// Open the meta file and acquire a lock.
	$f = fopen( CACHE_DIR . "/{$level}/{$cache_key}.meta", 'w' );
	if ( ! flock( $f, LOCK_EX ) ) {
		fclose( $f );
		return false;
	}

	file_put_contents( CACHE_DIR . "/{$level}/{$cache_key}.data", $contents, LOCK_EX );

	// Write the metadata and release the lock.
	fwrite( $f, $meta );
	fclose( $f ); // Releases the lock.
	return true;
}

function flag( $flag = null ) {
	static $flags;

	if ( ! isset( $flags ) ) {
		$flags = [];
	}

	if ( $flag ) {
		$flags[] = $flag;
	}

	return $flags;
}

function expire( $flag = null ) {
	static $expire;

	if ( ! isset( $expire ) ) {
		$expire = [];
	}

	if ( $flag ) {
		$expire[] = $flag;
	}

	return $expire;
}

function delete( $key ) {

}

/**
 * The main output buffer callback.
 *
 * @param string $contents The buffer contents.
 *
 * @return string Contents.
 */
$ob_callback = function ( $contents ) {
	$key = key();
	$skip = false;

	foreach ( headers_list() as $header ) {
		list( $name, $value ) = array_map( 'trim', explode( ':', $header, 2 ) );
		$headers[ $name ] = $value;

		if ( strtolower( $name ) == 'set-cookie' ) {
			$skip = true;
			break;
		}

		if ( strtolower( $name ) == 'cache-control' ) {
			if ( stripos( $value, 'no-cache' ) !== false || stripos( $value, 'max-age=0' ) !== false ) {
				$skip = true;
				break;
			}
		}
	}

	if ( ! in_array( strtoupper( $_SERVER['REQUEST_METHOD'] ), [ 'GET', 'HEAD' ] ) ) {
		$skip = true;
	}

	if ( ! in_array( http_response_code(), [ 200, 301, 302, 304, 404 ] ) ) {
		$skip = true;
	}

	if ( $skip ) {
		header( 'X-Cache: skip' );
		return $contents;
	}

	$cache = [
		'code' => http_response_code(),
		'headers' => $headers,

		'contents' => $contents,
		'created' => time(),
		'expires' => time() + config( 'ttl' ),
		'flags' => flag(),

		// TODO: Add custom headers probably.
		// TODO: REMOVE!
		// 'key' => $key,
	];

	set( $key, $cache );
	return $contents;
};

/**
 * Serve a cached version of a request, if available.
 *
 * @return null
 */
function serve() {
	$key = key();
	$cache = get( $key );

	header( 'X-Cache: miss' );

	if ( ! $cache ) {
		return;
	}

	if ( $cache['expires'] < time() ) {
		header( 'X-Cache: expired' );
		return;
	}

	$flags = null;
	if ( file_exists( CACHE_DIR . '/flags.json' ) ) {
		$flags = json_decode( file_get_contents( CACHE_DIR . '/flags.json' ), true );
	}

	if ( $flags && ! empty( $cache['flags'] ) ) {
		foreach ( $flags as $flag => $timestamp ) {
			if ( in_array( $flag, $cache['flags'] ) && $timestamp > $cache['created'] ) {
				header( 'X-Cache: expired' );
				return;
			}
		}
	}

	// Set the HTTP response code and send headers.
	http_response_code( $cache['code'] );

	foreach ( $cache['headers'] as $name => $value ) {
		header( "{$name}: {$value}" );
	}

	header( 'X-Cache: hit' );
	readfile( $cache['filename'] );
	die();
}

serve();
ob_start( $ob_callback );
