<?php
/**
 * Safe picture sources around WordPress-generated attachment images.
 *
 * @package Jocky\ImageOptimizer\Delivery
 */

declare(strict_types=1);

namespace Jocky\ImageOptimizer\Delivery;

use Jocky\ImageOptimizer\Plugin;
use Jocky\ImageOptimizer\Settings;

/** Applies browser-native format negotiation while preserving the original img fallback. */
final class FrontendDelivery {

	/** Read-only registry lookup, memoized within this request.
	 *
	 * @var DerivativeResolver
	 */
	private DerivativeResolver $resolver;
	/** Settings snapshot used consistently for this request.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $request_settings = null;

	/** Initializes the request-local resolver.
	 */
	public function __construct() {
		$this->resolver = new DerivativeResolver();
	}

	/** Registers narrow WordPress image-rendering filters.
	 */
	public function register(): void {
		add_filter( 'wp_get_attachment_image', array( $this, 'attachment_image' ), 20, 5 );
		add_filter( 'wp_content_img_tag', array( $this, 'content_image' ), 20, 3 );
		// Elementor sanitizes image helper output inside some widgets, stripping
		// <picture>/<source> tags. This public hook runs after widget rendering.
		add_filter( 'elementor/widget/render_content', array( $this, 'elementor_widget_content' ), 20, 2 );
	}

