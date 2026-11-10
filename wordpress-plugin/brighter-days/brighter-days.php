<?php
/**
 * Plugin Name:       Brighter Days
 * Plugin URI:        https://github.com/theanarchyram/BrighterDays
 * Description:       Adds the Brighter Days shopkeeper game to your site. Use [brighter_days] to embed the game and [brighter_days_banner] for the animated ad banner. The game ships inside the plugin and updates itself from GitHub releases.
 * Update URI:        https://github.com/theanarchyram/BrighterDays
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            LeverOne Studios
 * Author URI:        https://alfredwill.com
 * License:           MIT
 * Text Domain:       brighter-days
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BRIGHTER_DAYS_VERSION', '1.1.0' );
define( 'BRIGHTER_DAYS_REPO', 'theanarchyram/BrighterDays' );

function brighter_days_is_bundled() {
	return file_exists( plugin_dir_path( __FILE__ ) . 'game/index.html' );
}

/** Address of the game, which ships inside this plugin. */
function brighter_days_game_url() {
	return plugins_url( 'game/', __FILE__ );
}

function brighter_days_image_url( $file ) {
	return brighter_days_game_url() . 'images/' . rawurlencode( $file );
}

/** [brighter_days] embeds the playable game. */
function brighter_days_shortcode( $atts ) {
	$a = shortcode_atts(
		array(
			'ratio'           => '16/9',
			'height'          => '',
			'max_width'       => '1200px',
			'fullscreen_link' => 'yes',
		),
		$atts,
		'brighter_days'
	);

	$ratio     = preg_match( '#^\d+(\.\d+)?/\d+(\.\d+)?$#', $a['ratio'] ) ? $a['ratio'] : '16/9';
	$height    = preg_match( '#^\d+(\.\d+)?(px|vh|em|rem|%)$#', $a['height'] ) ? $a['height'] : '';
	$max_width = preg_match( '#^\d+(\.\d+)?(px|vw|em|rem|%)$#', $a['max_width'] ) ? $a['max_width'] : '1200px';

	$style  = 'width:100%;border:0;display:block;margin:0 auto;max-width:' . $max_width . ';';
	$style .= $height ? 'height:' . $height . ';' : 'aspect-ratio:' . $ratio . ';';

	$url  = brighter_days_game_url();
	$html = '<div class="brighter-days-embed">';
	$html .= '<iframe src="' . esc_url( $url ) . '" title="' . esc_attr__( 'Brighter Days', 'brighter-days' ) . '" style="' . esc_attr( $style ) . '" allow="fullscreen; autoplay" allowfullscreen loading="lazy"></iframe>';
	if ( 'no' !== strtolower( $a['fullscreen_link'] ) ) {
		$html .= '<p class="brighter-days-fullscreen" style="text-align:center;margin:.6em 0 0"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Play full screen', 'brighter-days' ) . '</a></p>';
	}
	return $html . '</div>';
}
add_shortcode( 'brighter_days', 'brighter_days_shortcode' );

/** [brighter_days_banner] shows the ad banner, linking to the game or to a page you choose. */
function brighter_days_banner_shortcode( $atts ) {
	$a = shortcode_atts(
		array(
			'link'     => '',
			'animated' => 'yes',
		),
		$atts,
		'brighter_days_banner'
	);
	$link = $a['link'] ? $a['link'] : brighter_days_game_url();
	$file = ( 'no' === strtolower( $a['animated'] ) ) ? 'brighter-days-ad-1200x630.png' : 'brighter-days-ad-animated-1200x630.gif';
	$alt  = __( "Brighter Days: a shopkeeper's tale, from candlelight to neon. Play now.", 'brighter-days' );
	return '<a class="brighter-days-banner" href="' . esc_url( $link ) . '" aria-label="' . esc_attr__( 'Play Brighter Days', 'brighter-days' ) . '"><img src="' . esc_url( brighter_days_image_url( $file ) ) . '" alt="' . esc_attr( $alt ) . '" width="1200" height="630" style="max-width:100%;height:auto;display:block" loading="lazy"></a>';
}
add_shortcode( 'brighter_days_banner', 'brighter_days_banner_shortcode' );


/* ---------- Updates from GitHub releases ---------- */

