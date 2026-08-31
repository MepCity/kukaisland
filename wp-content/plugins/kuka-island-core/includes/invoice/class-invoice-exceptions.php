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
