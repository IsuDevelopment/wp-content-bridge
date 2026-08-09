<?php
/**
 * Plugin composition root.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge;

use IsuDev\WPContentBridge\Adapter\Admin\ContentAccessSettingsPage;
use IsuDev\WPContentBridge\Adapter\Abilities\BlockMutationAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\ContentAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\CustomSchemaAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\LlmsAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\MediaAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\MutationAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\PatternAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\RestoreTrashedContentAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\SeoAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\ServiceSchemaAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\TrashAbilities;
use IsuDev\WPContentBridge\Application\Access\IntegrationAccessManager;
use IsuDev\WPContentBridge\Application\Status\StatusTransitionManager;
use IsuDev\WPContentBridge\Application\Content\GetBlockTree;
use IsuDev\WPContentBridge\Application\Content\GetContent;
use IsuDev\WPContentBridge\Application\Content\SearchContent;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\Editorial\GetEditorialContext;
use IsuDev\WPContentBridge\Application\Llms\GetLlmsTxt;
use IsuDev\WPContentBridge\Application\Llms\PreviewUpdateLlmsTxt;
use IsuDev\WPContentBridge\Application\Llms\RegenerateLlmsTxt;
use IsuDev\WPContentBridge\Application\Llms\UpdateLlmsTxt;
use IsuDev\WPContentBridge\Application\Mutation\CreateDraft;
use IsuDev\WPContentBridge\Application\Mutation\GetCustomSchema;
use IsuDev\WPContentBridge\Application\Mutation\GetServiceSchema;
use IsuDev\WPContentBridge\Application\Mutation\PreviewBlockUpdate;
use IsuDev\WPContentBridge\Application\Mutation\PreviewContentUpdate;
use IsuDev\WPContentBridge\Application\Mutation\PreviewCustomSchema;
use IsuDev\WPContentBridge\Application\Mutation\PreviewSeoUpdate;
use IsuDev\WPContentBridge\Application\Mutation\PreviewServiceSchema;
use IsuDev\WPContentBridge\Application\Mutation\RestoreTrashedContent;
use IsuDev\WPContentBridge\Application\Mutation\UpdateBlock;
use IsuDev\WPContentBridge\Application\Mutation\UpdateBlockAttributes;
use IsuDev\WPContentBridge\Application\Mutation\UpdateContent;
use IsuDev\WPContentBridge\Application\Mutation\UpdateCustomSchema;
use IsuDev\WPContentBridge\Application\Mutation\UpdateSeo;
use IsuDev\WPContentBridge\Application\Mutation\UpdateServiceSchema;
use IsuDev\WPContentBridge\Application\Mutation\TrashContent;
use IsuDev\WPContentBridge\Application\Pattern\ListBlockPatterns;
use IsuDev\WPContentBridge\Application\Pattern\PatternAccessManager;
use IsuDev\WPContentBridge\Application\Media\GetMediaById;
use IsuDev\WPContentBridge\Application\Media\MediaAccessManager;
use IsuDev\WPContentBridge\Application\Media\SearchMedia;
use IsuDev\WPContentBridge\Application\Seo\NullSeoProvider;
use IsuDev\WPContentBridge\Application\Seo\GetSeo;
use IsuDev\WPContentBridge\Application\Seo\SameSiteSeoTargetFactory;
use IsuDev\WPContentBridge\Application\Seo\SeoProvider;
use IsuDev\WPContentBridge\Application\Seo\SeoProviderRegistry;
use IsuDev\WPContentBridge\Domain\Llms\LlmsDocumentBuilder;
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;
use IsuDev\WPContentBridge\Infrastructure\WordPress\LlmsRegenerationRunner;
use IsuDev\WPContentBridge\Infrastructure\WordPress\LlmsRegenerationScheduler;
use IsuDev\WPContentBridge\Infrastructure\WordPress\LlmsTxtEndpoint;
use IsuDev\WPContentBridge\Infrastructure\WordPress\PhpBlockMarkupValidator;
use IsuDev\WPContentBridge\Infrastructure\WordPress\PhpBlockTreeSplicer;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressAuditLog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressBlockPatternAccess;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressBlockPatternCatalog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressBlockTreeRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentMutationRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentTrashRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentTypeCatalog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressEditorialContextRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressIntegrationAccessRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressLlmsArtifactStore;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressLlmsOwnershipInspector;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressLlmsSourceSelector;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressMediaRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressPostCacheInvalidator;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressRenderedSchemaReader;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressSeoImageRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressStatusTransitionRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressTaxonomyCatalog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressSeoTargetAccess;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressTransientIdempotencyStore;
use IsuDev\WPContentBridge\Infrastructure\SchemaExtended\SchemaExtendedCustomSchemaProvider;
use IsuDev\WPContentBridge\Infrastructure\SchemaExtended\SchemaExtendedServiceSchemaWriter;
use IsuDev\WPContentBridge\Infrastructure\Yoast\YoastSeoWriter;
use IsuDev\WPContentBridge\Infrastructure\Yoast\YoastSeoProvider;

/**
 * Composes the plugin's infrastructure adapters.
 *
 * Keep domain behavior out of this class. It is a bootstrap/composition root only.
 */
