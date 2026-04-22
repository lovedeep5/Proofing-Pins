<?php
/**
 * Admin settings template. Variables are method-scoped at include time.
 *
 * @package ProofingPins
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

if ( ! defined( 'ABSPATH' ) ) { exit; }
$settings = wp_parse_args( get_option( 'pp_settings', [] ), [
	'position'           => 'right',
	'brand_color'        => '#2271b1',
	'allowed_roles'      => [ 'subscriber', 'contributor', 'author', 'editor', 'administrator' ],
	'auto_resolve_days'  => 0,
	'guest_pins_enabled' => false,
	'guest_rate_limit'   => 5,
] );
$roles = [
	'administrator' => __( 'Administrator', 'proofing-pins' ),
	'editor'        => __( 'Editor', 'proofing-pins' ),
	'author'        => __( 'Author', 'proofing-pins' ),
	'contributor'   => __( 'Contributor', 'proofing-pins' ),
	'subscriber'    => __( 'Subscriber', 'proofing-pins' ),
];
?>
<div class="wrap pp-admin">
	<h1><?php esc_html_e( 'Proofing Pins Settings', 'proofing-pins' ); ?></h1>
	<form method="post" action="options.php" class="pp-settings-form">
		<?php settings_fields( 'pp_settings_group' ); ?>
		<table class="form-table">
			<tr>
				<th><label><?php esc_html_e( 'Floating button position', 'proofing-pins' ); ?></label></th>
				<td>
					<label><input type="radio" name="pp_settings[position]" value="right" <?php checked( $settings['position'], 'right' ); ?>> <?php esc_html_e( 'Bottom right', 'proofing-pins' ); ?></label>
					&nbsp;&nbsp;
					<label><input type="radio" name="pp_settings[position]" value="left" <?php checked( $settings['position'], 'left' ); ?>> <?php esc_html_e( 'Bottom left', 'proofing-pins' ); ?></label>
				</td>
			</tr>
			<tr>
				<th><label for="pp-brand-color"><?php esc_html_e( 'Brand color', 'proofing-pins' ); ?></label></th>
				<td><input type="color" id="pp-brand-color" name="pp_settings[brand_color]" value="<?php echo esc_attr( $settings['brand_color'] ); ?>"></td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'Roles allowed to leave pins', 'proofing-pins' ); ?></label></th>
				<td>
					<?php foreach ( $roles as $key => $label ) : ?>
						<label style="display:block;margin-bottom:4px;">
							<input type="checkbox" name="pp_settings[allowed_roles][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $settings['allowed_roles'], true ) ); ?>>
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th><label for="pp-auto-resolve"><?php esc_html_e( 'Auto-resolve pins older than', 'proofing-pins' ); ?></label></th>
				<td>
					<input type="number" id="pp-auto-resolve" name="pp_settings[auto_resolve_days]" min="0" value="<?php echo esc_attr( (int) $settings['auto_resolve_days'] ); ?>" style="width:80px">
					<?php esc_html_e( 'days (0 = disabled)', 'proofing-pins' ); ?>
				</td>
			</tr>
			<tr>
				<th colspan="2"><h2 style="margin:20px 0 4px;font-size:16px;"><?php esc_html_e( 'Guest comments', 'proofing-pins' ); ?></h2></th>
			</tr>
			<tr>
				<th><label for="pp-guest-enabled"><?php esc_html_e( 'Allow logged-out visitors to leave pins', 'proofing-pins' ); ?></label></th>
				<td>
					<label><input type="checkbox" id="pp-guest-enabled" name="pp_settings[guest_pins_enabled]" value="1" <?php checked( ! empty( $settings['guest_pins_enabled'] ) ); ?>>
					<?php esc_html_e( 'Enabled', 'proofing-pins' ); ?></label>
					<p class="description"><?php esc_html_e( 'Guests will be asked once for name + email (stored in their browser cookie for 30 days) before they can leave pins. Abuse protection: honeypot + per-IP rate limit.', 'proofing-pins' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="pp-guest-rate"><?php esc_html_e( 'Rate limit per IP', 'proofing-pins' ); ?></label></th>
				<td>
					<input type="number" id="pp-guest-rate" name="pp_settings[guest_rate_limit]" min="1" max="50" value="<?php echo esc_attr( (int) $settings['guest_rate_limit'] ); ?>" style="width:80px">
					<?php esc_html_e( 'pins per hour (max 50)', 'proofing-pins' ); ?>
				</td>
			</tr>
		</table>
		<?php submit_button(); ?>
	</form>
</div>
