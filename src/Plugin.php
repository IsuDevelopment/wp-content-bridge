<?php
/**
 * Plugin composition root.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge;

use IsuDev\WPContentBridge\Adapter\Admin\ContentAccessSettingsPage;
use IsuDev\WPContentBridge\Adapter\Abilities\ContentAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\MediaAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\MutationAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\PatternAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\SeoAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\ServiceSchemaAbilities;
use IsuDev\WPContentBridge\Adapter\Abilities\TrashAbilities;
use IsuDev\WPContentBridge\Application\Access\IntegrationAccessManager;
use IsuDev\WPContentBridge\Application\Content\GetContent;
use IsuDev\WPContentBridge\Application\Content\SearchContent;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\Editorial\GetEditorialContext;
use IsuDev\WPContentBridge\Application\Mutation\CreateDraft;
use IsuDev\WPContentBridge\Application\Mutation\GetServiceSchema;
use IsuDev\WPContentBridge\Application\Mutation\PreviewServiceSchema;
use IsuDev\WPContentBridge\Application\Mutation\UpdateContent;
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
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;
use IsuDev\WPContentBridge\Infrastructure\WordPress\PhpBlockMarkupValidator;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressAuditLog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressBlockPatternAccess;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressBlockPatternCatalog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentMutationRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentTrashRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentTypeCatalog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressEditorialContextRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressIntegrationAccessRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressMediaRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressPostCacheInvalidator;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressRenderedSchemaReader;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressSeoImageRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressTaxonomyCatalog;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressSeoTargetAccess;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressTransientIdempotencyStore;
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
				new IntegrationAccessManager( new WordPressIntegrationAccessRepository() )
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
			new GetEditorialContext( $manager, $search, new WordPressEditorialContextRepository(), $seo_providers ),
			$manager,
			$seo_providers
		) )->register_hooks();
		( new SeoAbilities(
			new GetSeo( $seo_providers, new WordPressSeoTargetAccess( $manager, $content_repository ) ),
			new SameSiteSeoTargetFactory( home_url( '/' ) )
		) )->register_hooks();

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
				new UpdateSeo( $manager, $mutation_repository, $seo_writer, $audit_log )
			) )->register_hooks();

			$service_schema_writer = new SchemaExtendedServiceSchemaWriter();
			if ( $service_schema_writer->is_available() ) {
				( new ServiceSchemaAbilities(
					new GetServiceSchema( $manager, $mutation_repository, $service_schema_writer ),
					new PreviewServiceSchema( $manager, $mutation_repository, $service_schema_writer ),
					new UpdateServiceSchema( $manager, $mutation_repository, $service_schema_writer, $audit_log )
				) )->register_hooks();
			}

			if ( get_option( Installer::TRASH_ENABLED_OPTION ) ) {
				( new TrashAbilities(
					new TrashContent( $manager, new WordPressContentTrashRepository(), $audit_log )
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