final class Plugin {

	/**
	 * Whether the composition root has already run.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Boots the plugin once.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;
		Installer::maybe_upgrade();

		$manager = new ContentAccessManager(
			new WordPressContentAccessSettingsRepository(),
			new WordPressContentTypeCatalog()
		);

		if ( is_admin() ) {

			( new ContentAccessSettingsPage(
				$manager,
				new IntegrationAccessManager( new WordPressIntegrationAccessRepository() ),
				new StatusTransitionManager( new WordPressStatusTransitionRepository() )
			) )->register_hooks();
		}

		$content_repository = new WordPressContentRepository();
		$taxonomy_catalog   = new WordPressTaxonomyCatalog();
		$search             = new SearchContent( $manager, $content_repository, $taxonomy_catalog );
		/**
		 * Filters optional provider-neutral SEO adapters in explicit priority order.
		 *
		 * Invalid values are ignored; adapters must implement SeoProvider.
		 *
		 * @param array $providers SEO provider adapters.
		 * @phpstan-param list<SeoProvider> $providers
		 */
		$providers     = apply_filters( 'wp_content_bridge_seo_providers', array( new YoastSeoProvider( new WordPressRenderedSchemaReader( home_url( '/' ) ) ) ) );
		$providers     = is_array( $providers )
			? array_values( array_filter( $providers, static fn ( mixed $provider ): bool => $provider instanceof SeoProvider ) )
			: array();
		$seo_providers = new SeoProviderRegistry( $providers, new NullSeoProvider() );
		( new ContentAbilities(
			$search,
			new GetContent( $manager, $content_repository ),
			new GetBlockTree( $manager, new WordPressBlockTreeRepository() ),
			new GetEditorialContext( $manager, $search, new WordPressEditorialContextRepository(), $seo_providers ),
			$manager,
			$seo_providers
		) )->register_hooks();
		( new SeoAbilities(
			new GetSeo( $seo_providers, new WordPressSeoTargetAccess( $manager, $content_repository ) ),
			new SameSiteSeoTargetFactory( home_url( '/' ) )
		) )->register_hooks();

		/*
		 * llms.txt: get-llms-txt must remain available regardless of
		 * wpcb_llms_enabled, so this registers unconditionally rather than
		 * being gated the way the write-only feature areas below are.
		 * LlmsAbilities itself withholds the three writes while the flag is
		 * off (ADR 0023).
		 */
		$llms_store     = new WordPressLlmsArtifactStore();
		$llms_selector  = new WordPressLlmsSourceSelector( $seo_providers );
		$llms_builder   = new LlmsDocumentBuilder();
		$llms_audit_log = new WordPressAuditLog();

		( new LlmsAbilities(
			new GetLlmsTxt( $llms_store, new WordPressLlmsOwnershipInspector() ),
			new PreviewUpdateLlmsTxt( $llms_store, $llms_selector, $llms_builder, home_url( '/' ) ),
			new UpdateLlmsTxt( $llms_store, $llms_selector, $llms_builder, $llms_audit_log, home_url( '/' ) ),
			new RegenerateLlmsTxt( $llms_store, $llms_selector, $llms_builder, $llms_audit_log )
		) )->register_hooks();

		/*
		 * The virtual `/llms.txt` route (ADR 0023). The flush watcher is
		 * registered unconditionally so a flag flip in either direction gets
		 * queued for the next request's `init`; the rewrite rule itself is
		 * registered only while the flag is on, per the ADR's off-means-
		 * never-installed requirement — see LlmsTxtEndpoint::register_hooks().
		 */
		add_action( 'init', array( LlmsTxtEndpoint::class, 'maybe_flush_rewrite_rules' ), 20 );
		LlmsTxtEndpoint::register_flush_watcher();
		if ( get_option( Installer::LLMS_ENABLED_OPTION ) ) {
			( new LlmsTxtEndpoint( $llms_store ) )->register_hooks();
		}

