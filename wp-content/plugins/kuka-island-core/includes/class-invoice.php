<?php
/**
 * Invoice Module Loader and Composition Root.
 *
 * @package Kuka_Island_Core
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Invoice {
	private Kuka_Island_Core_Invoice_Config $config;
	private Kuka_Island_Core_Invoice_Manager $manager;
	private Kuka_Island_Core_Invoice_Queue $queue;
	private Kuka_Island_Core_Invoice_Status_Poller $poller;
	private Kuka_Island_Core_Invoice_Admin $admin;

	public function __construct() {
		$this->load_dependencies();

		$this->config  = new Kuka_Island_Core_Invoice_Config();
		$this->manager = new Kuka_Island_Core_Invoice_Manager( $this->config );
		$this->queue   = new Kuka_Island_Core_Invoice_Queue( $this->manager );
		$this->poller  = new Kuka_Island_Core_Invoice_Status_Poller( $this->manager );
		$this->admin   = new Kuka_Island_Core_Invoice_Admin( $this->manager );
	}

	public function register(): void {
		$this->queue->register();
		$this->poller->register();
		$this->admin->register();
	}

	public function get_manager(): Kuka_Island_Core_Invoice_Manager {
		return $this->manager;
	}

	public function get_config(): Kuka_Island_Core_Invoice_Config {
		return $this->config;
	}

	private function load_dependencies(): void {
		$dir = __DIR__ . '/invoice/';

		require_once $dir . 'interface-invoice-provider.php';
		require_once $dir . 'interface-soap-transport.php';
		require_once $dir . 'class-invoice-exceptions.php';
		require_once $dir . 'class-edm-fault-classifier.php';
		require_once $dir . 'class-edm-request-header.php';
		require_once $dir . 'class-invoice-status.php';
		require_once $dir . 'class-edm-document-status.php';
		require_once $dir . 'class-invoice-fixture-guard.php';
		require_once $dir . 'class-invoice-config.php';
		require_once $dir . 'class-invoice-result.php';
		require_once $dir . 'class-edm-soap-transport.php';
		require_once $dir . 'class-edm-client.php';
		require_once $dir . 'class-edm-provider.php';
		require_once $dir . 'class-ubl-tr-builder.php';
		require_once $dir . 'class-invoice-order-mapper.php';
		require_once $dir . 'class-invoice-order-store.php';
		require_once $dir . 'class-invoice-numbering.php';
		require_once $dir . 'class-invoice-recovery.php';
		require_once $dir . 'class-internet-sales-details.php';
		require_once $dir . 'class-invoice-manager.php';
		require_once $dir . 'class-invoice-status-poller.php';
		require_once $dir . 'class-invoice-queue.php';
		require_once $dir . 'class-invoice-admin.php';
	}
}
