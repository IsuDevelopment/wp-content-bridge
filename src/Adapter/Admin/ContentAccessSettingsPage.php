<?php
/**
 * Content access settings page.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Admin;

use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentAccessSettingsRepository;

/**
 * Thin WordPress Settings API adapter for content-type policies.
 */
final readonly class ContentAccessSettingsPage {

	private const OPTION_GROUP = 'wpcb_content_access';

	/**
	 * Creates the Settings API adapter.
	 *
	 * @param ContentAccessManager $manager Shared access policy service.
	 */
	public function __construct( private ContentAccessManager $manager ) {
	}

	/**
	 * Registers WordPress admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_filter( 'option_page_capability_' . self::OPTION_GROUP, array( $this, 'settings_capability' ) );
	}

	/**
	 * Registers the page below WordPress Settings.
	 *
	 * @return void
	 */
	public function register_page(): void {
		add_options_page(
			esc_html__( 'WP Content Bridge', 'wp-content-bridge' ),
			esc_html__( 'WP Content Bridge', 'wp-content-bridge' ),
			'wpcb_manage_settings',
			'wp-content-bridge',
			array( $this, 'render' )
		);
	}

	/**
	 * Registers the matrix as a non-REST option.
	 *
	 * @return void
	 */
	public function register_setting(): void {
		register_setting(
			self::OPTION_GROUP,
			WordPressContentAccessSettingsRepository::OPTION_NAME,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			Installer::WRITES_ENABLED_OPTION,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => static fn ( mixed $value ): bool => (bool) $value,
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Normalizes settings submitted through options.php.
	 *
	 * @param mixed $value Submitted option value.
	 * @return array<string, array<string, bool>>
	 */
	public function sanitize( mixed $value ): array {
		return $this->manager->normalize_submitted( $value );
	}

	/**
	 * Returns the dedicated settings capability.
	 *
	 * @return string
	 */
	public function settings_capability(): string {
		return 'wpcb_manage_settings';
	}

	/**
	 * Renders the settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'wpcb_manage_settings' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage WP Content Bridge settings.', 'wp-content-bridge' ) );
		}

		$operations = $this->operation_labels();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WP Content Bridge: Content Access', 'wp-content-bridge' ); ?></h1>
			<p><?php echo esc_html__( 'Choose which operations agent integrations may request for each content type. WordPress capabilities are always checked in addition to this configuration.', 'wp-content-bridge' ); ?></p>
			<p><?php echo esc_html__( 'Write switches configure per-type policy. The global switch below must also be enabled for create-draft and update-content to become available.', 'wp-content-bridge' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION_GROUP ); ?>
				<table class="widefat striped" aria-describedby="wpcb-content-access-help">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html__( 'Content type', 'wp-content-bridge' ); ?></th>
							<?php foreach ( $operations as $label ) : ?>
								<th scope="col"><?php echo esc_html( $label ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $this->manager->content_types() as $definition ) : ?>
							<?php $policy = $this->manager->policy_for( $definition->name ); ?>
							<tr>
								<th scope="row">
									<?php echo esc_html( $definition->label ); ?>
									<code><?php echo esc_html( $definition->name ); ?></code>
								</th>
								<?php foreach ( $operations as $operation_value => $label ) : ?>
									<?php $operation = ContentOperation::from( $operation_value ); ?>
									<td>
										<input type="hidden" name="<?php echo esc_attr( WordPressContentAccessSettingsRepository::OPTION_NAME ); ?>[<?php echo esc_attr( $definition->name ); ?>][<?php echo esc_attr( $operation->value ); ?>]" value="0">
										<label>
											<input type="checkbox" name="<?php echo esc_attr( WordPressContentAccessSettingsRepository::OPTION_NAME ); ?>[<?php echo esc_attr( $definition->name ); ?>][<?php echo esc_attr( $operation->value ); ?>]" value="1" <?php checked( $policy->allows( $operation ) ); ?>>
											<span class="screen-reader-text"><?php echo esc_html( $definition->label . ': ' . $label ); ?></span>
										</label>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p id="wpcb-content-access-help" class="description"><?php echo esc_html__( 'Search and every write operation require Read. Invalid combinations are disabled when settings are saved.', 'wp-content-bridge' ); ?></p>

				<h2><?php echo esc_html__( 'Content writes', 'wp-content-bridge' ); ?></h2>
				<table class="widefat striped" aria-describedby="wpcb-writes-enabled-help">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Content writes', 'wp-content-bridge' ); ?></th>
							<td>
								<input type="hidden" name="<?php echo esc_attr( Installer::WRITES_ENABLED_OPTION ); ?>" value="0">
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Installer::WRITES_ENABLED_OPTION ); ?>" value="1" <?php checked( (bool) get_option( Installer::WRITES_ENABLED_OPTION ) ); ?>>
									<?php echo esc_html__( 'Enable create-draft and update-content abilities (master switch, off by default).', 'wp-content-bridge' ); ?>
								</label>
							</td>
						</tr>
					</tbody>
				</table>
				<p id="wpcb-writes-enabled-help" class="description"><?php echo esc_html__( 'This master switch must be enabled, in addition to per-type Create/Update policy above, for write abilities to be registered.', 'wp-content-bridge' ); ?></p>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Returns localized operation labels keyed by stable operation value.
	 *
	 * @return array<string, string>
	 */
	private function operation_labels(): array {
		return array(
			ContentOperation::READ->value       => esc_html__( 'Read', 'wp-content-bridge' ),
			ContentOperation::SEARCH->value     => esc_html__( 'Search', 'wp-content-bridge' ),
			ContentOperation::CREATE->value     => esc_html__( 'Create draft', 'wp-content-bridge' ),
			ContentOperation::UPDATE->value     => esc_html__( 'Update content', 'wp-content-bridge' ),
			ContentOperation::UPDATE_SEO->value => esc_html__( 'Update SEO', 'wp-content-bridge' ),
			ContentOperation::PUBLISH->value    => esc_html__( 'Publish', 'wp-content-bridge' ),
		);
	}
}