		/*
		 * Debounced llms.txt regeneration (ADR 0023 task 6). The trigger
		 * wiring and the cron batch handler are both registered
		 * unconditionally, matching LlmsAbilities' own pattern: each checks
		 * `Installer::LLMS_ENABLED_OPTION` and the stored configuration
		 * itself before doing anything. The flag watcher is registered
		 * unconditionally for the same reason LlmsTxtEndpoint's flush watcher
		 * is — it must observe a flag flip to false in either request.
		 */
		( new LlmsRegenerationScheduler( $llms_store ) )->register_hooks();
		LlmsRegenerationScheduler::register_flag_watcher();
		( new LlmsRegenerationRunner( $llms_store, $llms_selector, $llms_builder ) )->register_hooks();

		$media_access = new MediaAccessManager( (bool) get_option( Installer::MEDIA_READS_ENABLED_OPTION ) );
		if ( $media_access->reads_enabled ) {
			$media_repository = new WordPressMediaRepository();
			( new MediaAbilities(
				new SearchMedia( $media_access, $media_repository ),
				new GetMediaById( $media_access, $media_repository )
			) )->register_hooks();
		}

		$pattern_access = new PatternAccessManager(
			(bool) get_option( Installer::PATTERN_READS_ENABLED_OPTION ),
			new WordPressBlockPatternAccess()
		);
		if ( $pattern_access->reads_enabled ) {
			( new PatternAbilities(
				$pattern_access,
				new ListBlockPatterns( $pattern_access, new WordPressBlockPatternCatalog() )
			) )->register_hooks();
		}

		if ( get_option( Installer::WRITES_ENABLED_OPTION ) ) {
			$mutation_repository = new WordPressContentMutationRepository();
			$block_validator     = new PhpBlockMarkupValidator();
			$idempotency         = new WordPressTransientIdempotencyStore();
			$audit_log           = new WordPressAuditLog();
			$seo_writer          = new YoastSeoWriter( $seo_providers->active(), new WordPressSeoImageRepository() );
			( new WordPressPostCacheInvalidator() )->register_hooks();

			( new MutationAbilities(
				new CreateDraft( $manager, $block_validator, $mutation_repository, $idempotency, $audit_log ),
				new UpdateContent( $manager, $block_validator, $mutation_repository, $audit_log ),
				new UpdateSeo( $manager, $mutation_repository, $seo_writer, $audit_log ),
				new PreviewContentUpdate( $manager, $block_validator, $mutation_repository, $mutation_repository ),
				new PreviewSeoUpdate( $manager, $mutation_repository, $seo_writer )
			) )->register_hooks();

			$block_splicer = new PhpBlockTreeSplicer();
			( new BlockMutationAbilities(
				new UpdateBlock( $manager, $mutation_repository, $mutation_repository, $block_splicer, $block_validator, $audit_log ),
				new PreviewBlockUpdate( $manager, $mutation_repository, $mutation_repository, $block_splicer, $block_validator ),
				new UpdateBlockAttributes( $manager, $mutation_repository, $mutation_repository, $block_splicer, $audit_log )
			) )->register_hooks();

			$service_schema_writer = new SchemaExtendedServiceSchemaWriter();
			if ( $service_schema_writer->is_available() ) {
				( new ServiceSchemaAbilities(
					new GetServiceSchema( $manager, $mutation_repository, $service_schema_writer ),
					new PreviewServiceSchema( $manager, $mutation_repository, $service_schema_writer ),
					new UpdateServiceSchema( $manager, $mutation_repository, $service_schema_writer, $audit_log )
				) )->register_hooks();
			}

			$custom_schema_provider = new SchemaExtendedCustomSchemaProvider();
			if ( $custom_schema_provider->is_available() ) {
				( new CustomSchemaAbilities(
					new GetCustomSchema( $manager, $mutation_repository, $custom_schema_provider ),
					new PreviewCustomSchema( $manager, $mutation_repository, $custom_schema_provider ),
					new UpdateCustomSchema( $manager, $mutation_repository, $custom_schema_provider, $audit_log )
				) )->register_hooks();
			}

			if ( get_option( Installer::TRASH_ENABLED_OPTION ) ) {
				$trash_repository = new WordPressContentTrashRepository();
				( new TrashAbilities(
					new TrashContent( $manager, $trash_repository, $audit_log )
				) )->register_hooks();
				( new RestoreTrashedContentAbilities(
					new RestoreTrashedContent( $manager, $trash_repository, $audit_log )
				) )->register_hooks();
			}
		}

		/**
		 * Fires after WP Content Bridge has loaded its composition root.
		 *
		 * Read-only content abilities, and write abilities if enabled, have registered their WordPress hooks.
		 */
		do_action( 'wp_content_bridge_loaded' );
	}
}
