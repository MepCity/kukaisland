<?php
/**
 * Encrypted storage for the four carrier credentials.
 *
 * WHY THIS EXISTS AT ALL, GIVEN THE OLD RULE.
 *
 * The previous contract was absolute: secrets come from wp-config constants or
 * the process environment, never from an option. That rule is still the SAFEST
 * arrangement and it is still the one this module prefers -- a constant beats
 * everything here. But it assumes somebody can edit wp-config.php, and the shop
 * owner this module is being delivered to cannot. The honest choice was between
 * a panel that stores secrets carefully and no panel at all; "no panel" would
 * have meant the integration is never configured, and an unconfigured
 * integration is not safer, it is just unused.
 *
 * So the rule becomes a PRECEDENCE rather than a prohibition:
 *
 *     constant  >  environment  >  this vault
 *
 * and what the vault stores is ciphertext, never a readable value.
 *
 * THE KEY IS NOT STORED. It is derived, on every read, from the site's own
 * wp-config salts via HKDF. A database dump therefore contains ciphertext and
 * nothing that decrypts it: an attacker needs the filesystem as well. The
 * derivation is bound to a purpose string, so the same salts used elsewhere in
 * WordPress cannot produce this key by accident.
 *
 * SALT ROTATION IS VISIBLE, NOT SILENT. The key's fingerprint is stored beside
 * the ciphertext. Rotating the salts changes the fingerprint, and this class
 * then refuses to decrypt and says `credentials_unreadable` -- because the
 * alternative, returning an empty string, looks exactly like "never
 * configured" and would send an operator hunting for a setting they already
 * entered.
 *
 * AUTHENTICATED ENCRYPTION, OR NOTHING. sodium_crypto_secretbox is used because
 * it authenticates: an edited ciphertext fails rather than decrypting to
 * rubbish that some later code path treats as a password. If libsodium is
 * absent the vault reports itself unavailable and stores nothing at all; a
 * fallback to a homemade cipher would be worse than the missing feature.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_Secret_Vault {

	/** Ciphertext only, and never autoloaded. */
	public const OPTION = 'kuka_island_shipping_secrets';

	/** Storage format. Bumped only when the stored shape changes. */
	public const VERSION = 1;

	/** Safe code for "there is something stored and it cannot be read". */
	public const CODE_UNREADABLE = 'credentials_unreadable';

	/** Safe code for "this site cannot store secrets at all". */
	public const CODE_UNAVAILABLE = 'credentials_vault_unavailable';

	/** Domain separation for the derived key. */
	private const KEY_INFO = 'kuka-island-shipping/secret-vault/v1';

	/**
	 * The four field names this vault will hold, and nothing else.
	 *
	 * An allow-list rather than free-form keys: a caller that could invent a
	 * field name could also write an attacker-chosen key into the options row.
	 *
	 * @return array<int, string>
	 */
	public static function fields(): array {
		return array( 'client_id', 'client_secret', 'customer_number', 'password' );
	}

	/** Can this site encrypt at all? */
	public static function is_available(): bool {
		return function_exists( 'sodium_crypto_secretbox' )
			&& function_exists( 'sodium_crypto_secretbox_open' )
			&& function_exists( 'random_bytes' )
			&& function_exists( 'hash_hkdf' )
			&& defined( 'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' );
	}

	/**
	 * The site's own key material, concatenated.
	 *
	 * Every salt WordPress defines is used, so a site that rotated only some of
	 * them still changes the fingerprint. A site with no salts at all -- which
	 * is a broken install, not a supported one -- produces an empty string and
	 * the vault refuses to operate.
	 */
	private static function key_material(): string {
		$parts = array();

		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $name ) {
			if ( defined( $name ) ) {
				$parts[] = (string) constant( $name );
			}
		}

		return implode( "\x1f", $parts );
	}

	/** The 32-byte secretbox key, or an empty string when it cannot be derived. */
	private static function key(): string {
		$material = self::key_material();

		if ( '' === $material || ! self::is_available() ) {
			return '';
		}

		return (string) hash_hkdf( 'sha256', $material, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::KEY_INFO, '' );
	}

	/**
	 * A short, non-reversible name for the current key.
	 *
	 * Stored beside the ciphertext so that "the salts changed" is a thing this
	 * module can SAY rather than a silence an operator has to diagnose. It is an
	 * HMAC of a fixed string under the derived key, so it reveals nothing about
	 * the key itself.
	 */
	public static function key_fingerprint(): string {
		$key = self::key();

		if ( '' === $key ) {
			return '';
		}

		return substr( hash_hmac( 'sha256', 'fingerprint', $key ), 0, 32 );
	}

	/**
	 * The stored document, or an empty array.
	 *
	 * @return array<string, mixed>
	 */
	private static function document(): array {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Which fields have something stored. NAMES ONLY.
	 *
	 * @return array<int, string>
	 */
	public static function stored_fields(): array {
		$document = self::document();
		$fields   = is_array( $document['fields'] ?? null ) ? $document['fields'] : array();
		$names    = array();

		foreach ( self::fields() as $field ) {
			if ( is_array( $fields[ $field ] ?? null ) ) {
				$names[] = $field;
			}
		}

		return $names;
	}

	/**
	 * Is the stored document readable with the key this site has right now?
	 *
	 * @return array{ok: bool, code: string, stored: int}
	 */
	public static function state(): array {
		$document = self::document();
		$stored   = count( self::stored_fields() );

		if ( 0 === $stored ) {
			// Nothing stored is not a fault. It is a site that uses constants,
			// or one nobody has configured yet.
			return array(
				'ok'     => true,
				'code'   => '',
				'stored' => 0,
			);
		}

		if ( ! self::is_available() ) {
			return array(
				'ok'     => false,
				'code'   => self::CODE_UNAVAILABLE,
				'stored' => $stored,
			);
		}

		$fingerprint = self::key_fingerprint();

		if ( '' === $fingerprint || (string) ( $document['key_fingerprint'] ?? '' ) !== $fingerprint ) {
			return array(
				'ok'     => false,
				'code'   => self::CODE_UNREADABLE,
				'stored' => $stored,
			);
		}

		// The fingerprint matching is not proof that each field decrypts: the
		// row could have been edited. Every stored field is opened.
		foreach ( self::stored_fields() as $field ) {
			if ( null === self::decrypt( $field ) ) {
				return array(
					'ok'     => false,
					'code'   => self::CODE_UNREADABLE,
					'stored' => $stored,
				);
			}
		}

		return array(
			'ok'     => true,
			'code'   => '',
			'stored' => $stored,
		);
	}

	/**
	 * One stored value, or null when it is absent OR unreadable.
	 *
	 * The two are deliberately the same return value HERE, because a caller
	 * that wanted a credential has nothing to do with either. The difference is
	 * carried by state(), which is what the panel and the readiness check read.
	 */
	public static function get( string $field ): ?string {
		if ( ! in_array( $field, self::fields(), true ) ) {
			return null;
		}

		$document = self::document();

		if ( (string) ( $document['key_fingerprint'] ?? '' ) !== self::key_fingerprint() ) {
			return null;
		}

		return self::decrypt( $field );
	}

	/**
	 * Open one field. Null on anything at all going wrong.
	 */
	private static function decrypt( string $field ): ?string {
		if ( ! self::is_available() ) {
			return null;
		}

		$document = self::document();
		$entry    = $document['fields'][ $field ] ?? null;

		if ( ! is_array( $entry ) ) {
			return null;
		}

		$key    = self::key();
		$nonce  = base64_decode( (string) ( $entry['nonce'] ?? '' ), true );
		$cipher = base64_decode( (string) ( $entry['cipher'] ?? '' ), true );

		if ( '' === $key || false === $nonce || false === $cipher ) {
			return null;
		}

		if ( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES !== strlen( $nonce ) ) {
			return null;
		}

		try {
			$plain = sodium_crypto_secretbox_open( $cipher, $nonce, $key );
		} catch ( Throwable $e ) {
			unset( $e );

			return null;
		}

		// secretbox_open returns false on a failed authentication tag -- which
		// is exactly the edited-ciphertext case this vault exists to catch.
		return is_string( $plain ) ? $plain : null;
	}

	/**
	 * Store one value.
	 *
	 * @return bool False when this site cannot encrypt, or the field is unknown.
	 */
	public static function put( string $field, string $value ): bool {
		if ( ! in_array( $field, self::fields(), true ) || ! self::is_available() ) {
			return false;
		}

		$key = self::key();

		if ( '' === $key ) {
			return false;
		}

		try {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $value, $nonce, $key );
		} catch ( Throwable $e ) {
			unset( $e );

			return false;
		}

		$document = self::document();

		/*
		 * A document written under a DIFFERENT key cannot be added to: the
		 * other fields would stay unreadable while this one worked, and
		 * state() would keep saying credentials_unreadable for a site whose
		 * operator has just typed a fresh value. Writing under a new key starts
		 * a fresh document.
		 */
		if ( (string) ( $document['key_fingerprint'] ?? '' ) !== self::key_fingerprint() ) {
			$document = array( 'fields' => array() );
		}

		$document['version']         = self::VERSION;
		$document['key_fingerprint'] = self::key_fingerprint();
		$document['fields']          = is_array( $document['fields'] ?? null ) ? $document['fields'] : array();

		$document['fields'][ $field ] = array(
			'nonce'      => base64_encode( $nonce ),
			'cipher'     => base64_encode( $cipher ),
			'updated_at' => time(),
		);

		// autoload=no: a credential must never ride along in the option snapshot
		// every request loads.
		return (bool) update_option( self::OPTION, $document, false );
	}

	/** Remove one stored value. Deliberate, and never implicit. */
	public static function forget( string $field ): bool {
		if ( ! in_array( $field, self::fields(), true ) ) {
			return false;
		}

		$document = self::document();

		if ( ! is_array( $document['fields'] ?? null ) || ! isset( $document['fields'][ $field ] ) ) {
			return true;
		}

		unset( $document['fields'][ $field ] );

		if ( array() === $document['fields'] ) {
			return (bool) delete_option( self::OPTION );
		}

		return (bool) update_option( self::OPTION, $document, false );
	}

	/** Remove everything. Used by uninstall paths, never by a save. */
	public static function forget_all(): bool {
		return (bool) delete_option( self::OPTION );
	}
}
