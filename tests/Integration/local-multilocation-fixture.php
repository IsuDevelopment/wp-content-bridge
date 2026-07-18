<?php
/**
 * Licensed Yoast Local SEO multiple-location runtime fixture.
 *
 * Sets up (and tears down) a real multiple-location configuration on a local
 * WordPress install so the SEO abilities can be verified against provider-emitted
 * branch Schema. It is destructive to Local SEO configuration by design and
 * always restores the exact prior state on teardown.
 *
 * Modes are selected with the WPCB_ML_MODE environment variable (setup|teardown).
 * Never run against production. Local development only.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Tests\Integration;

use Yoast\WP\Local\PostType\PostType;

const WPCB_ML_STATE_OPTION     = 'wpcb_ml_fixture_state';
const WPCB_ML_LEAK_SENTINEL    = 'WPCBLEAKCANARY-do-not-return';
const WPCB_ML_ORG_NAME         = 'WP Content Bridge Test Organization';
const WPCB_ML_LOCATIONS_POLICY = array(
	'get_content'    => true,
	'search_content' => true,
);

/**
 * Reads the option that holds fixture state, or null when absent.
 *
 * @return array<string, mixed>|null
 */
function wpcb_ml_state(): ?array {
	$state = get_option( WPCB_ML_STATE_OPTION, null );

	return is_array( $state ) ? $state : null;
}

/**
 * Restores or deletes an option from a captured snapshot.
 *
 * @param string $name     Option name.
 * @param mixed  $snapshot Snapshot value, or the string "__absent__".
 * @return void
 */
function wpcb_ml_restore_option( string $name, mixed $snapshot ): void {
	if ( '__absent__' === $snapshot ) {
		delete_option( $name );

		return;
	}

	update_option( $name, $snapshot, false );
}

/**
 * Registers business meta for one location post.
 *
 * @param int    $post_id     Location post id.
 * @param string $street      Street address.
 * @param string $city        City.
 * @param string $zip         Postal code.
 * @param float  $lat         Latitude.
 * @param float  $lng         Longitude.
 * @param string $open        Opening time (HH:MM).
 * @param string $close       Closing time (HH:MM).
 * @return void
 */
function wpcb_ml_set_location_meta( int $post_id, string $street, string $city, string $zip, float $lat, float $lng, string $open, string $close ): void {
	$meta = array(
		'_wpseo_business_type'    => 'Dentist',
		'_wpseo_business_name'    => get_the_title( $post_id ),
		'_wpseo_business_address' => $street,
		'_wpseo_business_city'    => $city,
		'_wpseo_business_state'   => 'Małopolska',
		'_wpseo_business_zipcode' => $zip,
		'_wpseo_business_country' => 'PL',
		'_wpseo_business_phone'   => '+48 12 000 00 00',
		'_wpseo_business_email'   => 'location@example.invalid',
		'_wpseo_business_url'     => home_url( '/' ),
		'_wpseo_coordinates_lat'  => (string) $lat,
		'_wpseo_coordinates_long' => (string) $lng,
		// A non-allowlisted marker that must never surface in normalized output.
		'_wpcb_secret_marker'     => WPCB_ML_LEAK_SENTINEL,
	);

	foreach ( array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday' ) as $day ) {
		$meta[ "_wpseo_opening_hours_{$day}_from" ] = $open;
		$meta[ "_wpseo_opening_hours_{$day}_to" ]   = $close;
	}

	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}
}

/**
 * Enables the multiple-location configuration and creates fixture locations.
 *
 * @return void
 */