	/** Adds delivery sources after Elementor has sanitized supported widget output.
	 *
	 * Elementor Image and Image Box widgets may sanitize wp_get_attachment_image()
	 * output with wp_kses_post(), which removes picture/source wrappers. This
	 * adapter runs on Elementor's documented final widget-content filter and
	 * reuses the same identity-checked renderer as WordPress image markup.
	 *
	 * @param string $content Widget HTML after its render method.
	 * @param mixed  $widget  Elementor widget instance.
	 * @return string Filtered widget HTML.
	 */
	public function elementor_widget_content( string $content, $widget ): string {
		if ( is_admin() || ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || ! method_exists( $widget, 'get_settings_for_display' ) ) {
			return $content;
		}
		$widget_name = $widget->get_name();
		if ( ! in_array( $widget_name, array( 'image', 'image-box' ), true ) || str_contains( $content, '<picture' ) ) {
			return $content;
		}
		$settings = $widget->get_settings_for_display();
		if ( ! is_array( $settings ) || ! is_array( $settings['image'] ?? null ) ) {
			return $content;
		}
		$attachment_id = (int) ( $settings['image']['id'] ?? 0 );
		if ( $attachment_id < 1 || 'attachment' !== get_post_type( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) ) {
			return $content;
		}
		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			return $content;
		}
		$size_control = 'image' === $widget_name ? 'image_size' : 'thumbnail_size';
		$size         = $settings[ $size_control ] ?? 'full';
		if ( 'custom' === $size ) {
			$custom = $settings[ $size_control . '_custom_dimension' ] ?? array();
			if ( ! is_array( $custom ) || empty( $custom['width'] ) || empty( $custom['height'] ) ) {
				return $content;
			}
			$size = array( (int) $custom['width'], (int) $custom['height'] );
		}
		if ( ! is_string( $size ) && ! is_array( $size ) ) {
			return $content;
		}
		if ( ! class_exists( '\\WP_HTML_Tag_Processor' ) ) {
			return $content;
		}
		if ( 1 !== preg_match( '/<img\b[^>]*>/i', $content, $image_match, PREG_OFFSET_CAPTURE ) ) {
			return $content;
		}
		$image_html = $image_match[0][0];
		$offset     = $image_match[0][1];
		if ( ! $this->elementor_markup_matches_attachment( $image_html, $attachment_id, $size ) ) {
			return $content;
		}
		$image_tag = new \WP_HTML_Tag_Processor( $image_html );
		if ( $image_tag->next_tag( 'IMG' ) && $image_tag->get_attribute( 'data-jio-delivery' ) ) {
			$image_tag->remove_attribute( 'data-jio-delivery' );
			$clean_image = $image_tag->get_updated_html();
			if ( is_string( $clean_image ) && '' !== $clean_image ) {
				$image_html = $clean_image;
			}
		}
		$picture = $this->deliver( $image_html, $attachment_id, $size, false );
		if ( $picture === $image_html || ! str_contains( $picture, '<picture>' ) ) {
			return $content;
		}
		return substr_replace( $content, $picture, $offset, strlen( $image_match[0][0] ) );
	}

	/** Ensures Elementor's image source belongs to its claimed attachment.
	 *
	 * @param string       $content       Widget HTML.
	 * @param int          $attachment_id Selected attachment ID.
	 * @param string|int[] $size          Elementor's selected image size.
	 * @return bool Whether the first image uses the expected WordPress source.
	 */
	private function elementor_markup_matches_attachment( string $content, int $attachment_id, $size ): bool {
		$tag = new \WP_HTML_Tag_Processor( $content );
		if ( ! $tag->next_tag( 'IMG' ) ) {
			return false;
		}
		$source   = $tag->get_attribute( 'src' );
		$expected = wp_get_attachment_image_src( $attachment_id, $size );
		return is_string( $source ) && is_array( $expected ) && isset( $expected[0] ) && hash_equals( (string) $expected[0], $source );
	}

	/** Filters markup created by the WordPress attachment image API.
	 *
	 * @param string       $html          Generated img markup.
	 * @param int          $attachment_id Attachment ID.
	 * @param string|int[] $size          Requested registered size or dimensions.
	 * @param bool         $icon          Whether a mime icon is requested.
	 * @param array        $attr          WordPress image attributes.
	 * @return string Filtered markup.
	 */
	public function attachment_image( string $html, int $attachment_id, $size, bool $icon, array $attr ): string {
		return $this->deliver( $html, $attachment_id, $size, $icon, isset( $attr['data-jio-delivery'] ) );
	}

	/** Filters a single image core recognized in post content; unknown IDs remain unchanged.
	 *
	 * @param string $html          Parsed image markup.
	 * @param string $context       Core render context.
	 * @param int    $attachment_id Matched attachment ID.
	 * @return string Filtered markup.
	 */
	public function content_image( string $html, string $context, int $attachment_id ): string {
		if ( '' === $context || ! class_exists( '\\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}
		$tag = new \WP_HTML_Tag_Processor( $html );
		if ( ! $tag->next_tag( 'IMG' ) ) {
			return $html;
		}
		$width  = (int) $tag->get_attribute( 'width' );
		$height = (int) $tag->get_attribute( 'height' );
		if ( $width < 1 || $height < 1 ) {
			return $html;
		}
		return $this->deliver( $html, $attachment_id, array( $width, $height ), false );
	}

	/** Creates picture sources only when a verified derivative candidate exists.
	 *
	 * @param string       $html          Original WordPress markup.
	 * @param int          $attachment_id Attachment ID.
	 * @param string|int[] $size          Requested image size.
	 * @param bool         $icon          Whether the request is for an icon.
	 * @param bool         $already_marked Whether markup has the JIO delivery marker.
	 * @return string Original or safely wrapped markup.
	 */
	private function deliver( string $html, int $attachment_id, $size, bool $icon, bool $already_marked = false ): string {
		try {
			if ( $icon || $already_marked || $attachment_id < 1 || is_admin() || ! class_exists( '\\WP_HTML_Tag_Processor' ) ) {
				return $html;
			}
			$this->request_settings ??= Settings::sanitize( get_option( Plugin::OPTION_NAME, array() ) );
			if ( ! $this->request_settings['frontend_delivery'] ) {
				return $html;
			}
			$tag = new \WP_HTML_Tag_Processor( $html );
			if ( ! $tag->next_tag( 'IMG' ) || $tag->get_attribute( 'data-jio-delivery' ) ) {
				return $html;
			}
			$records = $this->resolver->records( $attachment_id );
			if ( array() === $records ) {
				return $html;
			}
			$metadata   = wp_get_attachment_metadata( $attachment_id );
			$dimensions = $this->requested_dimensions( $attachment_id, $size, $metadata );
			if ( null === $dimensions ) {
				return $html;
			}
			$tag_width  = (int) $tag->get_attribute( 'width' );
			$tag_height = (int) $tag->get_attribute( 'height' );
			if ( $tag_width > 0 && $tag_height > 0 && ! $this->same_ratio( $tag_width, $tag_height, $dimensions[0], $dimensions[1] ) ) {
				return $html;
			}
			$source_sets = array();
			foreach ( array( 'image/avif', 'image/webp' ) as $mime ) {
				$candidates = $this->responsive_candidates( $records, $mime, $dimensions[0], $dimensions[1], $dimensions[2], $metadata );
				if ( array() !== $candidates ) {
					$source_sets[ $mime ] = $candidates;
				}
			}
			$source_sets = $this->preference_order( $source_sets, $this->request_settings['delivery_preference'] );
			if ( array() === $source_sets ) {
				return $html;
			}
			$tag->set_attribute( 'data-jio-delivery', '1' );
			$img = $tag->get_updated_html();
			if ( ! is_string( $img ) || '' === $img ) {
				return $html;
			}
			$sources = '';
			$sizes   = $tag->get_attribute( 'sizes' );
			foreach ( $source_sets as $mime => $srcset ) {
				$sources .= '<source type="' . esc_attr( $mime ) . '" srcset="' . esc_attr( $srcset ) . '"' . ( is_string( $sizes ) && '' !== $sizes ? ' sizes="' . esc_attr( $sizes ) . '"' : '' ) . '>';
			}
			return '<picture>' . $sources . $img . '</picture>';
		} catch ( \Throwable ) {
			return $html;
		}
	}

	/** Resolves request dimensions to a current metadata variant.
	 *
	 * @param int          $attachment_id Attachment ID.
	 * @param string|int[] $size          Requested image size.
	 * @param mixed        $metadata      Current core metadata.
	 * @return array{int,int,string}|null Width, height, and required variant key.
	 */
	private function requested_dimensions( int $attachment_id, $size, $metadata ): ?array {
		if ( ! is_array( $metadata ) ) {
			return null;
		}
		if ( 'full' === $size || 'large' === $size || 'medium' === $size || 'thumbnail' === $size ) {
			if ( 'full' === $size ) {
				return isset( $metadata['width'], $metadata['height'] ) ? array( (int) $metadata['width'], (int) $metadata['height'], 'full' ) : null;
			}
			$registered = wp_get_registered_image_subsizes();
			if ( isset( $registered[ $size ]['width'], $registered[ $size ]['height'] ) ) {
				$target = $registered[ $size ];
				$sizes  = is_array( $metadata['sizes'] ?? null ) ? $metadata['sizes'] : array();
				foreach ( $sizes as $name => $data ) {
					if ( is_array( $data ) && isset( $data['width'], $data['height'] ) && $this->same_ratio( (int) $target['width'], (int) $target['height'], (int) $data['width'], (int) $data['height'] ) && (int) $data['width'] <= (int) $target['width'] ) {
						return array( (int) $data['width'], (int) $data['height'], 'size:' . sanitize_key( (string) $name ) );
					}
				}
				return array( (int) $metadata['width'], (int) $metadata['height'], 'full' );
			}
		}
		if ( is_string( $size ) && isset( $metadata['sizes'][ $size ]['width'], $metadata['sizes'][ $size ]['height'] ) ) {
			$data = $metadata['sizes'][ $size ];
			return array( (int) $data['width'], (int) $data['height'], 'size:' . sanitize_key( $size ) );
		}
		if ( is_array( $size ) && isset( $size[0], $size[1] ) ) {
			return array( max( 1, (int) $size[0] ), max( 1, (int) $size[1] ), '' );
		}
		return null;
	}

	/** Builds same-aspect derivative srcset candidates and requires requested variant coverage.
	 *
	 * @param array  $records         Safe records returned by DerivativeResolver.
	 * @param string $mime            Desired format MIME type.
	 * @param int    $width           Requested width.
	 * @param int    $height          Requested height.
	 * @param string $required_variant Required variant key.
	 * @param mixed  $metadata        Current WordPress metadata.
	 * @return array<int, string> Escaped when inserted into HTML.
	 */
	private function responsive_candidates( array $records, string $mime, int $width, int $height, string $required_variant, $metadata ): array {
		$set            = array();
		$required_found = '' === $required_variant;
		foreach ( $records as $record ) {
			if ( $mime !== $record['format'] ) {
				continue;
			}
			$record_dimensions = $this->variant_dimensions( (string) $record['variant'], $metadata );
			if ( null === $record_dimensions || ! $this->same_ratio( $width, $height, $record_dimensions[0], $record_dimensions[1] ) ) {
				continue;
			}
			if ( (string) $record['variant'] === $required_variant ) {
				$required_found = true;
			}
			$url = $this->derivative_url( (string) $record['relative'] );
			if ( null !== $url ) {
				$set[ $record_dimensions[0] ] = $url . ' ' . $record_dimensions[0] . 'w';
			}
		}
		ksort( $set, SORT_NUMERIC );
		return $required_found ? array_values( $set ) : array();
	}

	/** Resolves intrinsic dimensions for a core-managed variant.
	 *
	 * @param string $variant Variant key.
	 * @param mixed  $metadata Current attachment metadata.
	 * @return array{int,int}|null Intrinsic dimensions.
	 */
	private function variant_dimensions( string $variant, $metadata ): ?array {
		if ( 'full' === $variant && is_array( $metadata ) && isset( $metadata['width'], $metadata['height'] ) ) {
			return array( (int) $metadata['width'], (int) $metadata['height'] );
		}
		if ( str_starts_with( $variant, 'size:' ) && is_array( $metadata ) ) {
			$name = substr( $variant, 5 );
			$data = $metadata['sizes'][ $name ] ?? null;
			if ( is_array( $data ) && isset( $data['width'], $data['height'] ) ) {
				return array( (int) $data['width'], (int) $data['height'] );
			}
		}
		return null;
	}

	/** Determines whether dimensions describe effectively the same aspect ratio.
	 *
	 * @param int $left_width  First width.
	 * @param int $left_height First height.
	 * @param int $right_width Second width.
	 * @param int $right_height Second height.
	 * @return bool Ratio compatibility.
	 */
	private function same_ratio( int $left_width, int $left_height, int $right_width, int $right_height ): bool {
		return $left_width > 0 && $left_height > 0 && $right_width > 0 && $right_height > 0 && abs( ( $left_width * $right_height ) - ( $right_width * $left_height ) ) <= max( $left_width, $right_width );
	}

	/** Builds a public upload URL from a confined relative path.
	 *
	 * @param string $relative Confined relative path from trusted registry target.
	 * @return string|null Escaped URL.
	 */
	private function derivative_url( string $relative ): ?string {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) || empty( $uploads['baseurl'] ) || str_contains( $relative, '..' ) || str_contains( $relative, '\\' ) ) {
			return null;
		}
		$url = esc_url_raw( trailingslashit( $uploads['baseurl'] ) . implode( '/', array_map( 'rawurlencode', explode( '/', $relative ) ) ) );
		return '' !== $url ? $url : null;
	}

	/** Orders available image sources using the administrator's allow-listed preference.
	 *
	 * @param array<string, array<int, string>> $sources Available source candidates.
	 * @param string                            $preference Sanitized preference.
	 * @return array<string, string> MIME-to-srcset map.
	 */
	private function preference_order( array $sources, string $preference ): array {
		$order = match ( $preference ) {
			'prefer_webp' => array( 'image/webp', 'image/avif' ),
			'webp_only' => array( 'image/webp' ),
			'avif_only' => array( 'image/avif' ),
			default => array( 'image/avif', 'image/webp' ),
		};
		$ordered = array();
		foreach ( $order as $mime ) {
			if ( isset( $sources[ $mime ] ) ) {
				$ordered[ $mime ] = implode( ', ', $sources[ $mime ] );
			}
		}
		return $ordered;
	}
}
