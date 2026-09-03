<?php
/**
 * Invoice exception hierarchy.
 *
 * @package Kuka_Island_EDM
 */

defined( 'ABSPATH' ) || exit;

/**
 * Base invoice exception.
 */
class Kuka_Island_Core_Invoice_Exception extends Exception {
	protected string $safe_error_code;
	protected string $user_message;

	/**
	 * Printable classification of the underlying failure.
	 *
	 * Holds either the empty array (no diagnostic attached) or exactly the four
	 * allow-listed fields Kuka_Island_Core_EDM_Fault_Classifier::normalize()
	 * produces: category, fault_kind, marker and a real boolean retryable.
	 * Remote fault text can never be placed here -- set_diagnostic() normalises
	 * whatever it is handed, so an unlisted value collapses to a safe default
	 * rather than being stored.
	 *
	 * @var array<string, mixed>
	 */
	protected array $diagnostic = array();

	public function __construct( string $message = '', string $safe_error_code = 'invoice_error', string $user_message = '', int $code = 0, ?Throwable $previous = null ) {
		$this->safe_error_code = $safe_error_code;
		$this->user_message    = '' !== $user_message ? $user_message : __( 'Fatura işlemi sırasında bir hata oluştu.', 'kuka-island-edm' );
		parent::__construct( $message, $code, $previous );
	}

	public function get_safe_error_code(): string {
		return $this->safe_error_code;
	}

	public function get_user_message(): string {
		return $this->user_message;
	}

	/**
	 * Attach a classification, forced through the allow-list on the way in.
	 *
	 * The argument is treated as untrusted: callers outside this package can
	 * reach this method, and a fault verdict is itself derived from remote text.
	 * Normalising here -- rather than trusting the caller -- is what makes the
	 * "no remote byte reaches an output surface" contract structural instead of
	 * conventional.
	 *
	 * @param array<string, mixed> $diagnostic Candidate verdict.
	 */
	public function set_diagnostic( array $diagnostic ): self {
		$this->diagnostic = class_exists( 'Kuka_Island_Core_EDM_Fault_Classifier' )
			? Kuka_Island_Core_EDM_Fault_Classifier::normalize( $diagnostic )
			// Without the classifier there is no allow-list to check against, so
			// nothing is stored at all.
			: array();

		return $this;
	}

	/**
	 * Normalised diagnostic, or the empty array when none was attached.
	 *
	 * Normalised again on the way out: the property is protected, so a subclass
	 * could otherwise assign to it directly.
	 *
	 * @return array<string, mixed>
	 */
	public function get_diagnostic(): array {
		if ( array() === $this->diagnostic || ! class_exists( 'Kuka_Island_Core_EDM_Fault_Classifier' ) ) {
			return array();
		}

		return Kuka_Island_Core_EDM_Fault_Classifier::normalize( $this->diagnostic );
	}

	/**
	 * One-line diagnostic that is safe to print. Empty when none was attached.
	 *
	 * Shape is fixed and built only from allow-listed tokens:
	 * category:<x>|fault_kind:<x>|marker:<x>|retryable:yes|no
	 */
	public function get_safe_diagnostic_line(): string {
		$diagnostic = $this->get_diagnostic();
		if ( array() === $diagnostic ) {
			return '';
		}

		return Kuka_Island_Core_EDM_Fault_Classifier::to_safe_line( $diagnostic );
	}
}

/**
 * Transient (temporary/network/timeout) error that may succeed on retry.
 */
class Kuka_Island_Core_Invoice_Transient_Exception extends Kuka_Island_Core_Invoice_Exception {
	public function __construct( string $message = '', string $safe_error_code = 'network_timeout', string $user_message = '', int $code = 0, ?Throwable $previous = null ) {
		$default_msg = __( 'Fatura sunucusuna bağlanılamadı veya işlem zaman aşımına uğradı. Yeniden denenecektir.', 'kuka-island-edm' );
		parent::__construct( $message, $safe_error_code, '' !== $user_message ? $user_message : $default_msg, $code, $previous );
	}
}

/**
 * Permanent (validation, credentials, business rule) error that will NOT succeed on retry.
 */
class Kuka_Island_Core_Invoice_Permanent_Exception extends Kuka_Island_Core_Invoice_Exception {
	public function __construct( string $message = '', string $safe_error_code = 'validation_failed', string $user_message = '', int $code = 0, ?Throwable $previous = null ) {
		$default_msg = __( 'Fatura verisi doğrulanamadı veya entegratör tarafından kalıcı olarak reddedildi.', 'kuka-island-edm' );
		parent::__construct( $message, $safe_error_code, '' !== $user_message ? $user_message : $default_msg, $code, $previous );
	}
}
