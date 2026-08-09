<?php
/** Scroll-led brand story with a server-rendered article fallback. */
defined( 'ABSPATH' ) || exit;

$story_content = class_exists( 'Kuka_Island_Core_Site_Appearance' ) ? Kuka_Island_Core_Site_Appearance::get() : array();
$story_scenes  = $story_content['story']['scenes'] ?? array();
$story_english = function_exists( 'kuka_island_is_english' ) && kuka_island_is_english();

/** Resolve a localized scene value while retaining the Turkish source fallback. */
$story_value = static function ( array $scene, string $key ) use ( $story_english ): mixed {
	if ( $story_english ) {
		$translated = $scene[ $key . '_en' ] ?? '';
		if ( is_int( $translated ) || ctype_digit( (string) $translated ) ) {
			return absint( $translated ) ?: ( $scene[ $key ] ?? 0 );
		}
		if ( '' !== trim( (string) $translated ) ) { return $translated; }
	}
	return $scene[ $key ] ?? '';
};

/** Print one decorative responsive story image, deferred unless explicitly eager. */
$story_picture = static function ( int $desktop_id, int $mobile_id, bool $eager, string $class_name ) {
	$image_id = $desktop_id ?: $mobile_id;
	if ( ! $image_id ) { ?><div class="<?php echo esc_attr( $class_name ); ?> kuka-story__placeholder" aria-hidden="true"></div><?php return; }
	$desktop = wp_get_attachment_image_src( $image_id, 'full' );
	$mobile  = wp_get_attachment_image_src( $mobile_id ?: $image_id, 'full' );
	if ( ! $desktop || ! $mobile ) { ?><div class="<?php echo esc_attr( $class_name ); ?> kuka-story__placeholder" aria-hidden="true"></div><?php return; }
	$desktop_srcset = wp_get_attachment_image_srcset( $image_id, 'full' ) ?: '';
	$mobile_srcset  = wp_get_attachment_image_srcset( $mobile_id ?: $image_id, 'full' ) ?: '';
	$src_attr       = $eager ? 'src' : 'data-story-src';
	$srcset_attr    = $eager ? 'srcset' : 'data-story-srcset';
	?>
	<picture class="<?php echo esc_attr( $class_name ); ?>" aria-hidden="true">
		<source media="(max-width: 47.99em)" <?php echo esc_attr( $srcset_attr ); ?>="<?php echo esc_attr( $mobile_srcset ?: $mobile[0] ); ?>">
		<img <?php echo esc_attr( $src_attr ); ?>="<?php echo esc_url( $desktop[0] ); ?>" <?php if ( $desktop_srcset ) : ?><?php echo esc_attr( $srcset_attr ); ?>="<?php echo esc_attr( $desktop_srcset ); ?>"<?php endif; ?> sizes="100vw" alt="" loading="<?php echo $eager ? 'eager' : 'lazy'; ?>" decoding="async" <?php if ( $eager ) : ?>fetchpriority="high"<?php endif; ?>>
	</picture>
	<?php
};

/** Preserve paragraph and deliberate line breaks without accepting panel HTML. */
$story_text = static function ( string $text, bool $reveal_lines ) {
	$paragraphs = preg_split( '/\R{2,}/u', trim( $text ) ) ?: array();
	foreach ( $paragraphs as $paragraph_index => $paragraph ) {
		$lines = preg_split( '/\R/u', $paragraph ) ?: array();
		if ( count( $paragraphs ) - 1 === $paragraph_index && array( 'Love,', 'KÜBRA' ) === $lines ) {
			?><footer class="kuka-story__sign"><span>Love,</span><strong>KÜBRA</strong></footer><?php
			continue;
		}
		?><p><?php foreach ( $lines as $line_index => $line ) : ?><span<?php echo $reveal_lines ? ' class="kuka-story__line"' : ''; ?>><?php echo esc_html( $line ); ?></span><?php if ( count( $lines ) - 1 !== $line_index ) : ?><br><?php endif; ?><?php endforeach; ?></p><?php
	}
};

get_header();
while ( have_posts() ) : the_post(); ?>
	<main class="kuka-story" data-kuka-story data-scene-count="<?php echo esc_attr( (string) count( $story_scenes ) ); ?>">
		<h1 class="screen-reader-text"><?php the_title(); ?></h1>
		<div class="kuka-story__stage">
			<div class="kuka-story__media" aria-hidden="true">
				<?php foreach ( $story_scenes as $index => $scene ) :
					$desktop_id = absint( $story_value( $scene, 'desktop_image_id' ) );
					$mobile_id  = absint( $story_value( $scene, 'mobile_image_id' ) );
					$story_picture( $desktop_id, $mobile_id, 0 === $index, 'kuka-story__media-item' );
				endforeach; ?>
			</div>
			<div class="kuka-story__panel kuka-brand-story__source">
				<?php foreach ( $story_scenes as $index => $scene ) :
					$text       = (string) $story_value( $scene, 'text' );
					$tone       = (string) $story_value( $scene, 'text_tone' );
					$desktop_id = absint( $story_value( $scene, 'desktop_image_id' ) );
					$mobile_id  = absint( $story_value( $scene, 'mobile_image_id' ) );
					$text_length = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
					$length_class = $text_length > 80 ? ' kuka-story__scene--long' : '';
					?>
					<section class="kuka-story__scene kuka-story__scene--<?php echo esc_attr( in_array( $tone, array( 'light', 'dark' ), true ) ? $tone : 'light' ); ?><?php echo esc_attr( $length_class ); ?>" data-story-scene="<?php echo esc_attr( (string) $index ); ?>">
						<?php $story_picture( $desktop_id, $mobile_id, false, 'kuka-story__article-image' ); ?>
						<noscript><?php $story_picture( $desktop_id, $mobile_id, 0 === $index, 'kuka-story__noscript-image' ); ?></noscript>
						<div class="kuka-story__copy"><?php $story_text( $text, ! empty( $scene['reveal_lines'] ) ); ?></div>
					</section>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="kuka-story__steps" aria-hidden="true">
			<?php foreach ( $story_scenes as $index => $_scene ) : ?><div class="kuka-story__step" data-story-step="<?php echo esc_attr( (string) $index ); ?>"></div><?php endforeach; ?>
		</div>
	</main>
<?php endwhile;
get_footer();