/** Latest published release on GitHub, cached so the site rarely calls GitHub. */
function brighter_days_latest_release( $force = false ) {
	if ( ! $force ) {
		$cached = get_transient( 'brighter_days_release' );
		if ( false !== $cached ) {
			return $cached;
		}
	}
	$response = wp_remote_get(
		'https://api.github.com/repos/' . BRIGHTER_DAYS_REPO . '/releases/latest',
		array(
			'timeout' => 10,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'BrighterDays-WordPress/' . BRIGHTER_DAYS_VERSION,
			),
		)
	);
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		set_transient( 'brighter_days_release', array(), HOUR_IN_SECONDS );
		return array();
	}
	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
		set_transient( 'brighter_days_release', array(), HOUR_IN_SECONDS );
		return array();
	}
	$package = '';
	foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
		$name = $asset['name'] ?? '';
		$link = $asset['browser_download_url'] ?? '';
		if ( 0 !== strpos( $link, 'https://github.com/' . BRIGHTER_DAYS_REPO . '/releases/download/' ) ) {
			continue;
		}
		if ( 'brighter-days-wordpress-plugin.zip' === $name ) {
			$package = $link;
		}
	}
	$release = array(
		'version'   => ltrim( (string) $data['tag_name'], 'vV' ),
		'package'   => $package,
		'url'       => (string) ( $data['html_url'] ?? '' ),
		'notes'     => (string) ( $data['body'] ?? '' ),
		'published' => (string) ( $data['published_at'] ?? '' ),
	);
	set_transient( 'brighter_days_release', $release, $release['package'] ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	return $release;
}

/** Tell WordPress when a newer version is available. */
function brighter_days_check_update( $transient ) {
	if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
		return $transient;
	}
	$file    = plugin_basename( __FILE__ );
	$release = brighter_days_latest_release();
	$item    = (object) array(
		'id'          => 'github.com/' . BRIGHTER_DAYS_REPO,
		'slug'        => 'brighter-days',
		'plugin'      => $file,
		'new_version' => BRIGHTER_DAYS_VERSION,
		'url'         => 'https://github.com/' . BRIGHTER_DAYS_REPO,
		'package'     => '',
		'icons'       => array(),
		'banners'     => array(),
		'tested'      => get_bloginfo( 'version' ),
		'requires_php'=> '7.4',
	);
	if ( ! empty( $release['package'] ) && version_compare( $release['version'], BRIGHTER_DAYS_VERSION, '>' ) ) {
		$item->new_version = $release['version'];
		$item->package     = $release['package'];
		$item->url         = $release['url'];
		$transient->response[ $file ] = $item;
		unset( $transient->no_update[ $file ] );
	} else {
		$transient->no_update[ $file ] = $item;
		unset( $transient->response[ $file ] );
	}
	return $transient;
}
add_filter( 'pre_set_site_transient_update_plugins', 'brighter_days_check_update' );

/** "View details" popup on the Plugins screen. */
function brighter_days_plugin_info( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || empty( $args->slug ) || 'brighter-days' !== $args->slug ) {
		return $result;
	}
	$release = brighter_days_latest_release();
	return (object) array(
		'name'          => 'Brighter Days',
		'slug'          => 'brighter-days',
		'version'       => $release['version'] ?? BRIGHTER_DAYS_VERSION,
		'author'        => '<a href="https://alfredwill.com">LeverOne Studios</a>',
		'homepage'      => 'https://github.com/' . BRIGHTER_DAYS_REPO,
		'download_link' => $release['package'] ?? '',
		'requires'      => '5.8',
		'requires_php'  => '7.4',
		'last_updated'  => $release['published'] ?? '',
		'sections'      => array(
			'description' => '<p>A pixel-art shopkeeper game that runs in the browser. Use [brighter_days] to embed the game and [brighter_days_banner] for the animated ad banner.</p>',
			'changelog'   => ! empty( $release['notes'] ) ? wpautop( esc_html( $release['notes'] ) ) : '<p>See the releases on GitHub.</p>',
		),
	);
}
add_filter( 'plugins_api', 'brighter_days_plugin_info', 10, 3 );

