<?php
/**
 * Real SoapClient transport implementation for EDM integration.
 *
 * @package Kuka_Island_Core
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/interface-soap-transport.php';

final class Kuka_Island_Core_EDM_SOAP_Transport implements Kuka_Island_Core_SOAP_Transport_Interface {
	private ?SoapClient $soap_client = null;
	private string $wsdl;
	private int $timeout;
	private array $options;

	public function __construct( string $wsdl, int $timeout = 30, bool $trace = false, array $custom_options = array() ) {
		$this->wsdl    = $wsdl;
		$this->timeout = $timeout;

		$stream_context = stream_context_create(
			array(
				'ssl' => array(
					'verify_peer'       => true,
					'verify_peer_name'  => true,
					'allow_self_signed' => false,
				),
				'http' => array(
					'timeout' => $this->timeout,
				),
			)
		);

		$this->options = array_merge(
			array(
				'trace'              => $trace ? 1 : 0,
				'exceptions'         => true,
				'cache_wsdl'         => WSDL_CACHE_BOTH,
				'connection_timeout' => $this->timeout,
				'stream_context'     => $stream_context,
				'encoding'           => 'UTF-8',
				'soap_version'       => SOAP_1_1,
			),
			$custom_options
		);
	}

	private function get_client(): SoapClient {
		if ( null === $this->soap_client ) {
			if ( ! class_exists( 'SoapClient' ) ) {
				throw new Kuka_Island_Core_Invoice_Permanent_Exception(
					'PHP ext-soap extension is not installed or loaded.',
					'soap_extension_missing',
					__( 'Sunucuda PHP SoapClient eklentisi bulunamadı.', 'kuka-island-core' )
				);
			}

			try {
				$this->soap_client = new SoapClient( $this->wsdl, $this->options );
			} catch ( SoapFault $fault ) {
				throw new Kuka_Island_Core_Invoice_Transient_Exception(
					'WSDL loading failed.',
					'wsdl_load_failed',
					__( 'EDM WSDL tanımı yüklenemedi. Ağ veya sunucu geçici olarak yanıt vermiyor.', 'kuka-island-core' )
				);
			}
		}

		return $this->soap_client;
	}

	public function call( string $action, array $parameters ) {
		$client = $this->get_client();

		$old_socket_timeout = ini_get( 'default_socket_timeout' );
		ini_set( 'default_socket_timeout', (string) $this->timeout );

		try {
			$response = $client->__soapCall( $action, array( $parameters ) );
		} finally {
			ini_set( 'default_socket_timeout', (string) $old_socket_timeout );
		}

		return $response;
	}

	public function get_last_response(): string {
		return null !== $this->soap_client ? (string) $this->soap_client->__getLastResponse() : '';
	}

	public function get_last_request(): string {
		return null !== $this->soap_client ? (string) $this->soap_client->__getLastRequest() : '';
	}
}
