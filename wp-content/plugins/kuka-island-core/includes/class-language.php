<?php
/** URL-led Turkish/English locale and SEO infrastructure. */

defined( 'ABSPATH' ) || exit;

/** Return the one active storefront language code. */
function kuka_island_locale(): string {
	return Kuka_Island_Core_Language::is_english_request() ? 'en' : 'tr';
}

function kuka_island_is_english(): bool {
	return 'en' === kuka_island_locale();
}

final class Kuka_Island_Core_Language {
	/** @return array<string, array<string, array{key:string,mode:string}>> */
	public static function translation_fields(): array {
		return array(
			'brand' => array( 'social_links' => array( 'key' => 'social_links_labels_en', 'mode' => 'labels' ) ),
			'announcement' => array(
				'items' => array( 'key' => 'items_en', 'mode' => 'copy' ),
				'link_labels' => array( 'key' => 'link_labels_en', 'mode' => 'copy' ),
			),
			'hero' => self::simple_fields( array( 'eyebrow', 'title', 'copy', 'button_label' ) ),
			'home' => self::simple_fields( array(
				'category_index_label', 'category_index_title', 'new_arrivals_title', 'new_arrivals_copy',
				'editorial_title', 'editorial_copy', 'editorial_link_label', 'manifesto_line_1', 'manifesto_line_2',
				'service_1_title', 'service_1_copy', 'service_2_title', 'service_2_copy', 'service_3_title', 'service_3_copy',
			) ),
			'navigation' => array(
				'main' => array( 'key' => 'main_labels_en', 'mode' => 'labels' ),
				'categories' => array( 'key' => 'categories_labels_en', 'mode' => 'labels' ),
				'help' => array( 'key' => 'help_labels_en', 'mode' => 'labels' ),
			),
			'footer' => array_merge(
				self::simple_fields( array( 'newsletter_eyebrow', 'newsletter_title', 'newsletter_copy', 'newsletter_consent' ) ),
				array(
					'help_links' => array( 'key' => 'help_links_labels_en', 'mode' => 'labels' ),
					'legal_links' => array( 'key' => 'legal_links_labels_en', 'mode' => 'labels' ),
				)
			),
			'commercial' => self::simple_fields( array(
				'delivery_time', 'return_shipping_responsibility', 'shipping_copy', 'free_shipping_remaining_copy',
				'free_shipping_ready_copy', 'flat_rate_copy', 'hygiene_copy', 'hygiene_defect_copy',
				'hygiene_try_on_copy', 'secure_payment_copy', 'support_hours',
			) ),
		);
	}

	/** @param array<int, string> $keys @return array<string, array{key:string,mode:string}> */
	private static function simple_fields( array $keys ): array {
		$fields = array();
		foreach ( $keys as $key ) { $fields[ $key ] = array( 'key' => $key . '_en', 'mode' => 'copy' ); }
		return $fields;
	}

	public static function translation_config( string $group, string $key ): ?array {
		return self::translation_fields()[ $group ][ $key ] ?? null;
	}

	/** Add empty English storage keys without inventing translated content. */
	public static function with_translation_defaults( array $content ): array {
		foreach ( self::translation_fields() as $group => $fields ) {
			foreach ( $fields as $config ) {
				if ( ! array_key_exists( $config['key'], $content[ $group ] ?? array() ) ) {
					$content[ $group ][ $config['key'] ] = '';
				}
			}
		}
		return $content;
	}

	/** Resolve English values field-by-field, falling back to Turkish. */
	public static function localized_content( array $content ): array {
		$content = self::with_translation_defaults( $content );
		if ( ! self::is_english_request() ) { return $content; }
		foreach ( self::translation_fields() as $group => $fields ) {
			foreach ( $fields as $source_key => $config ) {
				$translated = $content[ $group ][ $config['key'] ] ?? '';
				if ( 'labels' === $config['mode'] ) {
					$content[ $group ][ $source_key ] = self::translated_labels( (string) ( $content[ $group ][ $source_key ] ?? '' ), (string) $translated );
				} elseif ( is_array( $content[ $group ][ $source_key ] ?? null ) ) {
					if ( is_array( $translated ) && array_filter( $translated, 'strlen' ) ) { $content[ $group ][ $source_key ] = $translated; }
				} elseif ( '' !== trim( (string) $translated ) ) {
					$content[ $group ][ $source_key ] = $translated;
				}
			}
		}
		return $content;
	}

	private static function translated_labels( string $source, string $translations ): string {
		$labels = preg_split( '/\R/', $translations ) ?: array();
		$rows   = preg_split( '/\R/', $source ) ?: array();
		foreach ( $rows as $index => &$row ) {
			$label = trim( (string) ( $labels[ $index ] ?? '' ) );
			if ( '' === $label ) { continue; }
			$parts = explode( '|', $row );
			$parts[0] = $label;
			$row = implode( '|', $parts );
		}
		return implode( "\n", $rows );
	}

	public static function translation_field_count(): int {
		return array_sum( array_map( 'count', self::translation_fields() ) );
	}

