<?php
/**
 * Runtime verification for the Custom Schema read/preview/write surface.
 *
 * Run: wp eval 'require "<abs path>/tests/Integration/schema-custom-verification.php";'
 *
 * Requires the standalone IsuDev Schema Extended plugin active for its public
 * Integration_API contract, and Yoast SEO active so the merged graph can be
 * inspected. The release gate for this slice is registration plus merged-graph
 * output, so the verifier reads the rendered front-end JSON-LD and asserts the
 * custom node coexists with Yoast's own nodes.
 *
 * @package IsuDev\WPContentBridge\Tests
 */

declare(strict_types=1);

use IsuDev\SchemaExtended\Service\Meta_Fields;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\Mutation\GetCustomSchema;
use IsuDev\WPContentBridge\Application\Mutation\MutationConflict;
use IsuDev\WPContentBridge\Application\Mutation\PreviewCustomSchema;
use IsuDev\WPContentBridge\Application\Mutation\UpdateCustomSchema;
use IsuDev\WPContentBridge\Infrastructure\SchemaExtended\SchemaExtendedCustomSchemaProvider;
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
 * Fail-fast runtime verifier for the Custom Schema abilities.
 */
final class WPCB_Custom_Schema_Verification {

	private const MISSING_OPTION = '__wpcb_custom_schema_missing__';

