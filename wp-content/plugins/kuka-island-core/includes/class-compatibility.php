<?php
/**
 * WooCommerce feature compatibility declarations.
 */

defined( 'ABSPATH' ) || exit;

final class Kuka_Island_Core_Compatibility {
	public function register(): void {
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );

		// Hemen, kanca beklemeden: aşağıdaki sabiti kullanan eklenti kodu bir
		// kancaya bağlı olmayabilir, kendi sınıfının kurucusunda çalışabilir.
		self::ensure_chmod_file_constant();
	}

	/**
	 * `FS_CHMOD_FILE` sabitini WordPress'in kendi varsayılanıyla garanti eder.
	 *
	 * SORUN. WordPress bu sabiti yalnız `WP_Filesystem()` içinde tanımlar, o da
	 * yalnız `wp-admin/includes/file.php` yüklendiğinde vardır. WP-CLI yönetim
	 * tarafını yüklemez. Bazı eklenti kodu — bu kurulumda iyzico'nun
	 * `AbstractLogger` sınıfı — `$wp_filesystem` doluysa kurulum bloğunu
	 * atlayıp doğrudan `\FS_CHMOD_FILE` kullanır; global dolu ama sabit
	 * tanımsızsa PHP "Undefined constant" ile ölür. Aynı şey
	 * `get_filesystem_method()` 'direct' dönmediğinde de olur: eklentinin
	 * yedek yolu erken döner ve sabiti hiç tanımlamaz.
	 *
	 * ÖLÇÜLEN DURUM. Bu konteynerde `get_filesystem_method()` `direct` döndüğü
	 * ve iyzico'nun logger'ı bootstrap sırasında `WP_Filesystem()` çağırdığı
	 * için sabit şu an tanımlı ve değeri `0644`. Yani hata bu ortamda
	 * ÜRETİLEMEDİ; kırılganlık, sabitin tanımlanmasının bir eklentinin yükleme
	 * sırasına ve dosya sistemi metoduna bağlı olması.
	 *
	 * DÜZELTME PROJE TARAFINDA. Vendor dosyasına dokunulmaz. Sabit burada,
	 * WordPress'in `wp-admin/includes/file.php` içindeki **kendi formülüyle**
	 * tanımlanır: `fileperms( ABSPATH . 'index.php' ) & 0777 | 0644`. Zaten
	 * tanımlıysa hiçbir şey yapılmaz, dolayısıyla WordPress'in ya da başka bir
	 * eklentinin değeri hiçbir koşulda değiştirilmez.
	 *
	 * @return string `already_defined` ya da `defined_now`.
	 */
	public static function ensure_chmod_file_constant(): string {
		if ( defined( 'FS_CHMOD_FILE' ) ) {
			return 'already_defined';
		}

		define( 'FS_CHMOD_FILE', self::chmod_file_default() );

		return 'defined_now';
	}

	/**
	 * WordPress'in `FS_CHMOD_FILE` için kullandığı formülün aynısı.
	 *
	 * Ayrı bir metot, çünkü ölçüm sabitin DEĞERİNİN WordPress'in kendi
	 * hesabıyla aynı olduğunu doğrulamak zorunda: farklı bir izin maskesi
	 * yazmak, bu sabiti kullanan her dosyayı sessizce başka bir modla
	 * oluşturur.
	 */
	public static function chmod_file_default(): int {
		return ( fileperms( ABSPATH . 'index.php' ) & 0777 ) | 0644;
	}

	public function declare_compatibility(): void {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', KUKA_ISLAND_CORE_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', KUKA_ISLAND_CORE_FILE, true );
		}
	}
}

