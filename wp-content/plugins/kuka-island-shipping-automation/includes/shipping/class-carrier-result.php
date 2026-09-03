<?php
/**
 * Carrier operation result DTO.
 *
 * Carrier-agnostic on purpose: nothing here names DHL, MNG, a JSON field or an
 * HTTP status. An adapter for a second courier produces the same object, and
 * the manager, the store and the admin panel never learn which courier answered.
 *
 * The single most important field is the OUTCOME, and it has four values, not
 * two:
 *
 *   success   -- the carrier answered, and the answer says the operation
 *                completed. Data is populated.
 *   permanent -- the carrier answered, and the answer says no. Retrying the
 *                same request produces the same no. Safe to report and stop.
 *   transient -- the request never reached a decision AND the operation is one
 *                where a repeat cannot create a duplicate (a read). Safe to
 *                retry.
 *   uncertain -- the request may or may not have taken effect at the carrier.
 *                A create that timed out is here. NOTHING may retry an
 *                uncertain write. The only legal next step is a read-only
 *                reconciliation that establishes what actually exists.
 *
 * Collapsing 'uncertain' into 'transient' is how an integration books the same
 * parcel twice, so the two are separate types and the manager treats them as
 * separate branches.
 *
 * @package Kuka_Island_Shipping_Automation
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Shipping_Result {

	public const OUTCOME_SUCCESS   = 'success';
	public const OUTCOME_PERMANENT = 'permanent';
	public const OUTCOME_TRANSIENT = 'transient';
	public const OUTCOME_UNCERTAIN = 'uncertain';

	private string $outcome;
	private string $operation;
	private string $safe_error_code;
	private int $http_status;

	/**
	 * Normalised, allow-listed values read out of the carrier's answer.
	 *
	 * Only scalar values the integration itself named are ever placed here.
	 * Free-text the carrier wrote is not: it can carry a customer name, an
	 * address or an internal identifier, and every consumer of this object
	 * (order note, admin panel, verification output) is a place such text must
	 * never reach.
	 *
	 * @var array<string, scalar>
	 */
	private array $data;

	/**
	 * @param string                $outcome         One of the OUTCOME_* constants.
	 * @param string                $operation       Logical operation name, e.g. 'create_order'.
	 * @param array<string, scalar> $data            Normalised response values.
	 * @param string                $safe_error_code Allow-listed failure code, '' on success.
	 * @param int                   $http_status     Transport status, 0 when no answer arrived.
	 */
	public function __construct( string $outcome, string $operation, array $data = array(), string $safe_error_code = '', int $http_status = 0 ) {
		$this->outcome = in_array(
			$outcome,
			array( self::OUTCOME_SUCCESS, self::OUTCOME_PERMANENT, self::OUTCOME_TRANSIENT, self::OUTCOME_UNCERTAIN ),
			true
		) ? $outcome : self::OUTCOME_UNCERTAIN;

		$this->operation       = $operation;
		$this->safe_error_code = $safe_error_code;
		$this->http_status     = $http_status;
		$this->data            = self::scalars_only( $data );
	}

	/**
	 * Keep only scalar leaves, so no nested carrier structure survives.
	 *
	 * @param array<string, mixed> $data Candidate values.
	 * @return array<string, scalar>
	 */
	private static function scalars_only( array $data ): array {
		$clean = array();
		foreach ( $data as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$clean[ (string) $key ] = $value;
			}
		}

		return $clean;
	}

	/**
	 * @param array<string, mixed> $data Normalised response values.
	 */
	public static function success( string $operation, array $data = array(), int $http_status = 200 ): self {
		return new self( self::OUTCOME_SUCCESS, $operation, $data, '', $http_status );
	}

	public static function permanent( string $operation, string $safe_error_code, int $http_status = 0 ): self {
		return new self( self::OUTCOME_PERMANENT, $operation, array(), $safe_error_code, $http_status );
	}

	public static function transient( string $operation, string $safe_error_code, int $http_status = 0 ): self {
		return new self( self::OUTCOME_TRANSIENT, $operation, array(), $safe_error_code, $http_status );
	}

	public static function uncertain( string $operation, string $safe_error_code, int $http_status = 0 ): self {
		return new self( self::OUTCOME_UNCERTAIN, $operation, array(), $safe_error_code, $http_status );
	}

	public function get_outcome(): string {
		return $this->outcome;
	}

	public function get_operation(): string {
		return $this->operation;
	}

	public function is_success(): bool {
		return self::OUTCOME_SUCCESS === $this->outcome;
	}

	public function is_uncertain(): bool {
		return self::OUTCOME_UNCERTAIN === $this->outcome;
	}

	public function get_safe_error_code(): string {
		return $this->safe_error_code;
	}

	public function get_http_status(): int {
		return $this->http_status;
	}

	/** @return array<string, scalar> */
	public function get_data(): array {
		return $this->data;
	}

	/**
	 * One normalised value, or the given default.
	 *
	 * @param string $key     Data key.
	 * @param scalar $default Fallback.
	 * @return scalar
	 */
	public function get( string $key, $default = '' ) {
		return $this->data[ $key ] ?? $default;
	}

	/**
	 * One line that is safe to print anywhere: order note, admin panel, test
	 * output. Contains only the operation name, the outcome, an allow-listed
	 * error code and the numeric HTTP status.
	 */
	public function to_safe_line(): string {
		return sprintf(
			'operation:%s|outcome:%s|code:%s|http:%d',
			$this->operation,
			$this->outcome,
			'' !== $this->safe_error_code ? $this->safe_error_code : 'none',
			$this->http_status
		);
	}
}