	private const NODE_TYPE = 'HowTo';

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
			$this->verify_invalid_source_is_reported_without_writing();
			$this->verify_read_preview_write();
			$this->verify_merged_graph();
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
		$this->assert_true(
			( new SchemaExtendedCustomSchemaProvider() )->is_available(),
			'Schema Extended Custom Schema is not available; activate the standalone plugin before running this verifier.'
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
		 * Integration_API exposes no supported-post-type accessor — an unsupported
		 * target is only reported as an error at call time. Meta_Fields, from the
		 * same plugin, is the one public discovery surface, so the fixture uses it
		 * rather than hard-coding a type this provider may not accept.
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
				'post_title'   => 'WPCB custom schema fixture',
				'post_content' => 'Fixture for the Custom Schema runtime verifier.',
			),
			true
		);
		$this->assert_true( ! is_wp_error( $post_id ), 'Could not create the Custom Schema fixture post.' );
		$this->post_id = (int) $post_id;
	}

	/**
	 * Proves preview reports invalid prospective JSON without writing.
	 *
	 * @return void
	 */
	private function verify_invalid_source_is_reported_without_writing(): void {
		$read = $this->get_use_case()->execute( array( 'post_id' => $this->post_id ) );

		$preview = $this->preview_use_case()->execute(
			array(
				'post_id'       => $this->post_id,
				'version_token' => $read->version->to_string(),
				'enabled'       => true,
				'source'        => '{ this is not valid JSON',
			)
		);

		$validation = $preview->preview_custom_schema['validation'] ?? array();
		$this->assert_true(
			false === ( $validation['valid'] ?? null ),
			'Preview did not report invalid prospective JSON as invalid.'
		);

		$after = $this->get_use_case()->execute( array( 'post_id' => $this->post_id ) );
		$this->assert_true(
			$after->custom_schema === $read->custom_schema,
			'Preview of an invalid source mutated the stored Custom Schema configuration.'
		);
	}

	/**
	 * Proves read, non-mutating preview, and an effective write.
	 *
	 * @return void
	 */
	private function verify_read_preview_write(): void {
		$read    = $this->get_use_case()->execute( array( 'post_id' => $this->post_id ) );
		$initial = $read->custom_schema;
		$source  = $this->valid_source();

		$preview    = $this->preview_use_case()->execute(
			array(
				'post_id'       => $this->post_id,
				'version_token' => $read->version->to_string(),
				'enabled'       => true,
				'source'        => $source,
			)
		);
		$validation = $preview->preview_custom_schema['validation'] ?? array();
		$this->assert_true( true === ( $validation['valid'] ?? null ), 'Preview rejected a valid prospective source.' );
		$this->assert_true( array() !== $preview->changed_fields, 'Preview reported no changed fields for a changing payload.' );

		$after_preview = $this->get_use_case()->execute( array( 'post_id' => $this->post_id ) );
		$this->assert_true(
			$after_preview->custom_schema === $initial,
			'Preview mutated the stored Custom Schema configuration.'
		);

		$result = $this->update_use_case()->execute(
			array(
				'post_id'       => $this->post_id,
				'version_token' => $after_preview->version->to_string(),
				'enabled'       => true,
				'source'        => $source,
			),
			get_current_user_id()
		);

		$effective = $result->effective_custom_schema;
		$this->assert_true( true === ( $effective['enabled'] ?? null ), 'Custom Schema was not enabled by the write.' );
		$this->assert_true(
			is_string( $effective['source'] ?? null ) && str_contains( (string) $effective['source'], self::NODE_TYPE ),
			'The effective Custom Schema source does not contain the written node.'
		);
	}

	/**
	 * Proves the custom node reaches the public graph alongside Yoast's nodes.
	 *
	 * @return void
	 */
	private function verify_merged_graph(): void {
		$permalink = get_permalink( $this->post_id );
		$this->assert_true( is_string( $permalink ) && '' !== $permalink, 'Could not resolve the fixture permalink.' );

		$graph = ( new WordPressRenderedSchemaReader( home_url( '/' ) ) )->graph_for_url( (string) $permalink );
		$this->assert_true( array() !== $graph, 'The rendered schema graph is empty; is Yoast active and the fixture public?' );

		$types = array();
		foreach ( $graph as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$type  = $node['@type'] ?? null;
			$types = array_merge( $types, is_array( $type ) ? $type : array( $type ) );
		}

		$this->assert_true(
			in_array( self::NODE_TYPE, $types, true ),
			'The written custom node is missing from the rendered graph.'
		);
		$this->assert_true(
			in_array( 'WebPage', $types, true ) || in_array( 'Article', $types, true ),
			'Yoast base nodes are missing; the custom node replaced the graph instead of merging into it.'
		);
	}

	/**
	 * Proves optimistic concurrency on the Custom Schema write.
	 *
	 * @return void
	 */
	private function verify_stale_token_rejected(): void {
		$before   = $this->get_use_case()->execute( array( 'post_id' => $this->post_id ) );
		$rejected = false;

		try {
			$this->update_use_case()->execute(
				array(
					'post_id'       => $this->post_id,
					'version_token' => 'deadbeefdeadbeef:2020-01-01 00:00:00',
					'enabled'       => false,
					'source'        => '',
				),
				get_current_user_id()
			);
		} catch ( MutationConflict $conflict ) {
			$rejected = true;
		}

		$this->assert_true( $rejected, 'A stale version token did not raise a mutation conflict.' );

		$after = $this->get_use_case()->execute( array( 'post_id' => $this->post_id ) );
		$this->assert_true(
			$after->custom_schema === $before->custom_schema,
			'A stale-token write changed the stored Custom Schema configuration.'
		);
	}

	/**
	 * Returns a small valid custom node source.
	 *
	 * @return string
	 */
	private function valid_source(): string {
		return (string) wp_json_encode(
			array(
				'@type' => self::NODE_TYPE,
				'name'  => 'WPCB verifier procedure',
				'step'  => array(
					array(
						'@type' => 'HowToStep',
						'text'  => 'Run the WP Content Bridge runtime verifier.',
					),
				),
			)
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
	 * @return GetCustomSchema
	 */
	private function get_use_case(): GetCustomSchema {
		return new GetCustomSchema(
			$this->access(),
			new WordPressContentMutationRepository(),
			new SchemaExtendedCustomSchemaProvider()
		);
	}

	/**
	 * Builds the preview use case from real adapters.
	 *
	 * @return PreviewCustomSchema
	 */
	private function preview_use_case(): PreviewCustomSchema {
		return new PreviewCustomSchema(
			$this->access(),
			new WordPressContentMutationRepository(),
			new SchemaExtendedCustomSchemaProvider()
		);
	}

	/**
	 * Builds the write use case from real adapters.
	 *
	 * @return UpdateCustomSchema
	 */
	private function update_use_case(): UpdateCustomSchema {
		return new UpdateCustomSchema(
			$this->access(),
			new WordPressContentMutationRepository(),
			new SchemaExtendedCustomSchemaProvider(),
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

( new WPCB_Custom_Schema_Verification() )->run();
