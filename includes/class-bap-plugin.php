<?php
defined( 'ABSPATH' ) || exit;

final class BAP_Plugin {
	private static array $seen = array();
	private static int $count = 0;
	private static ?array $banners = null;

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'the_content', array( __CLASS__, 'content' ), 20 );
		add_action( 'wp_footer', array( __CLASS__, 'sticky' ) );
		add_shortcode( 'banner_studio', array( __CLASS__, 'shortcode' ) );
	}

	public static function register(): void {
		register_post_type( 'bap_banner', array(
			'label' => 'Banners', 'public' => false, 'show_ui' => false,
			'supports' => array( 'title' ), 'can_export' => true,
		) );
		register_block_type( 'banner-studio/banner', array(
			'api_version' => 3,
			'attributes' => array( 'bannerId' => array( 'type' => 'integer', 'default' => 0 ) ),
			'render_callback' => static fn( $a ) => self::shortcode( array( 'id' => $a['bannerId'] ?? 0 ) ),
			'editor_script' => 'bap-block',
		) );
		wp_register_script( 'bap-block', BAP_URL . 'assets/block.js', array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor' ), BAP_VERSION, true );
		if ( is_admin() && current_user_can( 'edit_posts' ) ) {
			$options = array();
			foreach ( self::banners() as $post ) {
				$options[] = array( 'label' => $post->post_title, 'value' => $post->ID );
			}
			wp_localize_script( 'bap-block', 'bapBlock', array( 'banners' => $options ) );
		}
	}

	public static function defaults(): array {
		return array( 'max' => 3, 'gap' => 2, 'min_words' => 150, 'hide_admin' => 0, 'delete_data' => 0, 'sticky_offset' => 0, 'dismiss_hours' => 24 );
	}

	public static function settings(): array {
		return wp_parse_args( get_option( 'bap_settings', array() ), self::defaults() );
	}

	public static function banner_defaults(): array {
		return array(
			'desktop_image' => 0, 'mobile_image' => 0, 'url' => '', 'alt' => '', 'label' => 'Advertisement',
			'desktop' => 1, 'mobile' => 1, 'new_tab' => 0, 'sponsored' => 1, 'enabled' => 1,
			'placement' => 'after_paragraph', 'position' => 3, 'priority' => 10,
			'width' => 970, 'mobile_width' => 320, 'stretch' => 1, 'align' => 'center', 'margin' => 24, 'padding' => 0,
			'start' => '', 'end' => '', 'post_types' => array( 'post' ), 'categories' => array(), 'tags' => array(), 'exclude' => array(),
		);
	}

	public static function data( int $id ): array {
		return wp_parse_args( (array) get_post_meta( $id, '_bap_data', true ), self::banner_defaults() );
	}

	public static function banners(): array {
		if ( null === self::$banners ) {
			self::$banners = get_posts( array( 'post_type' => 'bap_banner', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) );
			usort( self::$banners, static function ( $a, $b ) {
				return ( (int) self::data( $a->ID )['priority'] <=> (int) self::data( $b->ID )['priority'] ) ?: ( $a->ID <=> $b->ID );
			} );
		}
		return self::$banners;
	}

	public static function reset_cache(): void {
		self::$banners = null;
		self::$seen = array();
		self::$count = 0;
	}

	public static function allowed(): bool {
		return ! is_admin() && ! is_feed() && ! is_preview() && ! is_search() && ! is_404()
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			&& ! ( self::settings()['hide_admin'] && current_user_can( 'manage_options' ) )
			&& ! post_password_required();
	}

	public static function eligible( int $id, ?array $data = null ): bool {
		$d = $data ?? self::data( $id );
		if ( 'bap_banner' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) || ! $d['enabled'] || ( ! $d['desktop'] && ! $d['mobile'] ) ) {
			return false;
		}
		$now = time();
		if ( ( $d['start'] && $now < (int) $d['start'] ) || ( $d['end'] && $now >= (int) $d['end'] ) ) {
			return false;
		}
		$post_id = get_queried_object_id();
		if ( in_array( $post_id, $d['exclude'], true ) || ! in_array( get_post_type( $post_id ), $d['post_types'], true ) ) {
			return false;
		}
		if ( $d['categories'] && ! has_category( $d['categories'], $post_id ) ) {
			return false;
		}
		if ( $d['tags'] && ! has_tag( $d['tags'], $post_id ) ) {
			return false;
		}
		return true;
	}

	public static function image( int $id ): ?array {
		if ( ! in_array( get_post_mime_type( $id ), array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' ), true ) ) {
			return null;
		}
		$image = wp_get_attachment_image_src( $id, 'full' );
		return $image ? array( 'url' => $image[0], 'width' => (int) $image[1], 'height' => (int) $image[2] ) : null;
	}

	public static function assets(): void {
		if ( ! is_singular() || ! self::allowed() || ! self::banners() ) {
			return;
		}
		wp_enqueue_style( 'bap-front', BAP_URL . 'assets/frontend.css', array(), BAP_VERSION );
		wp_enqueue_script( 'bap-front', BAP_URL . 'assets/frontend.js', array(), BAP_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}

	public static function render( int $id, bool $sticky = false ): string {
		$d = self::data( $id );
		if ( isset( self::$seen[ $id ] ) || self::$count >= (int) self::settings()['max'] || ! self::eligible( $id, $d ) ) {
			return '';
		}
		$desktop = self::image( (int) $d['desktop_image'] );
		$mobile = self::image( (int) $d['mobile_image'] ) ?: $desktop;
		$desktop = $desktop ?: $mobile;
		if ( ! $desktop || ! $mobile || ( $sticky && ! $d['mobile'] ) ) {
			return '';
		}
		self::$seen[ $id ] = true;
		++self::$count;
		$settings = self::settings();
		$classes = 'bap-banner bap-align-' . $d['align'] . ( $sticky ? ' bap-sticky' : '' ) . ( ! $d['desktop'] ? ' bap-no-desktop' : '' ) . ( ! $d['mobile'] ? ' bap-no-mobile' : '' );
		$style = sprintf( '--bap-width:%dpx;--bap-mobile-width:%dpx;--bap-margin:%dpx;--bap-padding:%dpx;--bap-ratio:%d/%d;--bap-mobile-ratio:%d/%d;--bap-offset:%dpx;', $d['width'], $d['mobile_width'], $d['margin'], $d['padding'], $desktop['width'], $desktop['height'], $mobile['width'], $mobile['height'], $settings['sticky_offset'] );
		$alt = $d['alt'] ?: get_post_meta( (int) $d['desktop_image'], '_wp_attachment_image_alt', true );
		$blank = 'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=';
		ob_start();
		?>
		<aside class="<?php echo esc_attr( $classes ); ?>" style="<?php echo esc_attr( $style ); ?>" aria-label="<?php echo esc_attr( $d['label'] ?: 'Advertisement' ); ?>" data-bap-id="<?php echo (int) $id; ?>" data-bap-end="<?php echo esc_attr( $d['end'] ); ?>" data-bap-dismiss="<?php echo (int) $settings['dismiss_hours']; ?>" <?php echo $sticky ? 'hidden' : ''; ?>>
			<?php if ( $sticky ) : ?><button type="button" class="bap-close" aria-label="Dismiss advertisement">&times;</button><?php endif; ?>
			<div class="bap-inner <?php echo $d['stretch'] ? 'bap-stretch' : ''; ?>">
				<?php if ( $d['label'] ) : ?><span class="bap-label"><?php echo esc_html( $d['label'] ); ?></span><?php endif; ?>
				<?php if ( $d['url'] ) : ?><a href="<?php echo esc_url( $d['url'], array( 'http', 'https' ) ); ?>" <?php echo $d['new_tab'] ? 'target="_blank"' : ''; ?> rel="<?php echo esc_attr( trim( ( $d['sponsored'] ? 'sponsored ' : '' ) . ( $d['new_tab'] ? 'noopener' : '' ) ) ); ?>"><?php endif; ?>
				<picture>
					<source media="(max-width: 767px)" srcset="<?php echo $d['mobile'] ? esc_url( $mobile['url'] ) : esc_attr( $blank ); ?>" width="<?php echo (int) $mobile['width']; ?>" height="<?php echo (int) $mobile['height']; ?>">
					<source media="(min-width: 768px)" srcset="<?php echo ( $d['desktop'] && ! $sticky ) ? esc_url( $desktop['url'] ) : esc_attr( $blank ); ?>" width="<?php echo (int) $desktop['width']; ?>" height="<?php echo (int) $desktop['height']; ?>">
					<img src="<?php echo esc_url( $desktop['url'] ); ?>" width="<?php echo (int) $desktop['width']; ?>" height="<?php echo (int) $desktop['height']; ?>" alt="<?php echo esc_attr( $alt ); ?>" loading="lazy" decoding="async">
				</picture>
				<?php if ( $d['url'] ) : ?></a><?php endif; ?>
			</div>
		</aside>
		<?php
		return (string) ob_get_clean();
	}

	public static function shortcode( $attributes ): string {
		if ( ! self::allowed() || ! is_singular() ) {
			return '';
		}
		$a = shortcode_atts( array( 'id' => 0 ), $attributes, 'banner_studio' );
		return self::render( absint( $a['id'] ) );
	}

	public static function content( string $content ): string {
		if ( ! self::allowed() || ! is_singular() || ! in_the_loop() || ! is_main_query() || false !== strpos( $content, 'data-bap-processed' ) ) {
			return $content;
		}
		$words = preg_split( '/\s+/u', trim( wp_strip_all_tags( $content ) ), -1, PREG_SPLIT_NO_EMPTY );
		if ( count( $words ?: array() ) < (int) self::settings()['min_words'] ) {
			return $content;
		}
		$before = ''; $after = ''; $inserts = array();
		foreach ( self::banners() as $banner ) {
			$d = self::data( $banner->ID );
			if ( ! self::eligible( $banner->ID, $d ) ) {
				continue;
			}
			if ( 'before' === $d['placement'] ) { $before .= self::render( $banner->ID ); }
			if ( 'after' === $d['placement'] ) { $after .= self::render( $banner->ID ); }
			if ( in_array( $d['placement'], array( 'after_paragraph', 'after_heading' ), true ) ) { $inserts[] = $banner; }
		}
		if ( $inserts && class_exists( 'DOMDocument' ) ) {
			$dom = new DOMDocument();
			$previous = libxml_use_internal_errors( true );
			$dom->loadHTML( '<?xml encoding="utf-8" ?><div id="bap-root">' . $content . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
			$root = $dom->getElementById( 'bap-root' );
			if ( $root ) {
				$nodes = iterator_to_array( $root->childNodes );
				$p = 0; $h = 0; $last = -PHP_INT_MAX; $index = 0;
				foreach ( $nodes as $node ) {
					if ( ! ( $node instanceof DOMElement ) ) { continue; }
					++$index;
					$is_p = 'p' === $node->tagName && ! $node->getElementsByTagName( 'img' )->length && ! $node->getElementsByTagName( 'iframe' )->length;
					$is_h = (bool) preg_match( '/^h[2-6]$/', $node->tagName );
					if ( $is_p ) { ++$p; }
					if ( $is_h ) { ++$h; }
					foreach ( $inserts as $banner ) {
						$d = self::data( $banner->ID );
						$matches = ( $is_p && 'after_paragraph' === $d['placement'] && $p === (int) $d['position'] ) || ( $is_h && 'after_heading' === $d['placement'] && $h === (int) $d['position'] );
						if ( ! $matches || $index - $last <= (int) self::settings()['gap'] ) { continue; }
						$html = self::render( $banner->ID );
						if ( ! $html ) { continue; }
						$fragment = new DOMDocument();
						$previous = libxml_use_internal_errors( true );
						$fragment->loadHTML( '<?xml encoding="utf-8" ?><div id="bap-fragment">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
						libxml_clear_errors(); libxml_use_internal_errors( $previous );
						foreach ( iterator_to_array( $fragment->getElementById( 'bap-fragment' )->childNodes ) as $child ) {
							$root->insertBefore( $dom->importNode( $child, true ), $node->nextSibling );
						}
						$last = $index;
					}
				}
				$content = '';
				foreach ( $root->childNodes as $child ) { $content .= $dom->saveHTML( $child ); }
			}
		}
		return '<!-- data-bap-processed -->' . $before . $content . $after;
	}

	public static function sticky(): void {
		if ( ! self::allowed() || ! is_singular() ) { return; }
		foreach ( self::banners() as $banner ) {
			if ( 'sticky' === self::data( $banner->ID )['placement'] ) {
				$html = self::render( $banner->ID, true );
				if ( $html ) { echo $html; break; } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by render().
			}
		}
	}
}
