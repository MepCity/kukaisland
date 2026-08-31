<?php
/**
 * Read-only acceptance for the three-state legal identifier contract.
 *
 * MERSİS, KEP, meslek odası, davranış kuralları ve ETBİS alanlarının her biri
 * "mevcut", "bekliyor" veya "uygulanamaz" olabilir. Bu ölçüm hiçbir seçeneği
 * varsaymadan üç durumun ayrı ayrı doğru davrandığını gösterir: yalnız
 * doğrulanmış "mevcut" değer yayımlanır, "bekliyor" lansman eksikliğidir,
 * "uygulanamaz" ne yayımlanır ne de eksik sayılır.
 *
 * Betik hiçbir seçeneği yazmaz; yalnız bellekteki kopyalar üzerinde çalışır.
 */

if ( ! class_exists( 'Kuka_Island_Core_Site_Appearance' ) ) {
	WP_CLI::error( 'Kuka_Island_Core_Site_Appearance missing' );
}

$appearance = 'Kuka_Island_Core_Site_Appearance';
$base       = $appearance::get();
$value_keys = array_values( $appearance::legal_status_map() );

/** Sample values that pass each field's own verification rule. */
$verified_values = array(
	'mersis_number'          => '0123456789012345',
	'kep_address'            => 'kuka@hs01.kep.tr',
	'professional_chamber'   => 'İstanbul Ticaret Odası',
	'professional_rules_url' => 'https://example.com/davranis-kurallari',
	'etbis_number'           => 'ETBIS-0000-0000',
);

/**
 * Build an in-memory copy of the stored content with every legal field forced
 * into one state. The stored option is never touched.
 */
$with_state = static function ( string $status, bool $fill ) use ( $base, $appearance, $verified_values ): array {
	$content = $base;
	foreach ( $appearance::legal_status_map() as $status_key => $value_key ) {
		$content['legal'][ $status_key ] = $status;
		$content['legal'][ $value_key ]  = $fill ? $verified_values[ $value_key ] : '';
	}
	return $content;
};

$scenarios = array(
	'present'        => $with_state( 'present', true ),
	'pending'        => $with_state( 'pending', true ),
	'not_applicable' => $with_state( 'not_applicable', true ),
	'unverified'     => $with_state( 'present', false ),
);

// 1. Resolved state per scenario.
$state_report = array();
foreach ( $scenarios as $name => $content ) {
	$states = array_unique( array_values( $appearance::legal_field_states( $content ) ) );
	$state_report[] = $name . ':' . ( 1 === count( $states ) ? $states[0] : 'mixed' );
}
WP_CLI::line( 'LEGAL_STATE_RESOLUTION=' . implode( '|', $state_report ) );

// 2. Publication: only a declared-present, verified value reaches the site.
$publish_report = array();
foreach ( $scenarios as $name => $content ) {
	$published = count( array_filter( $value_keys, static fn( string $key ): bool => Kuka_Island_Core_Site_Appearance::legal_field_publishable( $content, $key ) ) );
	$publish_report[] = $name . ':' . $published;
}
WP_CLI::line( 'LEGAL_STATE_PUBLISHED=' . implode( '|', $publish_report ) );

// 3. Readiness meter: "uygulanamaz" leaves numerator and denominator alike.
$readiness_report = array();
$missing_report   = array();
foreach ( $scenarios as $name => $content ) {
	$totals = $appearance::iyzico_readiness_totals( $appearance::iyzico_application_checks( $content ) );
	$readiness_report[] = sprintf( '%s:%d/%d', $name, $totals['complete'], $totals['total'] );
	$missing_report[]   = sprintf( '%s:%d', $name, $totals['missing'] );
}
WP_CLI::line( 'LEGAL_STATE_READINESS=' . implode( '|', $readiness_report ) );
WP_CLI::line( 'LEGAL_STATE_MISSING=' . implode( '|', $missing_report ) );

// 4. Value verification rules the migration and the publication gate share.
$verification = array(
	'filled'      => $appearance::legal_value_verified( 'mersis_number', '0123456789012345' ) ? 'yes' : 'no',
	'empty'       => $appearance::legal_value_verified( 'mersis_number', '' ) ? 'yes' : 'no',
	'placeholder' => $appearance::legal_value_verified( 'mersis_number', '[MERSİS NO]' ) ? 'yes' : 'no',
	'bad_email'   => $appearance::legal_value_verified( 'kep_address', 'kuka(at)hs01.kep.tr' ) ? 'yes' : 'no',
	'good_email'  => $appearance::legal_value_verified( 'kep_address', 'kuka@hs01.kep.tr' ) ? 'yes' : 'no',
	'bad_url'     => $appearance::legal_value_verified( 'professional_rules_url', 'javascript:alert(1)' ) ? 'yes' : 'no',
	'good_url'    => $appearance::legal_value_verified( 'professional_rules_url', 'https://example.com/kurallar' ) ? 'yes' : 'no',
);
WP_CLI::line( 'LEGAL_VALUE_VERIFICATION=' . implode( '|', array_map( static fn( string $k, string $v ): string => $k . ':' . $v, array_keys( $verification ), $verification ) ) );

// 5. Save boundary: unknown or absent input falls back to "bekliyor", never to
//    "uygulanamaz". sanitize() is pure, so this stays a read-only measurement.
$sanitize = new ReflectionMethod( $appearance, 'sanitize' );
$sanitize->setAccessible( true );
$sanitized_unknown = $sanitize->invoke( null, array( 'legal' => array( 'mersis_status' => 'uygulanamaz-belki', 'kep_status' => '', 'etbis_status' => 'not_applicable' ) ) );
WP_CLI::line( sprintf(
	'LEGAL_STATUS_SANITIZE=unknown:%s|absent:%s|explicit:%s',
	(string) $sanitized_unknown['legal']['mersis_status'],
	(string) $sanitized_unknown['legal']['kep_status'],
	(string) $sanitized_unknown['legal']['etbis_status']
) );

// 6. The migration may only ever assign "mevcut" or "bekliyor"; a legal call is
//    the operator's, so no code path can reach "uygulanamaz" on its own.
$source        = (string) file_get_contents( WP_PLUGIN_DIR . '/kuka-island-core/includes/class-site-appearance.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
$migrate_body  = (string) ( preg_split( '/private static function migrate\(/', $source )[1] ?? '' );
$migrate_body  = (string) ( preg_split( '/\n\t\/\*\*/', $migrate_body )[0] ?? '' );
preg_match_all( '/self::LEGAL_STATUS_[A-Z_]+/', $migrate_body, $matches );
$migrate_targets = array_values( array_unique( $matches[0] ) );
sort( $migrate_targets );
WP_CLI::line( 'LEGAL_MIGRATION_TARGETS=' . ( $migrate_targets ? implode( ',', str_replace( 'self::LEGAL_STATUS_', '', $migrate_targets ) ) : 'none' ) );

// 7. Defaults ship as "bekliyor" for every field.
$defaults        = new ReflectionMethod( $appearance, 'defaults' );
$defaults->setAccessible( true );
$default_legal   = $defaults->invoke( null )['legal'];
$default_states  = array_unique( array_map( static fn( string $key ): string => (string) ( $default_legal[ $key ] ?? 'missing' ), array_keys( $appearance::legal_status_map() ) ) );
WP_CLI::line( 'LEGAL_STATUS_DEFAULT=' . ( 1 === count( $default_states ) ? (string) reset( $default_states ) : 'mixed' ) );
