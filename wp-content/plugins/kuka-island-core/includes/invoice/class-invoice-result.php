<?php
/**
 * Invoice operation result DTO.
 *
 * @package Kuka_Island_Core
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Invoice_Result {
	private bool $success;
	private string $status;
	private string $uuid;
	private string $invoice_number;
	private string $status_code;
	private string $status_description;
	private string $safe_error_code;
	private array $raw_data;

	public function __construct( array $data = array() ) {
		$this->success            = (bool) ( $data['success'] ?? false );
		$this->status             = (string) ( $data['status'] ?? Kuka_Island_Core_Invoice_Status::STATUS_NONE );
		$this->uuid               = (string) ( $data['uuid'] ?? '' );
		$this->invoice_number     = (string) ( $data['invoice_number'] ?? '' );
		$this->status_code        = (string) ( $data['status_code'] ?? '' );
		$this->status_description = (string) ( $data['status_description'] ?? '' );
		$this->safe_error_code    = (string) ( $data['safe_error_code'] ?? '' );
		$this->raw_data           = (array) ( $data['raw_data'] ?? array() );
	}

	public static function success( string $uuid, string $invoice_number = '', string $status = Kuka_Island_Core_Invoice_Status::STATUS_SENT, string $code = '', string $desc = '', array $raw = array() ): self {
		return new self(
			array(
				'success'            => true,
				'status'             => $status,
				'uuid'               => $uuid,
				'invoice_number'     => $invoice_number,
				'status_code'        => $code,
				'status_description' => $desc,
				'raw_data'           => $raw,
			)
		);
	}

	public static function failure( string $safe_error_code, string $desc = '', string $uuid = '', array $raw = array() ): self {
		return new self(
			array(
				'success'            => false,
				'status'             => Kuka_Island_Core_Invoice_Status::STATUS_NEEDS_MANUAL_REVIEW,
				'uuid'               => $uuid,
				'safe_error_code'    => $safe_error_code,
				'status_description' => $desc,
				'raw_data'           => $raw,
			)
		);
	}

	public function is_success(): bool {
		return $this->success;
	}

	public function get_status(): string {
		return $this->status;
	}

	public function get_uuid(): string {
		return $this->uuid;
	}

	public function get_invoice_number(): string {
		return $this->invoice_number;
	}

	public function get_status_code(): string {
		return $this->status_code;
	}

	public function get_status_description(): string {
		return $this->status_description;
	}

	public function get_safe_error_code(): string {
		return $this->safe_error_code;
	}

	public function get_raw_data(): array {
		return $this->raw_data;
	}

	public function to_array(): array {
		return array(
			'success'            => $this->success,
			'status'             => $this->status,
			'uuid'               => $this->uuid,
			'invoice_number'     => $this->invoice_number,
			'status_code'        => $this->status_code,
			'status_description' => $this->status_description,
			'safe_error_code'    => $this->safe_error_code,
		);
	}
}
