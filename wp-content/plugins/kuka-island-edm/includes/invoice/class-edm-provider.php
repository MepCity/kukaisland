<?php
/**
 * EDM Invoice Provider implementation.
 *
 * @package Kuka_Island_EDM
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/interface-invoice-provider.php';

final class Kuka_Island_Core_EDM_Provider implements Kuka_Island_Core_Invoice_Provider_Interface {
	public const PROVIDER_ID = 'edm';

	private Kuka_Island_Core_EDM_Client $client;

	public function __construct( Kuka_Island_Core_Invoice_Config $config, ?Kuka_Island_Core_SOAP_Transport_Interface $transport = null ) {
		$this->client = new Kuka_Island_Core_EDM_Client( $config, $transport );
	}

	public function get_client(): Kuka_Island_Core_EDM_Client {
		return $this->client;
	}

	public function get_id(): string {
		return self::PROVIDER_ID;
	}

	public function get_name(): string {
		return __( 'EDM Bilişim e-Fatura / e-Arşiv', 'kuka-island-edm' );
	}

	public function login(): string {
		return $this->client->login();
	}

	public function logout(): bool {
		return $this->client->logout();
	}

	public function check_user( string $identifier ): array {
		return $this->client->check_user( $identifier );
	}

	public function send_invoice( array $invoice_payload ): Kuka_Island_Core_Invoice_Result {
		return $this->client->send_invoice( $invoice_payload );
	}

	public function get_invoice_status( string $uuid, string $invoice_number = '' ): Kuka_Island_Core_Invoice_Result {
		return $this->client->get_invoice_status( $uuid, $invoice_number );
	}

	public function get_invoice_document( string $uuid, string $format = 'PDF' ): string {
		return $this->client->get_invoice_document( $uuid, $format );
	}

	public function email_invoice( string $uuid, string $email ): bool {
		return $this->client->email_invoice( $uuid, $email );
	}

	public function check_counter(): array {
		return $this->client->check_counter();
	}

	public function get_invoice_serial( string $serial_code = '', int $year = 0, string $send_type = '' ): array {
		return $this->client->get_invoice_serial( $serial_code, $year, $send_type );
	}
}