function wpcb_ml_setup(): void {
	if ( null !== wpcb_ml_state() ) {
		wpcb_ml_teardown( false );
	}

	$local_snapshot  = get_option( 'wpseo_local', '__absent__' );
	$titles_snapshot = get_option( 'wpseo_titles', '__absent__' );
	$policy_snapshot = get_option( 'wpcb_content_type_access', '__absent__' );

	// Represent a company so Yoast emits an Organization the branch can parent to.
	$titles                      = is_array( $titles_snapshot ) ? $titles_snapshot : array();
	$titles['company_or_person'] = 'company';
	$titles['company_name']      = WPCB_ML_ORG_NAME;
	update_option( 'wpseo_titles', $titles, false );

	$local                           = is_array( $local_snapshot ) ? $local_snapshot : array();
	$local['use_multiple_locations'] = 'on';
	$local['multiple_locations_same_organization']    = 'on';
	$local['multiple_locations_shared_business_info'] = 'off';
	$local['multiple_locations_shared_opening_hours'] = 'off';
	// Sentinels: private keys that must never appear in normalized SEO output.
	$local['local_api_key']         = WPCB_ML_LEAK_SENTINEL;
	$local['local_api_key_browser'] = WPCB_ML_LEAK_SENTINEL;
	$local['googlemaps_api_key']    = WPCB_ML_LEAK_SENTINEL;
	update_option( 'wpseo_local', $local, false );

	if ( class_exists( '\\Yoast\\WP\\SEO\\Options\\WPSEO_Options' ) ) {
		\WPSEO_Options::clear_cache();
	} elseif ( class_exists( '\\WPSEO_Options' ) ) {
		\WPSEO_Options::clear_cache();
	}

	// Register the locations CPT in this process so permalinks resolve.
	if ( class_exists( PostType::class ) ) {
		( new PostType() )->initialize();
	}
	flush_rewrite_rules( false );

	$primary_id = wp_insert_post(
		array(
			'post_type'    => 'wpseo_locations',
			'post_status'  => 'publish',
			'post_title'   => 'WPCB Test HQ Krakow',
			'post_name'    => 'wpcb-test-hq-krakow',
			'post_content' => 'Primary location fixture for runtime verification.',
		),
		true
	);
	$branch_id  = wp_insert_post(
		array(
			'post_type'    => 'wpseo_locations',
			'post_status'  => 'publish',
			'post_title'   => 'WPCB Test Branch Warsaw',
			'post_name'    => 'wpcb-test-branch-warsaw',
			'post_content' => 'Branch location fixture for runtime verification.',
		),
		true
	);

	if ( is_wp_error( $primary_id ) || is_wp_error( $branch_id ) ) {
		echo wp_json_encode(
			array(
				'status'  => 'ERROR',
				'message' => 'Failed to create location posts.',
			)
		), "\n";

		return;
	}

	wpcb_ml_set_location_meta( (int) $primary_id, 'Rynek Główny 1', 'Kraków', '31-042', 50.06143, 19.93658, '09:00', '17:00' );
	wpcb_ml_set_location_meta( (int) $branch_id, 'Marszałkowska 10', 'Warszawa', '00-590', 52.22977, 21.01178, '08:00', '16:00' );

	// Mark the primary location so branch pages emit parentOrganization.
	$local['multiple_locations_primary_location'] = (int) $primary_id;
	update_option( 'wpseo_local', $local, false );

	// Enable READ + SEARCH for the locations type without disturbing other rows.
	$policy                    = is_array( $policy_snapshot ) ? $policy_snapshot : array();
	$policy['post']            = WPCB_ML_LOCATIONS_POLICY;
	$policy['page']            = WPCB_ML_LOCATIONS_POLICY;
	$policy['wpseo_locations'] = WPCB_ML_LOCATIONS_POLICY;
	update_option( 'wpcb_content_type_access', $policy, false );

	flush_rewrite_rules( false );

	update_option(
		WPCB_ML_STATE_OPTION,
		array(
			'primary_id'      => (int) $primary_id,
			'branch_id'       => (int) $branch_id,
			'local_snapshot'  => $local_snapshot,
			'titles_snapshot' => $titles_snapshot,
			'policy_snapshot' => $policy_snapshot,
		),
		false
	);

	// Build relative paths deterministically; CLI home_url is malformed in this Local config.
	$locations_slug = is_string( $local['locations_slug'] ?? null ) && '' !== $local['locations_slug'] ? $local['locations_slug'] : 'locations';
	$primary_post   = get_post( (int) $primary_id );
	$branch_post    = get_post( (int) $branch_id );
	$primary_path   = $primary_post instanceof \WP_Post ? '/' . $locations_slug . '/' . $primary_post->post_name . '/' : '';
	$branch_path    = $branch_post instanceof \WP_Post ? '/' . $locations_slug . '/' . $branch_post->post_name . '/' : '';

	echo wp_json_encode(
		array(
			'status'        => 'OK',
			'primary_id'    => (int) $primary_id,
			'branch_id'     => (int) $branch_id,
			'primary_path'  => $primary_path,
			'branch_path'   => $branch_path,
			'leak_sentinel' => WPCB_ML_LEAK_SENTINEL,
			'org_name'      => WPCB_ML_ORG_NAME,
		)
	), "\n";
}

/**
 * Deletes the fixture and restores the prior configuration exactly.
 *
 * @param bool $emit Whether to print a JSON status line.
 * @return void
 */
function wpcb_ml_teardown( bool $emit = true ): void {
	$state = wpcb_ml_state();
	if ( null === $state ) {
		if ( $emit ) {
			echo wp_json_encode( array( 'status' => 'NOOP' ) ), "\n";
		}

		return;
	}

	foreach ( array( 'primary_id', 'branch_id' ) as $key ) {
		$post_id = isset( $state[ $key ] ) ? (int) $state[ $key ] : 0;
		if ( $post_id > 0 ) {
			wp_delete_post( $post_id, true );
		}
	}

	wpcb_ml_restore_option( 'wpseo_local', $state['local_snapshot'] ?? '__absent__' );
	wpcb_ml_restore_option( 'wpseo_titles', $state['titles_snapshot'] ?? '__absent__' );
	wpcb_ml_restore_option( 'wpcb_content_type_access', $state['policy_snapshot'] ?? '__absent__' );

	delete_option( WPCB_ML_STATE_OPTION );

	if ( class_exists( '\\WPSEO_Options' ) ) {
		\WPSEO_Options::clear_cache();
	}
	flush_rewrite_rules( false );

	if ( $emit ) {
		echo wp_json_encode( array( 'status' => 'RESTORED' ) ), "\n";
	}
}

$wpcb_ml_mode = getenv( 'WPCB_ML_MODE' );
if ( 'setup' === $wpcb_ml_mode ) {
	wpcb_ml_setup();
} elseif ( 'teardown' === $wpcb_ml_mode ) {
	wpcb_ml_teardown();
} else {
	echo wp_json_encode(
		array(
			'status'  => 'ERROR',
			'message' => 'Set WPCB_ML_MODE to setup or teardown.',
		)
	), "\n";
}
