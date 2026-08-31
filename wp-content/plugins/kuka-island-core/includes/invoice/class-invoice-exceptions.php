<?php
/**
 * Invoice exception hierarchy.
 *
 * @package Kuka_Island_Core
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
	 * Only ever holds the fixed vocabulary produced by
	 * Kuka_Island_Core_EDM_Fault_Classifier: a category, a folded fault kind,
	 * the name of the matched marker, a retryable flag and a truncated digest.
	 * Remote fault text is never placed here.
	 *
	 * @var array<string, mixed>
	 */
	protected array $diagnostic = array();

	public function __construct( string $message = '', string $safe_error_code = 'invoice_error', string $user_message = '', int $code = 0, ?Throwable $previous = null ) {
		$this->safe_error_code = $safe_error_code;
		$this->user_message    = '' !== $user_message ? $user_message : __( 'Fatura işlemi sırasında bir hata oluştu.', 'kuka-island-core' );
		parent::__construct( $message, $code, $previous );
	}

	public function get_safe_error_code(): string {
		return $this->safe_error_code;
	}

	public function get_user_message(): string {
		return $this->user_message;
	}

	/**
	 * Attach a safe classification.
	 *
	 * @param array<string, mixed> $diagnostic Verdict from the fault classifier.
	 */
	public function set_diagnostic( array $diagnostic ): self {
		$this->diagnostic = $diagnostic;

		return $this;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_diagnostic(): array {
		return $this->diagnostic;
	}

	/**
	 * One-line diagnostic that is safe to print. Empty when none was attached.
	 */
	public function get_safe_diagnostic_line(): string {
		if ( array() === $this->diagnostic ) {
			return '';
		}

		return Kuka_Island_Core_EDM_Fault_Classifier::to_safe_line( $this->diagnostic );
	}
}

/**
 * Transient (temporary/network/timeout) error that may succeed on retry.
 */
class Kuka_Island_Core_Invoice_Transient_Exception extends Kuka_Island_Core_Invoice_Exception {
	public function __construct( string $message = '', string $safe_error_code = 'network_timeout', string $user_message = '', int $code = 0, ?Throwable $previous = null ) {
		$default_msg = __( 'Fatura sunucusuna bağlanılamadı veya işlem zaman aşımına uğradı. Yeniden denenecektir.', 'kuka-island-core' );
		parent::__construct( $message, $safe_error_code, '' !== $user_message ? $user_message : $default_msg, $code, $previous );
	}
}

/**
 * Permanent (validation, credentials, business rule) error that will NOT succeed on retry.
 */
class Kuka_Island_Core_Invoice_Permanent_Exception extends Kuka_Island_Core_Invoice_Exception {
	public function __construct( string $message = '', string $safe_error_code = 'validation_failed', string $user_message = '', int $code = 0, ?Throwable $previous = null ) {
		$default_msg = __( 'Fatura verisi doğrulanamadı veya entegratör tarafından kalıcı olarak reddedildi.', 'kuka-island-core' );
		parent::__construct( $message, $safe_error_code, '' !== $user_message ? $user_message : $default_msg, $code, $previous );
	}
}
