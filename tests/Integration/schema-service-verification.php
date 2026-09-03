<?php
/**
 * Runtime verification for the Service schema read/preview/write surface.
 *
 * Run: wp eval 'require "<abs path>/tests/Integration/schema-service-verification.php";'
 *
 * Requires the standalone IsuDev Schema Extended plugin active for its public
 * Meta_Fields API, and Yoast SEO active so the emitted graph can be inspected.
 * The release gate for this slice is graph-level Service / areaServed /
 * hasOfferCatalog parity, so the verifier reads the rendered front-end JSON-LD
 * rather than trusting the provider's own re-read.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

use IsuDev\SchemaExtended\Service\Meta_Fields;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\Mutation\GetServiceSchema;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\PreviewServiceSchema;
use IsuDev\WPContentBridge\Application\Mutation\UpdateServiceSchema;
use IsuDev\WPContentBridge\Infrastructure\SchemaExtended\SchemaExtendedServiceSchemaWriter;
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressAuditLog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentMutationRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentTypeCatalog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressRenderedSchemaReader;

// phpcs:disable Squiz.Commenting.FunctionCommentThrowTag.Missing -- assertion helpers intentionally fail the runtime harness fast.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are CLI diagnostics, not rendered HTML.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- exit code + STDOUT text for a CLI verifier, not rendered HTML.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- CLI diagnostic output, not a filesystem operation.

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress via wp eval.\n" );
	exit( 1 );
}

/**
 * Fail-fast runtime verifier for the Service schema abilities.
 */
final class WPCB_Service_Schema_Verification {

	private const MISSING_OPTION = '__wpcb_service_schema_missing__';

	/**
	 * Collected failures.
	 *
	 * @var list<string>
	 */
	private array $failures = array();

	/**
	 * Snapshotted options restored in the teardown.
	 *
	 * @var array<string, mixed>
	 */
	private array $saved_options = array();

	/**
	 * Fixture post ID removed in the teardown.
	 *
	 * @var int
	 */
	private int $post_id = 0;

	/**
	 * Principal restored in the teardown.
	 *
	 * @var int
	 */
	private int $original_user_id = 0;

