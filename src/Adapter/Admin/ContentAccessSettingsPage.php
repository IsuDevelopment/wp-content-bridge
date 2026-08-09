<?php
/**
 * Content access settings page.
 *
 * @package IsuDev\WPContentBridge
 */

declare(strict_types=1);

namespace IsuDev\WPContentBridge\Adapter\Admin;

use IsuDev\WPContentBridge\Application\Access\IntegrationAccessManager;
use IsuDev\WPContentBridge\Application\Access\IntegrationAccessProblem;
use IsuDev\WPContentBridge\Application\ContentAccess\ContentAccessManager;
use IsuDev\WPContentBridge\Application\Status\StatusTransitionManager;
use IsuDev\WPContentBridge\Domain\Access\IntegrationCapability;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentOperation;
use IsuDev\WPContentBridge\Domain\ContentAccess\ContentTypeDefinition;
use IsuDev\WPContentBridge\Domain\Status\StatusTransition;
use IsuDev\WPContentBridge\Infrastructure\WordPress\Installer;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressContentAccessSettingsRepository;
use IsuDev\WPContentBridge\Infrastructure\WordPress\WordPressStatusTransitionRepository;
use Throwable;

/**
 * Thin WordPress Settings API adapter for content-type policies.
 */
final readonly class ContentAccessSettingsPage {

	private const OPTION_GROUP            = 'wpcb_content_access';
	private const ACCESS_ACTION           = 'wpcb_update_integration_access';
	private const ACCESS_NONCE            = 'wpcb_integration_access';
	private const PRESET_ACTION           = 'wpcb_apply_status_transition_preset';
	private const PRESET_NONCE            = 'wpcb_status_transition_preset';
	private const PRESET_STATUS_QUERY_ARG = 'wpcb_status_preset_status';

	/**
	 * Creates the Settings API adapter.
	 *
	 * @param ContentAccessManager     $manager             Shared access policy service.
	 * @param IntegrationAccessManager $integration_access   Integration-principal access service.
	 * @param StatusTransitionManager  $status_transitions   Status transition graph configuration service.
	 */
	public function __construct(
		private ContentAccessManager $manager,
		private IntegrationAccessManager $integration_access,
		private StatusTransitionManager $status_transitions,
	) {
	}

	/**
	 * Registers WordPress admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'register_setting' ) );
		add_action( 'admin_post_' . self::ACCESS_ACTION, array( $this, 'update_integration_access' ) );
		add_action( 'admin_post_' . self::PRESET_ACTION, array( $this, 'apply_status_transition_preset' ) );
		add_filter( 'option_page_capability_' . self::OPTION_GROUP, array( $this, 'settings_capability' ) );
	}

	/**
	 * Registers the page below WordPress Settings.
	 *
	 * @return void
	 */
	public function register_page(): void {
		$hook_suffix = add_options_page(
			esc_html__( 'WP Content Bridge', 'wp-content-bridge' ),
			esc_html__( 'WP Content Bridge', 'wp-content-bridge' ),
			'wpcb_manage_settings',
			'wp-content-bridge',
			array( $this, 'render' )
		);

		/*
		 * The returned hook suffix is the only authoritative name for this
		 * screen. Hard-coding `settings_page_wp-content-bridge` would break
		 * silently if the menu parent ever moved, and loading the assets on
		 * every admin screen would be worse.
		 */
		if ( ! is_string( $hook_suffix ) || '' === $hook_suffix ) {
			return;
		}

		add_action(
			'admin_enqueue_scripts',
			function ( string $current_screen ) use ( $hook_suffix ): void {
				if ( $current_screen === $hook_suffix ) {
					$this->enqueue_assets();
				}
			}
		);
	}

	/**
	 * Enqueues the status transition matrix bulk-selection assets.
	 *
	 * Both are progressive enhancement: the bulk toggles submit nothing and
	 * stay hidden without them, so a failed enqueue degrades to the matrix as
	 * it shipped in 0.7.0 rather than to a broken form.
	 *
	 * @return void
	 */
	private function enqueue_assets(): void {
		wp_enqueue_style(
			'wpcb-status-transitions',
			plugins_url( 'assets/admin/status-transitions.css', WPCB_FILE ),
			array(),
			WPCB_VERSION
		);

		wp_enqueue_script(
			'wpcb-status-transitions',
			plugins_url( 'assets/admin/status-transitions.js', WPCB_FILE ),
			array(),
			WPCB_VERSION,
			true
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
				'sanitize_callback' => array( self::class, 'sanitize_checkbox' ),
				'show_in_rest'      => false,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			Installer::MEDIA_READS_ENABLED_OPTION,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => array( self::class, 'sanitize_checkbox' ),
				'show_in_rest'      => false,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			Installer::PATTERN_READS_ENABLED_OPTION,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => array( self::class, 'sanitize_checkbox' ),
				'show_in_rest'      => false,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			Installer::TRASH_ENABLED_OPTION,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => array( self::class, 'sanitize_checkbox' ),
				'show_in_rest'      => false,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			Installer::LLMS_ENABLED_OPTION,
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => array( self::class, 'sanitize_checkbox' ),
				'show_in_rest'      => false,
			)
		);

		register_setting(
			self::OPTION_GROUP,
			WordPressStatusTransitionRepository::OPTION_NAME,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize_status_transitions' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Accepts only the values emitted by WordPress checkboxes.
	 *
	 * @param mixed $value Submitted option value.
	 * @return bool
	 */
	public static function sanitize_checkbox( mixed $value ): bool {
		return true === $value || 1 === $value || '1' === $value || 'yes' === $value || 'on' === $value;
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
	 * Normalizes the status transition matrix submitted through options.php.
	 *
	 * @param mixed $value Submitted option value.
	 * @return array<string, mixed>
	 */
	public function sanitize_status_transitions( mixed $value ): array {
		return $this->status_transitions->normalize_submitted( $value, $this->eligible_post_types() );
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
			<p><?php echo esc_html__( 'Write switches configure per-type policy. The global switch below must also be enabled for create-draft, update-content, update-seo, and trash-content to become available.', 'wp-content-bridge' ); ?></p>

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
				<p class="description"><?php echo esc_html__( 'The Change status column above only enables the transition-content-status ability for a type. Which specific status moves are allowed is configured in the matrix below.', 'wp-content-bridge' ); ?></p>

				<h2><?php echo esc_html__( 'Status transitions', 'wp-content-bridge' ); ?></h2>
				<p><?php echo esc_html__( 'Choose the exact status moves each content type may perform. A pair such as publish → draft does not imply the reverse draft → publish; each direction must be listed on its own.', 'wp-content-bridge' ); ?></p>
				<?php if ( ! $this->status_transitions->is_configured() ) : ?>
					<div class="notice notice-warning inline"><p><?php echo esc_html__( 'Status transitions have never been saved on this site. Every transition is currently denied.', 'wp-content-bridge' ); ?></p></div>
				<?php endif; ?>
				<div id="wpcb-status-transitions" style="overflow-x: auto;">
					<table class="widefat striped" aria-describedby="wpcb-status-transitions-help">
						<thead>
							<tr>
								<th scope="col">
									<?php echo esc_html__( 'Content type', 'wp-content-bridge' ); ?>
									<span class="wpcb-bulk-toggle" hidden>
										<label>
											<input type="checkbox" data-wpcb-scope="all">
											<?php echo esc_html__( 'Select every pair', 'wp-content-bridge' ); ?>
										</label>
									</span>
								</th>
								<?php foreach ( StatusTransition::all_possible() as $pair ) : ?>
									<th scope="col">
										<?php echo esc_html( $pair->from->value . ' → ' . $pair->to->value ); ?>
										<span class="wpcb-bulk-toggle" hidden>
											<label>
												<input type="checkbox" data-wpcb-scope="column" data-wpcb-key="<?php echo esc_attr( $pair->from->value . ':' . $pair->to->value ); ?>">
												<span class="screen-reader-text">
													<?php
													/* translators: %s: a status pair such as "draft to pending". */
													printf( esc_html__( 'Select %s for every content type', 'wp-content-bridge' ), esc_html( $pair->from->value . ' to ' . $pair->to->value ) );
													?>
												</span>
											</label>
										</span>
									</th>
								<?php endforeach; ?>
							</tr>
						</thead>
						<tbody>
							<?php $status_config = $this->status_transitions->config(); ?>
							<?php foreach ( $this->manager->content_types() as $definition ) : ?>
								<tr>
									<th scope="row">
										<?php echo esc_html( $definition->label ); ?>
										<code><?php echo esc_html( $definition->name ); ?></code>
										<span class="wpcb-bulk-toggle" hidden>
											<label>
												<input type="checkbox" data-wpcb-scope="row" data-wpcb-key="<?php echo esc_attr( $definition->name ); ?>">
												<span class="screen-reader-text">
													<?php
													/* translators: %s: a content type label. */
													printf( esc_html__( 'Select every pair for %s', 'wp-content-bridge' ), esc_html( $definition->label ) );
													?>
												</span>
											</label>
										</span>
									</th>
									<?php foreach ( StatusTransition::all_possible() as $pair ) : ?>
										<td>
											<input type="hidden" name="<?php echo esc_attr( WordPressStatusTransitionRepository::OPTION_NAME ); ?>[<?php echo esc_attr( $definition->name ); ?>][<?php echo esc_attr( $pair->from->value ); ?>][<?php echo esc_attr( $pair->to->value ); ?>]" value="0">
											<label>
												<input type="checkbox" name="<?php echo esc_attr( WordPressStatusTransitionRepository::OPTION_NAME ); ?>[<?php echo esc_attr( $definition->name ); ?>][<?php echo esc_attr( $pair->from->value ); ?>][<?php echo esc_attr( $pair->to->value ); ?>]" value="1" data-wpcb-row="<?php echo esc_attr( $definition->name ); ?>" data-wpcb-col="<?php echo esc_attr( $pair->from->value . ':' . $pair->to->value ); ?>" <?php checked( $status_config->graph->permits( $definition->name, $pair->from->value, $pair->to->value ) ); ?>>
												<span class="screen-reader-text"><?php echo esc_html( $definition->label . ': ' . $pair->from->value . ' to ' . $pair->to->value ); ?></span>
											</label>
										</td>
									<?php endforeach; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p id="wpcb-status-transitions-help" class="description"><?php echo esc_html__( 'A pair alone is not enough: a move to publish or future additionally requires the reserved publication flag, the Publish content capability, and native publish_post, none of which are affected by this matrix.', 'wp-content-bridge' ); ?></p>

				<h2><?php echo esc_html__( 'Media reads', 'wp-content-bridge' ); ?></h2>
				<table class="widefat striped" aria-describedby="wpcb-media-reads-enabled-help">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Media library reads', 'wp-content-bridge' ); ?></th>
							<td>
								<input type="hidden" name="<?php echo esc_attr( Installer::MEDIA_READS_ENABLED_OPTION ); ?>" value="0">
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Installer::MEDIA_READS_ENABLED_OPTION ); ?>" value="1" <?php checked( (bool) get_option( Installer::MEDIA_READS_ENABLED_OPTION ) ); ?>>
									<?php echo esc_html__( 'Enable get-media and get-media-by-id abilities (master switch, off by default).', 'wp-content-bridge' ); ?>
								</label>
							</td>
						</tr>
					</tbody>
				</table>
				<p id="wpcb-media-reads-enabled-help" class="description"><?php echo esc_html__( 'The integration principal also needs Read media, and WordPress read_post permission is checked for every attachment.', 'wp-content-bridge' ); ?></p>

				<h2><?php echo esc_html__( 'Block-pattern reads', 'wp-content-bridge' ); ?></h2>
				<table class="widefat striped" aria-describedby="wpcb-pattern-reads-enabled-help">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Registered block patterns', 'wp-content-bridge' ); ?></th>
							<td>
								<input type="hidden" name="<?php echo esc_attr( Installer::PATTERN_READS_ENABLED_OPTION ); ?>" value="0">
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Installer::PATTERN_READS_ENABLED_OPTION ); ?>" value="1" <?php checked( (bool) get_option( Installer::PATTERN_READS_ENABLED_OPTION ) ); ?>>
									<?php echo esc_html__( 'Enable list-block-patterns (master switch, off by default).', 'wp-content-bridge' ); ?>
								</label>
							</td>
						</tr>
					</tbody>
				</table>
				<p id="wpcb-pattern-reads-enabled-help" class="description"><?php echo esc_html__( 'The integration principal also needs Read block patterns and native editor-level permission for at least one REST-visible content type.', 'wp-content-bridge' ); ?></p>

				<h2><?php echo esc_html__( 'Content writes', 'wp-content-bridge' ); ?></h2>
				<table class="widefat striped" aria-describedby="wpcb-writes-enabled-help">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Content writes', 'wp-content-bridge' ); ?></th>
							<td>
								<input type="hidden" name="<?php echo esc_attr( Installer::WRITES_ENABLED_OPTION ); ?>" value="0">
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Installer::WRITES_ENABLED_OPTION ); ?>" value="1" <?php checked( (bool) get_option( Installer::WRITES_ENABLED_OPTION ) ); ?>>
									<?php echo esc_html__( 'Enable create-draft, update-content, and update-seo abilities (master switch, off by default).', 'wp-content-bridge' ); ?>
								</label>
							</td>
						</tr>
					</tbody>
				</table>
				<p id="wpcb-writes-enabled-help" class="description"><?php echo esc_html__( 'This master switch must be enabled in addition to the matching per-type policy. Trash-content also requires its separate destructive switch below.', 'wp-content-bridge' ); ?></p>

				<h2><?php echo esc_html__( 'Content trash', 'wp-content-bridge' ); ?></h2>
				<table class="widefat striped" aria-describedby="wpcb-trash-enabled-help">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Move content to trash', 'wp-content-bridge' ); ?></th>
							<td>
								<input type="hidden" name="<?php echo esc_attr( Installer::TRASH_ENABLED_OPTION ); ?>" value="0">
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Installer::TRASH_ENABLED_OPTION ); ?>" value="1" <?php checked( (bool) get_option( Installer::TRASH_ENABLED_OPTION ) ); ?>>
									<?php echo esc_html__( 'Enable trash-content (additional destructive switch, off by default).', 'wp-content-bridge' ); ?>
								</label>
							</td>
						</tr>
					</tbody>
				</table>
				<p id="wpcb-trash-enabled-help" class="description"><?php echo esc_html__( 'Content writes, the per-type Trash policy, Delete content capability, native delete_post permission, and reversible WordPress trash must also be available.', 'wp-content-bridge' ); ?></p>

				<h2><?php echo esc_html__( 'llms.txt publication', 'wp-content-bridge' ); ?></h2>
				<table class="widefat striped" aria-describedby="wpcb-llms-enabled-help">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Publish llms.txt', 'wp-content-bridge' ); ?></th>
							<td>
								<input type="hidden" name="<?php echo esc_attr( Installer::LLMS_ENABLED_OPTION ); ?>" value="0">
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Installer::LLMS_ENABLED_OPTION ); ?>" value="1" <?php checked( (bool) get_option( Installer::LLMS_ENABLED_OPTION ) ); ?>>
									<?php echo esc_html__( 'Enable preview-update-llms-txt, update-llms-txt, and regenerate-llms-txt, and the public /llms.txt endpoint once configured (master switch, off by default).', 'wp-content-bridge' ); ?>
								</label>
							</td>
						</tr>
					</tbody>
				</table>
				<p id="wpcb-llms-enabled-help" class="description"><?php echo esc_html__( 'get-llms-txt remains available without this switch, so review its reported configuration and ownership-conflict state before enabling publication.', 'wp-content-bridge' ); ?></p>

				<?php submit_button(); ?>
			</form>

			<?php $this->render_status_transition_preset(); ?>
			<?php $this->render_integration_access(); ?>
		</div>
		<?php
	}

	/**
	 * Applies an exact WPCB capability set to one existing integration user.
	 *
	 * @return void
	 */
	public function update_integration_access(): void {
		check_admin_referer( self::ACCESS_NONCE );

		if ( ! current_user_can( 'wpcb_manage_settings' ) || ! current_user_can( 'promote_users' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage integration users.', 'wp-content-bridge' ) );
		}

		if ( is_multisite() ) {
			$this->redirect_access_status( 'multisite_unsupported' );
		}

		$identifier = isset( $_POST['wpcb_integration_user'] ) && is_string( $_POST['wpcb_integration_user'] )
			? sanitize_text_field( wp_unslash( $_POST['wpcb_integration_user'] ) )
			: '';
		$requested  = isset( $_POST['wpcb_integration_capabilities'] ) && is_array( $_POST['wpcb_integration_capabilities'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every token is validated against IntegrationCapability before persistence.
			? wp_unslash( $_POST['wpcb_integration_capabilities'] )
			: array();

		$target = $this->integration_access->find( $identifier );
		if ( null === $target ) {
			$this->redirect_access_status( 'user_not_found' );
		}

		$current = $this->integration_access->managed();
		if ( ! current_user_can( 'edit_user', $target->user_id )
			|| ( null !== $current && $current->user_id !== $target->user_id && ! current_user_can( 'edit_user', $current->user_id ) )
		) {
			wp_die( esc_html__( 'You are not allowed to edit the selected integration user.', 'wp-content-bridge' ) );
		}

		try {
			$this->integration_access->update( $target->user_id, $requested );
			$this->redirect_access_status( 'updated' );
		} catch ( IntegrationAccessProblem $error ) {
			$this->redirect_access_status( $error->error_code );
		} catch ( Throwable ) {
			$this->redirect_access_status( 'update_failed' );
		}
	}

	/**
	 * Applies the ADR 0024 editorial preset to every currently eligible
	 * content type.
	 *
	 * This is a deliberate administrator action, never a default: nothing
	 * in activation or upgrade calls the equivalent application-service
	 * method, and pressing this button always overwrites the matrix rows
	 * for eligible types rather than merging into whatever an administrator
	 * already configured for them.
	 *
	 * @return void
	 */
	public function apply_status_transition_preset(): void {
		check_admin_referer( self::PRESET_NONCE );

		if ( ! current_user_can( 'wpcb_manage_settings' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage WP Content Bridge settings.', 'wp-content-bridge' ) );
		}

		$this->status_transitions->apply_editorial_preset( $this->eligible_post_types() );
		$this->redirect_status_preset_status( 'applied' );
	}

	/**
	 * Renders the editorial-preset action as its own discrete form, matching
	 * the integration-access section's separate admin-post.php submission
	 * rather than folding it into the options.php matrix save.
	 *
	 * @return void
	 */
	private function render_status_transition_preset(): void {
		?>
		<hr>
		<h2><?php echo esc_html__( 'Status transition preset', 'wp-content-bridge' ); ?></h2>
		<p><?php echo esc_html__( 'Applies the documented editorial preset (draft and pending may move to each other, and either may move to private) to every content type listed above. It never adds a publish or future pair. Existing rows for those content types are replaced.', 'wp-content-bridge' ); ?></p>
		<?php $this->render_status_preset_notice(); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::PRESET_ACTION ); ?>">
			<?php wp_nonce_field( self::PRESET_NONCE ); ?>
			<?php
			/*
			 * The preset replaces every rendered content type's row outright.
			 * One click therefore discards a hand-built matrix, which the
			 * paragraph above states but the button did not. The confirmation
			 * is attached by the enqueued script; without JavaScript the
			 * button behaves as it did in 0.7.0.
			 */
			submit_button(
				esc_html__( 'Apply editorial preset', 'wp-content-bridge' ),
				'secondary',
				'submit',
				true,
				array(
					'data-wpcb-confirm' => esc_attr__( 'Applying the preset replaces the configured status pairs for every content type shown above. Continue?', 'wp-content-bridge' ),
				)
			);
			?>
		</form>
		<?php
	}

	/**
	 * Renders a bounded status message after a preset action.
	 *
	 * @return void
	 */
	private function render_status_preset_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only, bounded status message after a redirect.
		$status = isset( $_GET[ self::PRESET_STATUS_QUERY_ARG ] ) && is_string( $_GET[ self::PRESET_STATUS_QUERY_ARG ] )
			? sanitize_key( wp_unslash( $_GET[ self::PRESET_STATUS_QUERY_ARG ] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'applied' => array( 'success', __( 'The editorial preset was applied to every eligible content type.', 'wp-content-bridge' ) ),
		);

		if ( ! isset( $messages[ $status ] ) ) {
			return;
		}

		$message = $messages[ $status ];
		?>
		<div class="notice notice-<?php echo esc_attr( $message[0] ); ?> inline"><p><?php echo esc_html( $message[1] ); ?></p></div>
		<?php
	}

	/**
	 * Redirects to a bounded status message on the settings page.
	 *
	 * @param string $status Stable status code.
	 * @return never
	 */
	private function redirect_status_preset_status( string $status ): never {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                        => 'wp-content-bridge',
					self::PRESET_STATUS_QUERY_ARG => sanitize_key( $status ),
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Lists the post types the status transition matrix currently renders
	 * rows for.
	 *
	 * @return array
	 * @phpstan-return list<string>
	 */
	private function eligible_post_types(): array {
		return array_map(
			static fn ( ContentTypeDefinition $definition ): string => $definition->name,
			$this->manager->content_types()
		);
	}

	/**
	 * Renders the principal-bound integration capability editor.
	 *
	 * @return void
	 */
	private function render_integration_access(): void {
		?>
		<hr>
		<h2><?php echo esc_html__( 'Integration user access', 'wp-content-bridge' ); ?></h2>
		<p><?php echo esc_html__( 'Assign WP Content Bridge capabilities to one existing WordPress user used by MCP or another integration. Native WordPress roles and object permissions remain separate.', 'wp-content-bridge' ); ?></p>
		<?php $this->render_access_notice(); ?>
		<?php if ( is_multisite() ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html__( 'Integration-user management is unavailable on multisite until network authorization behavior is specified.', 'wp-content-bridge' ); ?></p></div>
			<?php return; ?>
		<?php endif; ?>
		<?php if ( ! current_user_can( 'promote_users' ) ) : ?>
			<div class="notice notice-warning inline"><p><?php echo esc_html__( 'Your account can configure content policy but cannot assign user capabilities.', 'wp-content-bridge' ); ?></p></div>
			<?php return; ?>
		<?php endif; ?>

		<?php $principal = $this->integration_access->managed(); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCESS_ACTION ); ?>">
			<?php wp_nonce_field( self::ACCESS_NONCE ); ?>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="wpcb-integration-user"><?php echo esc_html__( 'WordPress user', 'wp-content-bridge' ); ?></label></th>
						<td>
							<input id="wpcb-integration-user" name="wpcb_integration_user" type="text" class="regular-text" maxlength="100" required value="<?php echo esc_attr( null !== $principal ? $principal->login : '' ); ?>" autocomplete="off">
							<p class="description"><?php echo esc_html__( 'Enter an existing user login or email. Use a dedicated least-privilege account with a WordPress role that grants native Read access.', 'wp-content-bridge' ); ?></p>
							<?php if ( null !== $principal ) : ?>
								<p>
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: display name, 2: user ID, 3: native read status. */
											__( 'Managed user: %1$s (ID %2$d). Native Read: %3$s.', 'wp-content-bridge' ),
											$principal->display_name,
											$principal->user_id,
											$principal->has_native_read ? __( 'yes', 'wp-content-bridge' ) : __( 'no', 'wp-content-bridge' )
										)
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Bridge capabilities', 'wp-content-bridge' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $this->integration_capability_labels() as $capability_value => $label ) : ?>
									<?php $capability = IntegrationCapability::from( $capability_value ); ?>
									<label>
										<input type="checkbox" name="wpcb_integration_capabilities[]" value="<?php echo esc_attr( $capability->value ); ?>" <?php checked( null !== $principal && $principal->has( $capability ) ); ?>>
										<?php echo esc_html( $label ); ?>
									</label><br>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php echo esc_html__( 'Saving replaces the exact WPCB capability set for this managed user. Selecting a different user revokes these capabilities from the previously managed user. Native WordPress capabilities, content-type policy, feature flags, and connector grants are still enforced independently.', 'wp-content-bridge' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>
			<?php submit_button( esc_html__( 'Save integration access', 'wp-content-bridge' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Renders a bounded status message after an integration-access update.
	 *
	 * @return void
	 */
	private function render_access_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only, bounded status message after a redirect.
		$status = isset( $_GET['wpcb_access_status'] ) && is_string( $_GET['wpcb_access_status'] )
			? sanitize_key( wp_unslash( $_GET['wpcb_access_status'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$messages = array(
			'updated'                   => array( 'success', __( 'Integration-user access was updated.', 'wp-content-bridge' ) ),
			'user_not_found'            => array( 'error', __( 'The selected WordPress user was not found.', 'wp-content-bridge' ) ),
			'administrator_not_allowed' => array( 'error', __( 'Use a dedicated least-privilege user instead of an administrator account.', 'wp-content-bridge' ) ),
			'native_read_required'      => array( 'error', __( 'The selected user needs native WordPress Read access before Bridge capabilities can be assigned.', 'wp-content-bridge' ) ),
			'invalid_capability'        => array( 'error', __( 'The submitted capability set was invalid.', 'wp-content-bridge' ) ),
			'multisite_unsupported'     => array( 'error', __( 'Integration-user management is not supported on multisite.', 'wp-content-bridge' ) ),
			'update_failed'             => array( 'error', __( 'Integration-user access could not be updated.', 'wp-content-bridge' ) ),
		);

		if ( ! isset( $messages[ $status ] ) ) {
			return;
		}

		$message = $messages[ $status ];
		?>
		<div class="notice notice-<?php echo esc_attr( $message[0] ); ?> inline"><p><?php echo esc_html( $message[1] ); ?></p></div>
		<?php
	}

	/**
	 * Redirects to a bounded status message on the settings page.
	 *
	 * @param string $status Stable status code.
	 * @return never
	 */
	private function redirect_access_status( string $status ): never {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'               => 'wp-content-bridge',
					'wpcb_access_status' => sanitize_key( $status ),
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Returns labels for the closed operational capability vocabulary.
	 *
	 * `wpcb_manage_settings` is intentionally excluded from integration grants.
	 *
	 * @return array<string, string>
	 */
	private function integration_capability_labels(): array {
		return array(
			IntegrationCapability::READ_CONTENT->value    => esc_html__( 'Read content, SEO, editorial context, and diagnostics', 'wp-content-bridge' ),
			IntegrationCapability::READ_MEDIA->value      => esc_html__( 'Read the authorized media library', 'wp-content-bridge' ),
			IntegrationCapability::READ_PATTERNS->value   => esc_html__( 'Read registered block patterns (also requires native editor access)', 'wp-content-bridge' ),
			IntegrationCapability::EDIT_CONTENT->value    => esc_html__( 'Create drafts and update content', 'wp-content-bridge' ),
			IntegrationCapability::MANAGE_SEO->value      => esc_html__( 'Update supported SEO fields', 'wp-content-bridge' ),
			IntegrationCapability::PUBLISH_CONTENT->value => esc_html__( 'Publish or schedule through status transition (reserved; not implemented)', 'wp-content-bridge' ),
			IntegrationCapability::DELETE_CONTENT->value  => esc_html__( 'Move authorized content to trash', 'wp-content-bridge' ),
			IntegrationCapability::MANAGE_LLMS->value     => esc_html__( 'Read, preview, update, and regenerate llms.txt configuration and publication', 'wp-content-bridge' ),
		);
	}

	/**
	 * Returns localized operation labels keyed by stable operation value.
	 *
	 * @return array<string, string>
	 */
	private function operation_labels(): array {
		return array(
			ContentOperation::READ->value              => esc_html__( 'Read', 'wp-content-bridge' ),
			ContentOperation::SEARCH->value            => esc_html__( 'Search', 'wp-content-bridge' ),
			ContentOperation::CREATE->value            => esc_html__( 'Create draft', 'wp-content-bridge' ),
			ContentOperation::UPDATE->value            => esc_html__( 'Update content', 'wp-content-bridge' ),
			ContentOperation::UPDATE_SEO->value        => esc_html__( 'Update SEO', 'wp-content-bridge' ),
			ContentOperation::TRANSITION_STATUS->value => esc_html__( 'Change status (reserved)', 'wp-content-bridge' ),
			ContentOperation::TRASH->value             => esc_html__( 'Trash', 'wp-content-bridge' ),
		);
	}
}
