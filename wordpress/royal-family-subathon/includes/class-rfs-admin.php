<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RFS_Admin {
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'save' ) );
	}

	public static function menu(): void {
		add_options_page(
			'Subathon Timer',
			'Subathon Timer',
			'manage_options',
			'royal-family-subathon',
			array( __CLASS__, 'page' )
		);
	}

	public static function save(): void {
		if ( ! isset( $_POST['rfs_action'] ) || 'save_settings' !== $_POST['rfs_action'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'royal-family-subathon' ) );
		}
		check_admin_referer( 'rfs_save_settings' );

		$client_id = isset( $_POST['rfs_client_id'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_POST['rfs_client_id'] ) ) : '';
		$secret    = isset( $_POST['rfs_client_secret'] ) ? trim( (string) wp_unslash( $_POST['rfs_client_secret'] ) ) : '';
		if ( '' !== $client_id && ( strlen( $client_id ) < 10 || strlen( $client_id ) > 80 ) ) {
			add_settings_error( 'rfs_messages', 'rfs_invalid_id', 'Die Twitch Client-ID sieht ungültig aus.', 'error' );
			return;
		}
		if ( '' !== $secret ) {
			if ( strlen( $secret ) < 10 || strlen( $secret ) > 255 ) {
				add_settings_error( 'rfs_messages', 'rfs_invalid_secret', 'Das Twitch Client-Secret sieht ungültig aus.', 'error' );
				return;
			}
		}
		update_option( 'rfs_twitch_client_id', $client_id, false );
		if ( '' !== $secret ) {
			update_option( 'rfs_twitch_client_secret', RFS_Crypto::encrypt( $secret ), false );
		}
		delete_transient( 'rfs_twitch_app_token' );
		add_settings_error( 'rfs_messages', 'rfs_saved', 'Einstellungen gespeichert.', 'success' );
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1>Royal Family Subathon Timer</h1>
			<?php settings_errors( 'rfs_messages' ); ?>
			<p>Das Plugin verbindet die versteckte Subathon-Seite sicher mit Twitch. Es verändert weder das Theme noch die öffentliche Navigation.</p>
			<table class="widefat striped" style="max-width:1000px;margin:18px 0">
				<tbody>
					<tr><th style="width:220px">Status</th><td><strong><?php echo RFS_Twitch::configured() ? '<span style="color:#167d32">Twitch konfiguriert</span>' : '<span style="color:#b32d2e">Twitch noch nicht konfiguriert</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></strong></td></tr>
					<tr><th>Versteckte Seite</th><td><code><?php echo esc_html( home_url( '/subathon/' ) ); ?></code></td></tr>
					<tr><th>OAuth-Weiterleitungs-URL</th><td><code><?php echo esc_html( RFS_Twitch::callback_url() ); ?></code></td></tr>
					<tr><th>EventSub-Webhook</th><td><code><?php echo esc_html( RFS_Twitch::webhook_url() ); ?></code></td></tr>
				</tbody>
			</table>
			<form method="post">
				<?php wp_nonce_field( 'rfs_save_settings' ); ?>
				<input type="hidden" name="rfs_action" value="save_settings">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="rfs_client_id">Twitch Client-ID</label></th>
						<td><input class="regular-text" id="rfs_client_id" name="rfs_client_id" type="text" autocomplete="off" value="<?php echo esc_attr( RFS_Twitch::client_id() ); ?>" required></td>
					</tr>
					<tr>
						<th scope="row"><label for="rfs_client_secret">Twitch Client-Secret</label></th>
						<td><input class="regular-text" id="rfs_client_secret" name="rfs_client_secret" type="password" autocomplete="new-password" value=""><p class="description">Leer lassen, um das bereits verschlüsselt gespeicherte Secret beizubehalten.</p></td>
					</tr>
				</table>
				<?php submit_button( 'Twitch-Einstellungen speichern' ); ?>
			</form>
			<h2>Sicherheit</h2>
			<p>Zugangstokens, Client-Secret und OBS-Schlüssel werden verschlüsselt gespeichert. Dashboard-Sitzungen laufen nach 30 Tagen ohne Benutzung ab. Twitch-Nachrichten werden anhand ihrer HMAC-Signatur und ihres Zeitstempels geprüft.</p>
		</div>
		<?php
	}
}