/** Install updates automatically unless switched off in Settings > Brighter Days. */
function brighter_days_auto_update( $update, $item ) {
	if ( isset( $item->plugin ) && plugin_basename( __FILE__ ) === $item->plugin ) {
		return '1' === get_option( 'brighter_days_auto_update', '1' );
	}
	return $update;
}
add_filter( 'auto_update_plugin', 'brighter_days_auto_update', 10, 2 );

/** Forget the cached release once an update has been installed. */
function brighter_days_after_upgrade( $upgrader, $options ) {
	if ( isset( $options['type'], $options['plugins'] ) && 'plugin' === $options['type'] && in_array( plugin_basename( __FILE__ ), (array) $options['plugins'], true ) ) {
		delete_transient( 'brighter_days_release' );
	}
}
add_action( 'upgrader_process_complete', 'brighter_days_after_upgrade', 10, 2 );

/** "Check for updates now" button on the settings page. */
function brighter_days_check_now() {
	if ( ! current_user_can( 'update_plugins' ) ) {
		wp_die( esc_html__( 'You are not allowed to update plugins.', 'brighter-days' ) );
	}
	check_admin_referer( 'brighter_days_check_now' );
	delete_transient( 'brighter_days_release' );
	brighter_days_latest_release( true );
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	wp_safe_redirect( admin_url( 'options-general.php?page=brighter-days&checked=1' ) );
	exit;
}
add_action( 'admin_post_brighter_days_check_now', 'brighter_days_check_now' );

/* Settings page: Settings > Brighter Days */
function brighter_days_register_settings() {
	register_setting(
		'brighter_days',
		'brighter_days_auto_update',
		array(
			'type'              => 'string',
			'default'           => '1',
			'sanitize_callback' => function ( $v ) {
				return '1' === (string) $v ? '1' : '0';
			},
		)
	);
}
add_action( 'admin_init', 'brighter_days_register_settings' );

function brighter_days_admin_menu() {
	add_options_page( 'Brighter Days', 'Brighter Days', 'manage_options', 'brighter-days', 'brighter_days_settings_page' );
}
add_action( 'admin_menu', 'brighter_days_admin_menu' );

function brighter_days_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1>Brighter Days</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'brighter_days' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Updates</th>
					<td>
						<input type="hidden" name="brighter_days_auto_update" value="0">
						<label><input type="checkbox" name="brighter_days_auto_update" value="1" <?php checked( get_option( 'brighter_days_auto_update', '1' ), '1' ); ?>> Install new versions automatically</label>
						<p class="description">New versions come from the releases on <a href="https://github.com/<?php echo esc_attr( BRIGHTER_DAYS_REPO ); ?>/releases" target="_blank" rel="noopener">GitHub</a>.</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<h2>Version</h2>
		<?php $rel = brighter_days_latest_release(); ?>
		<p>Installed: <strong><?php echo esc_html( BRIGHTER_DAYS_VERSION ); ?></strong>. Latest on GitHub: <strong><?php echo esc_html( ! empty( $rel['version'] ) ? $rel['version'] : 'not found yet' ); ?></strong>.</p>
		<?php if ( isset( $_GET['checked'] ) ) : ?><p><em>Checked for updates just now.</em></p><?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="brighter_days_check_now">
			<?php wp_nonce_field( 'brighter_days_check_now' ); ?>
			<?php submit_button( 'Check for updates now', 'secondary', 'submit', false ); ?>
		</form>
		<h2>How to use</h2>
		<p><code>[brighter_days]</code> embeds the game on any page or post. Options: <code>ratio="16/9"</code>, <code>height="720px"</code>, <code>max_width="1200px"</code>, <code>fullscreen_link="no"</code>.</p>
		<p><code>[brighter_days_banner]</code> shows the animated ad banner. Options: <code>link="/play/"</code> to send visitors to your game page, <code>animated="no"</code> for a still image.</p>
		<?php if ( ! brighter_days_is_bundled() ) : ?><p><strong>The game files are missing from this plugin.</strong> Reinstall it from the latest release on GitHub.</p><?php endif; ?>
	</div>
	<?php
}

function brighter_days_action_links( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=brighter-days' ) ) . '">' . esc_html__( 'Settings', 'brighter-days' ) . '</a>' );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'brighter_days_action_links' );