	public function register(): void {
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_filter( 'rewrite_rules_array', array( $this, 'translated_rewrite_rules' ), 20 );
		add_filter( 'locale', array( $this, 'request_locale' ), 1 );
		add_filter( 'determine_locale', array( $this, 'request_locale' ), 1 );
		add_action( 'wp', array( $this, 'switch_runtime_locale' ), 1 );
		add_filter( 'home_url', array( $this, 'filter_home_url' ), 20, 4 );
		add_action( 'wp_head', array( $this, 'language_metadata' ), 0 );
		add_filter( 'wp_sitemaps_enabled', '__return_true' );
		add_action( 'init', array( $this, 'register_sitemap_provider' ), 20 );
	}

	public static function is_english_request(): bool {
		if ( 'en' === (string) get_query_var( 'kuka_lang', '' ) ) { return true; }
		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH );
		return (bool) preg_match( '#^/en(?:/|$)#', $path );
	}

	public function query_vars( array $vars ): array {
		$vars[] = 'kuka_lang';
		return $vars;
	}

	/** Prefix every existing public rewrite while retaining the original query. */
	public function translated_rewrite_rules( array $rules ): array {
		$translated = array( 'en/?$' => 'index.php?kuka_lang=en' );
		foreach ( $rules as $pattern => $query ) {
			$pattern = ltrim( (string) $pattern, '^' );
			$query   = str_starts_with( (string) $query, 'index.php?' ) ? substr( (string) $query, 10 ) : (string) $query;
			$translated[ 'en/' . $pattern ] = 'index.php?kuka_lang=en&' . $query;
		}
		return $translated + $rules;
	}

	public function request_locale( string $locale ): string {
		return self::is_english_request() ? 'en_US' : $locale;
	}

	public function switch_runtime_locale(): void {
		if ( self::is_english_request() && 'en_US' !== get_locale() ) {
			switch_to_locale( 'en_US' );
		}
	}

	/** Prefix ordinary storefront home URLs on an English request. */
	public function filter_home_url( string $url, string $path, ?string $scheme, ?int $blog_id ): string {
		unset( $scheme, $blog_id );
		if ( ! self::is_english_request() || str_starts_with( ltrim( $path, '/' ), 'en/' ) ) { return $url; }
		if ( preg_match( '#^(wp-admin|wp-login\.php|wp-json)(?:/|$)#', ltrim( $path, '/' ) ) ) { return $url; }
		return self::url_for_language( $url, 'en' );
	}

	public static function url_for_language( string $url, string $language ): string {
		$home = untrailingslashit( (string) get_option( 'home' ) );
		if ( ! str_starts_with( $url, $home ) ) { return $url; }
		$rest = substr( $url, strlen( $home ) );
		$rest = preg_replace( '#^/en(?=/|$)#', '', $rest ) ?: $rest;
		return $home . ( 'en' === $language ? '/en' : '' ) . ( str_starts_with( $rest, '/' ) ? $rest : '/' . $rest );
	}

	public static function current_url( string $language ): string {
		$request = (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' );
		$home    = untrailingslashit( (string) get_option( 'home' ) );
		$path    = '/' . ltrim( $request, '/' );
		$path    = preg_replace( '#^/en(?=/|\?|$)#', '', $path ) ?: '/';
		return $home . ( 'en' === $language ? '/en' : '' ) . ( str_starts_with( $path, '/' ) ? $path : '/' . $path );
	}

	public function language_metadata(): void {
		if ( is_admin() || is_feed() ) { return; }
		$tr = self::current_url( 'tr' );
		$en = self::current_url( 'en' );
		$current = self::is_english_request() ? $en : $tr;
		echo '<link rel="canonical" href="' . esc_url( $current ) . '">' . "\n";
		echo '<link rel="alternate" hreflang="tr" href="' . esc_url( $tr ) . '">' . "\n";
		echo '<link rel="alternate" hreflang="en" href="' . esc_url( $en ) . '">' . "\n";
		echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( $tr ) . '">' . "\n";
		echo '<meta property="og:locale" content="' . esc_attr( self::is_english_request() ? 'en_US' : 'tr_TR' ) . '">' . "\n";
	}

	public function register_sitemap_provider(): void {
		if ( ! function_exists( 'wp_sitemaps_get_server' ) || ! class_exists( 'WP_Sitemaps_Provider' ) ) { return; }
		$server = wp_sitemaps_get_server();
		if ( ! $server->registry->get_provider( 'english' ) ) {
			$server->registry->add_provider( 'english', new Kuka_Island_English_Sitemap_Provider() );
		}
	}
}

final class Kuka_Island_English_Sitemap_Provider extends WP_Sitemaps_Provider {
	public function __construct() {
		$this->name = 'english';
		$this->object_type = 'language';
	}

	public function get_url_list( $page_num, $object_subtype = '' ): array {
		unset( $object_subtype );
		if ( 1 !== (int) $page_num ) { return array(); }
		$urls = array( array( 'loc' => Kuka_Island_Core_Language::url_for_language( (string) get_option( 'home' ) . '/', 'en' ) ) );
		foreach ( get_posts( array( 'post_type' => array( 'page', 'product' ), 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ) ) as $post_id ) {
			$urls[] = array( 'loc' => Kuka_Island_Core_Language::url_for_language( get_permalink( $post_id ), 'en' ) );
		}
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$urls[] = array( 'loc' => Kuka_Island_Core_Language::url_for_language( wc_get_page_permalink( 'shop' ), 'en' ) );
		}
		return $urls;
	}

	public function get_max_num_pages( $object_subtype = '' ): int {
		unset( $object_subtype );
		return 1;
	}
}
