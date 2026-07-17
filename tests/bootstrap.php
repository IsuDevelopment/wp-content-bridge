<?php
/**
 * PHPUnit bootstrap.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! is_readable( $autoload ) ) {
	throw new RuntimeException( 'Composer dependencies are required to run the tests.' );
}

require_once $autoload;
