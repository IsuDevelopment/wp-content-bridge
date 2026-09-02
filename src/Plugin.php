<?php
/**
 * Plugin composition root.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge;

use IsuDev\WPContentBridge\Adapter\Admin\ContentAccessSettingsPage;
use IsuDev\WPContentBridge\Adapter\Abilities\CreateRedirectAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\DeleteRedirectAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\UpdateRedirectAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\SearchRedirectsAbilities;
use IsuDev\WPContentBridge\Application\Redirect\CreateRedirect;
use IsuDev\WPContentBridge\Application\Redirect\DeleteRedirect;
use IsuDev\WPContentBridge\Application\Redirect\NullRedirectProvider;
use IsuDev\WPContentBridge\Application\Redirect\RedirectCandidateGuard;
use IsuDev\WPContentBridge\Application\Redirect\RedirectProviderRegistry;
use IsuDev\WPContentBridge\Application\Redirect\SearchRedirects;
use IsuDev\WPContentBridge\Application\Redirect\UpdateRedirect;
use IsuDev\WPContentBridge\Infrastructure\Redirection\RedirectionProvider;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressPublishedPermalinkLookup;
use IsuDev\WPContentBridge\Infrastructure\Yoast\YoastPremiumRedirectProvider;
use IsuDev\WPContentBridge\Adapter\Abilities\AbilityInvocationTelemetry;
use IsuDev\WPContentBridge\Adapter\Abilities\BlockMutationAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\ContentAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\CustomSchemaAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\GetStatusTransitionsAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\LlmsAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\CreateMediaAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\FeaturedImageAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\MediaAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\MutationAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\PatternAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\RestoreTrashedContentAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\SeoAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\ServiceSchemaAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\TrashAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\TransitionContentStatusAbilities;
use IsuDev\WPContentBridge\Adapter\Mcp\McpServerProvider;
use IsuDev\WPContentBridge\Application\Access\IntegrationAccessManager;
use IsuDev\WPContentBridge\Application\Status\GetStatusTransitions;
use IsuDev\WPContentBridge\Application\Status\StatusTransitionManager;
use IsuDev\WPContentBridge\Application\Status\TransitionContentStatus;
use IsuDev\WPContentBridge\Application\Content\GetBlockTree;
use IsuDev\WPContentBridge\Application\Content\GetContent;
use IsuDev\WPContentBridge\Application\Content\SearchContent;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\Editorial\GetEditorialContext;
use IsuDev\WPContentBridge\Application\Llms\AdoptLlmsTxtOwnership;
use IsuDev\WPContentBridge\Application\Llms\GetLlmsTxt;
use IsuDev\WPContentBridge\Application\Llms\LlmsInitialConfigFactory;
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
use IsuDev\WPContentBridge\Application\Media\CreateMedia;
use IsuDev\WPContentBridge\Application\Media\MediaAccessManager;
use IsuDev\WPContentBridge\Application\Media\UpdateFeaturedImage;
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
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressInvocationLog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressLlmsArtifactStore;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressLlmsLegacyArtifactArchiver;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressLlmsOwnershipInspector;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressLlmsSourceSelector;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressMediaRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressPostCacheInvalidator;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressFeaturedImageRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressMediaUploader;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressSchemaTargetReader;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressRenderedSchemaReader;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressSeoImageRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressSiteClock;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressStatusTransitionRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressStatusTransitionTargetRepository;
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

		// Shared with the admin settings screen below and with the always-on
		// get-status-transitions read further down: the effective transition
		// graph is needed regardless of `wpcb_writes_enabled`.
		$status_transitions = new StatusTransitionManager( new WordPressStatusTransitionRepository() );
		$llms_store         = new WordPressLlmsArtifactStore();
		$llms_ownership     = new WordPressLlmsOwnershipInspector();
		$llms_audit_log     = new WordPressAuditLog();
		$get_llms           = new GetLlmsTxt( $llms_store, $llms_ownership, home_url( '/' ) );

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
		 * `wpcb_publish_enabled` gates the `publish`/`future` targets of
		 * `transition-content-status` (below) and is also reported by the
		 * always-on `get-status-transitions` read, so it is read once here
		 * rather than twice.
		 */
		$publish_enabled          = (bool) get_option( Installer::PUBLISH_ENABLED_OPTION );
		$status_target_repository = new WordPressStatusTransitionTargetRepository();
		$site_clock               = new WordPressSiteClock();

		/*
		 * get-status-transitions (Slice 2 task 3, ADR 0024) is a read: it
		 * must remain available regardless of `wpcb_writes_enabled`, exactly
		 * like the other reads registered above. The write half of this
		 * feature area, transition-content-status, is registered further
		 * below, only inside the `wpcb_writes_enabled` block.
		 */
		( new GetStatusTransitionsAbilities(
			new GetStatusTransitions( $manager, $status_target_repository, $status_transitions, $site_clock, $publish_enabled )
		) )->register_hooks();

		/*
		 * llms.txt: get-llms-txt must remain available regardless of
		 * wpcb_llms_enabled, so this registers unconditionally rather than
		 * being gated the way the write-only feature areas below are.
		 * LlmsAbilities itself withholds the three writes while the flag is
		 * off (ADR 0023).
		 */
		$llms_selector   = new WordPressLlmsSourceSelector( $seo_providers );
		$llms_builder    = new LlmsDocumentBuilder();
		$update_llms     = new UpdateLlmsTxt( $llms_store, $llms_selector, $llms_builder, $llms_audit_log, $llms_ownership, home_url( '/' ) );
		$regenerate_llms = new RegenerateLlmsTxt( $llms_store, $llms_selector, $llms_builder, $llms_audit_log, $llms_ownership );

		if ( is_admin() ) {
			( new ContentAccessSettingsPage(
				$manager,
				new IntegrationAccessManager( new WordPressIntegrationAccessRepository() ),
				$status_transitions,
				$get_llms,
				new LlmsInitialConfigFactory(),
				$update_llms,
				$regenerate_llms,
				new AdoptLlmsTxtOwnership(
					$llms_store,
					$llms_ownership,
					new WordPressLlmsLegacyArtifactArchiver(),
					$llms_audit_log
				)
			) )->register_hooks();
		}

		( new LlmsAbilities(
			$get_llms,
			new PreviewUpdateLlmsTxt( $llms_store, $llms_selector, $llms_builder, home_url( '/' ) ),
			$update_llms,
			$regenerate_llms
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
					new GetCustomSchema( $manager, $mutation_repository, $custom_schema_provider, new WordPressSchemaTargetReader() ),
					new PreviewCustomSchema( $manager, $mutation_repository, $custom_schema_provider ),
					new UpdateCustomSchema( $manager, $mutation_repository, $custom_schema_provider, $audit_log )
				) )->register_hooks();
			}

			/*
			 * Importing gets its own switch rather than sharing the
			 * featured-image one: assigning an image the site already holds and
			 * making the site fetch a URL an agent chose are different grants
			 * (ADR 0031 decision 5).
			 */
			if ( $media_access->reads_enabled && get_option( Installer::MEDIA_UPLOADS_ENABLED_OPTION ) ) {
				( new CreateMediaAbilities(
					new CreateMedia(
						$media_access,
						new WordPressMediaUploader( new WordPressMediaRepository() ),
						new WordPressMediaRepository(),
						$idempotency,
						$audit_log
					)
				) )->register_hooks();
			}

			/*
			 * Featured-image writes need three switches, not one: media reads
			 * (the effective result re-reads the attachment), the content-writes
			 * master switch above, and their own flag. An operator who enabled
			 * content writes did not thereby consent to media being mutated.
			 */
			if ( $media_access->reads_enabled && get_option( Installer::MEDIA_WRITES_ENABLED_OPTION ) ) {
				( new FeaturedImageAbilities(
					new UpdateFeaturedImage(
						$manager,
						$media_access,
						$mutation_repository,
						new WordPressFeaturedImageRepository(),
						new WordPressMediaRepository(),
						$audit_log
					)
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

			/*
			 * transition-content-status (Slice 2 task 4, ADR 0024) is
			 * registered only here, inside `wpcb_writes_enabled`: the write
			 * must be unregistered while that flag is off, never
			 * registered-and-refusing. Its own publication gates
			 * (`wpcb_publish_enabled`, `wpcb_publish_content`, native
			 * `publish_post`) are enforced inside the use case regardless.
			 */
			( new TransitionContentStatusAbilities(
				new TransitionContentStatus(
					$manager,
					$status_target_repository,
					$status_transitions,
					$mutation_repository,
					$site_clock,
					$publish_enabled,
					$audit_log
				)
			) )->register_hooks();
		}

		/*
		 * Redirects (Slice 5, ADR 0026 as amended 2026-09-01). The registry
		 * order below is a stable *reporting* order, not a preference: it
		 * never selects a write target, because a write names its provider.
		 * Both adapters are constructed unconditionally and report themselves
		 * unavailable when their plugin is absent, so "no provider" stays
		 * distinguishable from "no redirects".
		 */
		if ( get_option( Installer::REDIRECTS_ENABLED_OPTION ) ) {
			$redirect_providers = new RedirectProviderRegistry(
				array(
					new YoastPremiumRedirectProvider( home_url( '/' ) ),
					new RedirectionProvider( home_url( '/' ) ),
				),
				new NullRedirectProvider()
			);

			( new SearchRedirectsAbilities( new SearchRedirects( $redirect_providers ) ) )->register_hooks();

			/*
			 * The read above is registered by the redirect flag alone, but the
			 * write also requires `wpcb_writes_enabled`, like every other
			 * write in this plugin. A redirect changes routing for real
			 * visitors, so it must disappear with the master write switch
			 * rather than merely refuse at execution time.
			 */
			if ( get_option( Installer::WRITES_ENABLED_OPTION ) ) {
				$redirect_guard = new RedirectCandidateGuard();
				$redirect_audit = new WordPressAuditLog();

				( new CreateRedirectAbilities(
					new CreateRedirect(
						$redirect_providers,
						$redirect_guard,
						new WordPressPublishedPermalinkLookup(),
						$redirect_audit,
						home_url( '/' )
					)
				) )->register_hooks();
				( new UpdateRedirectAbilities(
					new UpdateRedirect( $redirect_providers, $redirect_guard, $redirect_audit, home_url( '/' ) )
				) )->register_hooks();
				( new DeleteRedirectAbilities(
					new DeleteRedirect( $redirect_providers, $redirect_audit )
				) )->register_hooks();
			}
		}

		/*
		 * MCP projection (ADR 0025). Registered last, after every ability
		 * registration above, because it discovers its tool set from the
		 * ability registry rather than from a list: whatever registered in this
		 * request is what gets projected. `mcp_adapter_init` only fires on
		 * installs that added the official Adapter themselves, so this stays a
		 * no-op — and the Adapter stays optional and unbundled — otherwise.
		 */
		if ( McpServerProvider::is_enabled() ) {
			( new McpServerProvider() )->register_hooks();
		}

		/*
		 * Invocation telemetry is an off-by-default diagnostic mode (ADR 0029).
		 * The listener is registered only while the flag is on, so with it off
		 * no hook observes ability execution at all — consistent with this
		 * plugin's rule that a disabled feature is absent rather than
		 * present-and-discarding, and it keeps the read path free of a
		 * per-request write nobody asked for.
		 */
		if ( (bool) get_option( Installer::INVOCATION_TELEMETRY_ENABLED_OPTION, false ) ) {
			( new AbilityInvocationTelemetry( new WordPressInvocationLog() ) )->register_hooks();
		}

		/**
		 * Fires after WP Content Bridge has loaded its composition root.
		 *
		 * Read-only content abilities, and write abilities if enabled, have registered their WordPress hooks.
		 */
		do_action( 'wp_content_bridge_loaded' );
	}
}
