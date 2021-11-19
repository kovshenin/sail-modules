<?php
namespace Sail;

$modules = [
	'performance/cache-control.php',
];

foreach ( $modules as $module ) {
	require_once( __DIR__ . '/' . $module );
}
