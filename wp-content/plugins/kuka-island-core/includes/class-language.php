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
