<?php
/**
 * Turn the order's free-text city and district into the carrier's own codes.
 *
 * Both Customer.cityCode and Customer.districtCode say the same thing in the
 * vendor's document -- "you can provide code information from CBS Info API" --
 * so the codes are looked up, never derived from a table this project maintains
 * and never inferred from the order's postcode.
 *
 * MATCHING HAPPENS IN TWO STEPS, AND NEITHER OF THEM GUESSES.
 *
 * Step one is an exact comparison after Turkish folding. Turkish makes that
 * non-trivial: PHP's strtolower() does not know the dotted/dotless I rule, so
 * 'İstanbul' and 'İSTANBUL' would not compare equal without help.
 *
 * Step two exists because of a genuine property of Turkish text rather than as
 * a convenience. 'ISTANBUL' is not the Turkish uppercase of 'İstanbul' --
 * 'İSTANBUL' is -- yet a customer on a non-Turkish keyboard types 'Istanbul',
 * 'Kadikoy' and 'Sisli' constantly, and step one rejects every one of them. So
 * when step one finds nothing, both sides are folded again to plain ASCII and
 * the comparison is retried -- but the result is accepted ONLY IF EXACTLY ONE
 * candidate matches. That is not fuzzy matching and it is not a guess: it is a
 * uniqueness proof. Two places that collide under ASCII folding are refused as
 * ambiguous, by name, and the order waits for a person.
 *
 * What is deliberately never done is approximate matching: no prefix match, no
 * edit distance, no "closest district". A parcel addressed to a district code
 * that was a near miss is a parcel delivered to the wrong town, and the operator
 * would never see it happen.
 *
 * CACHING IS BOUNDED AND ONLY EVER OF SUCCESS. Cities and districts change
 * rarely, and re-fetching the whole list per order would put a network call in
 * the path of every shipment. So a successful listing is cached for a day; a
 * failed one is not cached at all, so an outage cannot pin an empty list in
 * front of every subsequent lookup. The cache key carries a version so a change
 * to the stored shape invalidates it instead of being misread.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_DHL_Address_Resolver {

	/** Bump when the cached array shape changes. */
	public const CACHE_VERSION = 'v1';

	public const CITIES_KEY_PREFIX    = 'kuka_dhl_cbs_cities_';
	public const DISTRICTS_KEY_PREFIX = 'kuka_dhl_cbs_districts_';

	/**
	 * The suffix every cache key carries. Defaults to the shape version.
	 *
	 * Settable so a verification run can take a key space of its OWN. A suite
	 * has to empty this cache before it resolves an address -- a warm cache
	 * means the mock's /getcities is never called and the request counts stop
	 * meaning anything -- and it used to do that by deleting and rewriting the
	 * shop's rows. Then the cleanup had to put them back, and "put them back"
	 * is a promise a crashed process cannot keep.
	 *
	 * With its own namespace the run never reads or writes the shop's rows at
	 * all, so there is nothing to restore and nothing to get wrong. The only
	 * rows it can leave behind are ones nothing else could have created.
	 *
	 * @var string
	 */
	private string $cache_namespace = self::CACHE_VERSION;

	/** One day. Long enough to keep the network out of the order path, short
	 *  enough that a genuine change to the carrier's coverage arrives by itself. */
	private const CACHE_TTL = DAY_IN_SECONDS;

	private Kuka_Island_Shipping_DHL_Client $client;

	public function __construct( Kuka_Island_Shipping_DHL_Client $client ) {
		$this->client = $client;
	}

	/**
	 * Fold a Turkish place name into a comparable form.
	 *
	 * Lower-cases with the Turkish dotted/dotless I rule applied FIRST (so 'I'
	 * becomes 'ı' and 'İ' becomes 'i', which is what a Turkish reader means),
	 * then strips everything that is not a letter or a digit so that 'K. Maraş',
	 * 'K.Maraş' and 'K Maraş' agree.
	 *
	 * Public and static because it is the single comparison rule in this
	 * integration, and a rule that cannot be measured on its own is a rule that
	 * silently drifts.
	 */
	public static function fold( string $value ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return '';
		}

		$value = strtr(
			$value,
			array(
				'I' => 'ı',
				'İ' => 'i',
				'Ş' => 'ş',
				'Ğ' => 'ğ',
				'Ü' => 'ü',
				'Ö' => 'ö',
				'Ç' => 'ç',
			)
		);

		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );

		// Keep Turkish letters and digits; drop punctuation, spaces and
		// everything else.
		$value = preg_replace( '/[^\p{L}\p{N}]+/u', '', $value );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Fold further, down to plain ASCII letters.
	 *
	 * Used ONLY by the uniqueness step, never for a direct match. Applied to
	 * both sides, so 'Kadikoy' and 'KADIKÖY' meet at 'kadikoy' -- and so would
	 * any other place that happens to collide there, which is exactly why the
	 * caller requires the collision set to hold one entry.
	 */
	public static function fold_ascii( string $value ): string {
		$folded = self::fold( $value );

		return strtr(
			$folded,
			array(
				'ı' => 'i',
				'ş' => 's',
				'ğ' => 'g',
				'ü' => 'u',
				'ö' => 'o',
				'ç' => 'c',
				'â' => 'a',
				'î' => 'i',
				'û' => 'u',
			)
		);
	}

	/**
	 * Resolve a city and district pair.
	 *
	 * @param string $city     Free-text city from the order.
	 * @param string $district Free-text district from the order.
	 * @return Kuka_Island_Shipping_Result Data carries city_code, city_name, district_code, district_name.
	 */
	public function resolve( string $city, string $district ): Kuka_Island_Shipping_Result {
		if ( '' === self::fold( $city ) ) {
			return Kuka_Island_Shipping_Result::permanent( 'resolve_location', 'city_missing' );
		}

		if ( '' === self::fold( $district ) ) {
			return Kuka_Island_Shipping_Result::permanent( 'resolve_location', 'district_missing' );
		}

		$cities = $this->cities();

		if ( ! $cities['ok'] ) {
			return $cities['result'];
		}

		$city_match = self::match( $cities['places'], $city );

		if ( null === $city_match['place'] ) {
			return Kuka_Island_Shipping_Result::permanent( 'resolve_location', 'city_' . $city_match['reason'] );
		}

		$districts = $this->districts( $city_match['place']['code'] );

		if ( ! $districts['ok'] ) {
			return $districts['result'];
		}

		$district_match = self::match( $districts['places'], $district );

		if ( null === $district_match['place'] ) {
			return Kuka_Island_Shipping_Result::permanent( 'resolve_location', 'district_' . $district_match['reason'] );
		}

		return Kuka_Island_Shipping_Result::success(
			'resolve_location',
			array(
				'city_code'     => $city_match['place']['code'],
				'city_name'     => $city_match['place']['name'],
				'district_code' => $district_match['place']['code'],
				'district_name' => $district_match['place']['name'],
				// Which of the two steps answered. Recorded so an operator
				// reading the audit trail can see that an ASCII spelling was
				// accepted because it was unique, not because it was close.
				'match_mode'    => $city_match['mode'] . '+' . $district_match['mode'],
			)
		);
	}

	/**
	 * Exact Turkish match, then a unique ASCII match, then nothing.
	 *
	 * @param array<int, array{code: string, name: string}> $places Candidates.
	 * @param string                                        $needle Raw name from the order.
	 * @return array{place: array{code: string, name: string}|null, mode: string, reason: string}
	 */
	public static function match( array $places, string $needle ): array {
		$exact = array();
		foreach ( $places as $place ) {
			if ( self::fold( $place['name'] ) === self::fold( $needle ) ) {
				$exact[] = $place;
			}
		}

		if ( 1 === count( $exact ) ) {
			return array(
				'place'  => $exact[0],
				'mode'   => 'exact',
				'reason' => '',
			);
		}

		/*
		 * More than one carrier row folding to the same Turkish name is the
		 * carrier's own data being ambiguous. Picking the first would be a coin
		 * toss with a parcel on it.
		 */
		if ( count( $exact ) > 1 ) {
			return array(
				'place'  => null,
				'mode'   => 'exact',
				'reason' => 'ambiguous',
			);
		}

		$ascii = array();
		foreach ( $places as $place ) {
			if ( self::fold_ascii( $place['name'] ) === self::fold_ascii( $needle ) ) {
				$ascii[] = $place;
			}
		}

		if ( 1 === count( $ascii ) ) {
			return array(
				'place'  => $ascii[0],
				'mode'   => 'ascii_unique',
				'reason' => '',
			);
		}

		return array(
			'place'  => null,
			'mode'   => 'none',
			'reason' => count( $ascii ) > 1 ? 'ambiguous' : 'not_found',
		);
	}

	/**
	 * The city list, from cache when possible.
	 *
	 * @return array{ok: bool, places: array<int, array{code: string, name: string}>, result: Kuka_Island_Shipping_Result}
	 */
	public function cities(): array {
		$key    = $this->cities_cache_key();
		$cached = get_transient( $key );

		if ( is_array( $cached ) && array() !== $cached ) {
			return array(
				'ok'     => true,
				'places' => $cached,
				'result' => Kuka_Island_Shipping_Result::success( 'get_cities', array( 'count' => count( $cached ), 'source' => 'cache' ) ),
			);
		}

		$response = $this->client->get_cities();

		if ( ! $response['ok'] || array() === $response['cities'] ) {
			// Never cache a failure or an empty list: an outage would otherwise
			// keep answering "no cities" for a day.
			return array(
				'ok'     => false,
				'places' => array(),
				'result' => $response['ok']
					? Kuka_Island_Shipping_Result::permanent( 'get_cities', 'empty_reference_data' )
					: $response['result'],
			);
		}

		set_transient( $key, $response['cities'], self::CACHE_TTL );

		return array(
			'ok'     => true,
			'places' => $response['cities'],
			'result' => $response['result'],
		);
	}

	/**
	 * The district list for one city, from cache when possible.
	 *
	 * @param string $city_code City code.
	 * @return array{ok: bool, places: array<int, array{code: string, name: string}>, result: Kuka_Island_Shipping_Result}
	 */
	public function districts( string $city_code ): array {
		$key    = $this->districts_cache_key( $city_code );
		$cached = get_transient( $key );

		if ( is_array( $cached ) && array() !== $cached ) {
			return array(
				'ok'     => true,
				'places' => $cached,
				'result' => Kuka_Island_Shipping_Result::success( 'get_districts', array( 'count' => count( $cached ), 'source' => 'cache' ) ),
			);
		}

		$response = $this->client->get_districts( $city_code );

		if ( ! $response['ok'] || array() === $response['districts'] ) {
			return array(
				'ok'     => false,
				'places' => array(),
				'result' => $response['ok']
					? Kuka_Island_Shipping_Result::permanent( 'get_districts', 'empty_reference_data' )
					: $response['result'],
			);
		}

		set_transient( $key, $response['districts'], self::CACHE_TTL );

		return array(
			'ok'     => true,
			'places' => $response['districts'],
			'result' => $response['result'],
		);
	}

	/**
	 * Drop the cached reference data.
	 *
	 * Deliberately explicit rather than automatic. An operator who has been told
	 * the carrier added a district needs a way to make that visible today, and a
	 * cache with no purge is a cache somebody eventually clears by deleting
	 * rows from the database by hand.
	 *
	 * @param array<int, string> $city_codes City codes whose districts to drop.
	 * @return int Number of cache entries removed.
	 */
	/**
	 * Move this resolver onto its own cache key space.
	 *
	 * Only a verification run has a reason to call this. The namespace is
	 * reduced to the characters a transient name may safely carry, and an empty
	 * value puts the resolver back on the shop's own keys.
	 *
	 * @param string $namespace Key suffix.
	 */
	public function set_cache_namespace( string $namespace ): void {
		$clean = preg_replace( '/[^A-Za-z0-9_-]/', '', $namespace );

		$this->cache_namespace = '' !== (string) $clean ? (string) $clean : self::CACHE_VERSION;
	}

	public function cache_namespace(): string {
		return $this->cache_namespace;
	}

	/** The transient name the city list is cached under. */
	public function cities_cache_key(): string {
		return self::CITIES_KEY_PREFIX . $this->cache_namespace;
	}

	/**
	 * The transient name one city's district list is cached under.
	 *
	 * @param string $city_code Carrier city code.
	 */
	public function districts_cache_key( string $city_code ): string {
		return self::DISTRICTS_KEY_PREFIX . $this->cache_namespace . '_' . $city_code;
	}

	public function purge_cache( array $city_codes = array() ): int {
		$removed = 0;

		if ( delete_transient( $this->cities_cache_key() ) ) {
			++$removed;
		}

		foreach ( $city_codes as $city_code ) {
			if ( delete_transient( $this->districts_cache_key( $city_code ) ) ) {
				++$removed;
			}
		}

		return $removed;
	}
}