	/**
	 * Runs the verification and prints a machine-readable result.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->original_user_id = get_current_user_id();

		try {
			$this->set_up();
			$this->verify_read_preview_write();
			$this->verify_graph();
			$this->verify_stale_token_rejected();
		} catch ( Throwable $error ) {
			$this->failures[] = $error->getMessage();
		} finally {
			$this->tear_down();
		}

		echo wp_json_encode(
			array(
				'status'   => array() === $this->failures ? 'PASS' : 'FAIL',
				'failures' => $this->failures,
			)
		) . "\n";

		exit( array() === $this->failures ? 0 : 1 );
	}

	/**
	 * Creates the fixture and enables exactly the required policy.
	 *
	 * @return void
	 */
	private function set_up(): void {
		$writer = new SchemaExtendedServiceSchemaWriter();
		$this->assert_true(
			$writer->is_available(),
			'Schema Extended is not available; activate the standalone plugin before running this verifier.'
		);

		$administrators = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		$this->assert_true( array() !== $administrators, 'No administrator is available for the verification.' );
		wp_set_current_user( (int) $administrators[0] );

		foreach ( array( Installer::WRITES_ENABLED_OPTION, WordPressContentAccessSettingsRepository::OPTION_NAME ) as $option ) {
			$this->saved_options[ $option ] = get_option( $option, self::MISSING_OPTION );
		}

		/*
		 * Schema Extended scopes the Service surface to its own supported post
		 * types, so the fixture must use one of them rather than assuming 'post'.
		 */
		$supported = Meta_Fields::get_supported_post_types();
		$this->assert_true( array() !== $supported, 'Schema Extended reports no supported post types.' );
		$post_type = (string) $supported[0];

		update_option( Installer::WRITES_ENABLED_OPTION, true, false );
		update_option(
			WordPressContentAccessSettingsRepository::OPTION_NAME,
			array(
				$post_type => array(
					'get_content' => true,
					'update_seo'  => true,
				),
			)
		);

		$post_id = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_status'  => 'publish',
				'post_title'   => 'WPCB service schema fixture',
				'post_content' => 'Fixture for the Service schema runtime verifier.',
			),
			true
		);
		$this->assert_true( ! is_wp_error( $post_id ), 'Could not create the Service schema fixture post.' );
		$this->post_id = (int) $post_id;
	}

	/**
	 * Proves read, non-mutating preview, and an effective write.
	 *
	 * @return void
	 */
	private function verify_read_preview_write(): void {
		$read    = $this->get_use_case()->execute( array( 'post_id' => $this->post_id ) );
		$initial = $read->service_schema;

		$fields = array(
			'enabled'      => true,
			'name'         => 'Runtime verifier service',
			'service_type' => 'Consulting',
			'description'  => 'Service written by the WP Content Bridge runtime verifier.',
			'areas'        => array(
				array(
					'type' => 'City',
					'name' => 'Oslo',
				),
			),
			'catalog_name' => 'Verifier catalog',
			'offers'       => array(
				array(
					'name'        => 'Verifier offer',
					'description' => 'One bounded offer entry.',
				),
			),
		);

		$preview = $this->preview_use_case()->execute(
			array_merge(
				array(
					'post_id'       => $this->post_id,
					'version_token' => $read->version->to_string(),
				),
				$fields
			)
		);
		$this->assert_true( array() !== $preview->changed_fields, 'Preview reported no changed fields for a changing payload.' );

		$after_preview = $this->get_use_case()->execute( array( 'post_id' => $this->post_id ) );
		$this->assert_true(
			$after_preview->service_schema === $initial,
			'Preview mutated the stored Service schema configuration.'
		);

		$result = $this->update_use_case()->execute(
			array_merge(
				array(
					'post_id'       => $this->post_id,
					'version_token' => $after_preview->version->to_string(),
				),
				$fields
			),
			get_current_user_id()
		);

		$effective = $result->effective_service_schema;
		$this->assert_true(
			'Runtime verifier service' === ( $effective['name'] ?? null ),
			'Effective Service name does not match the accepted write.'
		);
		$this->assert_true(
			'Consulting' === ( $effective['service_type'] ?? null ),
			'Effective Service type does not match the accepted write.'
		);
	}

	/**
	 * Proves the write reaches the public graph with its bounded nested nodes.
	 *
	 * @return void
	 */
	private function verify_graph(): void {
		$permalink = get_permalink( $this->post_id );
		$this->assert_true( is_string( $permalink ) && '' !== $permalink, 'Could not resolve the fixture permalink.' );

		$graph = ( new WordPressRenderedSchemaReader( home_url( '/' ) ) )->graph_for_url( (string) $permalink )->nodes;
		$this->assert_true( array() !== $graph, 'The rendered schema graph is empty; is Yoast active and the fixture public?' );

		$service = null;
		foreach ( $graph as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$type = $node['@type'] ?? null;
			$type = is_array( $type ) ? $type : array( $type );
			if ( in_array( 'Service', $type, true ) ) {
				$service = $node;
				break;
			}
		}

		if ( null === $service ) {
			$this->failures[] = 'No Service node is present in the rendered graph.';
			return;
		}

		$this->assert_true(
			isset( $service['areaServed'] ) && array() !== (array) $service['areaServed'],
			'The Service node has no areaServed entries.'
		);
		$this->assert_true(
			isset( $service['hasOfferCatalog'] ),
			'The Service node has no hasOfferCatalog entry.'
		);
	}

	/**
	 * Proves optimistic concurrency on the Service write.
	 *
	 * @return void
	 */
	private function verify_stale_token_rejected(): void {
		$stale    = 'deadbeefdeadbeef:2020-01-01 00:00:00';
		$rejected = false;

		try {
			$this->update_use_case()->execute(
				array(
					'post_id'       => $this->post_id,
					'version_token' => $stale,
					'name'          => 'Should never be written',
				),
				get_current_user_id()
			);
		} catch ( MutationConflict $conflict ) {
			$rejected = true;
		}

		$this->assert_true( $rejected, 'A stale version token did not raise a mutation conflict.' );

		$current = $this->get_use_case()->execute( array( 'post_id' => $this->post_id ) );
		$this->assert_true(
			'Should never be written' !== ( $current->service_schema['name'] ?? null ),
			'A stale-token write changed the stored Service name.'
		);
	}

	/**
	 * Restores the site to its pre-verification state.
	 *
	 * @return void
	 */
	private function tear_down(): void {
		if ( 0 !== $this->post_id ) {
			wp_delete_post( $this->post_id, true );
		}

		foreach ( $this->saved_options as $option => $value ) {
			if ( self::MISSING_OPTION === $value ) {
				delete_option( $option );
				continue;
			}

			update_option( $option, $value, false );
		}

		wp_set_current_user( $this->original_user_id );
	}

	/**
	 * Builds the shared access manager.
	 *
	 * @return ContentAccessManager
	 */
	private function access(): ContentAccessManager {
		return new ContentAccessManager(
			new WordPressContentAccessSettingsRepository(),
			new WordPressContentTypeCatalog()
		);
	}

	/**
	 * Builds the read use case from real adapters.
	 *
	 * @return GetServiceSchema
	 */
	private function get_use_case(): GetServiceSchema {
		return new GetServiceSchema(
			$this->access(),
			new WordPressContentMutationRepository(),
			new SchemaExtendedServiceSchemaWriter()
		);
	}

	/**
	 * Builds the preview use case from real adapters.
	 *
	 * @return PreviewServiceSchema
	 */
	private function preview_use_case(): PreviewServiceSchema {
		return new PreviewServiceSchema(
			$this->access(),
			new WordPressContentMutationRepository(),
			new SchemaExtendedServiceSchemaWriter()
		);
	}

	/**
	 * Builds the write use case from real adapters.
	 *
	 * @return UpdateServiceSchema
	 */
	private function update_use_case(): UpdateServiceSchema {
		return new UpdateServiceSchema(
			$this->access(),
			new WordPressContentMutationRepository(),
			new SchemaExtendedServiceSchemaWriter(),
			new WordPressAuditLog()
		);
	}

	/**
	 * Records a failure message when the condition does not hold.
	 *
	 * @param bool   $condition Assertion condition.
	 * @param string $message   Failure message.
	 * @return void
	 */
	private function assert_true( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}
}

( new WPCB_Service_Schema_Verification() )->run();
