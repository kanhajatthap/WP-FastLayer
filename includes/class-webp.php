<?php
namespace WP_FastLayer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Webp {
    private static $instance = null;
    private $bulk_supported_mime_types = array( 'image/jpeg', 'image/jpg', 'image/png' );
    private $frontend_debug_enabled = null;
    private $frontend_debug_run = null;
    private $ensured_subsizes = array();

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_filter( 'wp_generate_attachment_metadata', array( $this, 'generate_webp_images' ), 10, 2 );
        add_filter( 'wp_update_attachment_metadata', array( $this, 'generate_webp_images' ), 10, 2 );
        add_action( 'add_attachment', array( $this, 'generate_webp_on_upload' ) );
        add_action( 'init', array( $this, 'register_responsive_image_sizes' ) );
        add_filter( 'intermediate_image_sizes_advanced', array( $this, 'enforce_smart_thumbnail_crop' ), 20, 2 );
        add_filter( 'wp_get_attachment_image', array( $this, 'wrap_attachment_image_with_picture' ), 10, 5 );
        add_filter( 'wp_get_attachment_image_attributes', array( $this, 'add_webp_srcset_to_image_attributes' ), 10, 3 );
        add_filter( 'wp_get_attachment_image_srcset', array( $this, 'filter_attachment_image_srcset' ), 10, 5 );
        add_filter( 'wp_get_attachment_image_sizes', array( $this, 'filter_attachment_image_sizes' ), 10, 5 );
        add_filter( 'wp_calculate_image_srcset', array( $this, 'filter_calculated_image_srcset' ), 10, 5 );
        add_filter( 'wp_calculate_image_sizes', array( $this, 'filter_calculated_image_sizes' ), 10, 5 );
        add_filter( 'image_downsize', array( $this, 'filter_image_downsize' ), 10, 3 );
        add_filter( 'the_content', array( $this, 'replace_content_images_with_webp' ), 999 );
        add_filter( 'the_content', array( $this, 'cleanup_nested_picture_structures' ), 1000 );
        add_action( 'init', array( $this, 'maybe_update_webp_rewrite_rules' ), 20 );
        add_action( 'init', array( $this, 'maybe_serve_webp_representation' ), 1 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_progressive_image_loading_assets' ), 0 );

        if ( is_admin() ) {
            add_action( 'admin_notices', array( $this, 'print_upload_conversion_notice' ) );
            add_action( 'admin_notices', array( $this, 'print_nginx_webp_notice' ) );
            add_action( 'wp_ajax_wp_fastlayer_dismiss_nginx_webp_notice', array( $this, 'ajax_dismiss_nginx_webp_notice' ) );
        }
    }

    public function register_responsive_image_sizes() {
        add_image_size( 'wp_fastlayer_logo_150x50', 150, 50, true );
        add_image_size( 'wp_fastlayer_logo_200x80', 200, 80, true );
        add_image_size( 'wp_fastlayer_logo_300x120', 300, 120, true );
    }

    public function enforce_smart_thumbnail_crop( $sizes ) {
        if ( empty( $sizes ) || ! is_array( $sizes ) ) {
            return $sizes;
        }

        foreach ( $sizes as $size_name => &$size_data ) {
            if ( empty( $size_data['crop'] ) ) {
                continue;
            }

            $width  = isset( $size_data['width'] ) ? (int) $size_data['width'] : 0;
            $height = isset( $size_data['height'] ) ? (int) $size_data['height'] : 0;

            $is_target_thumbnail = ( 'thumbnail' === $size_name ) || ( 150 === $width && 110 === $height );
            if ( ! $is_target_thumbnail ) {
                continue;
            }

            // Keep hard-crop enabled but force a centered crop box for balanced thumbnails.
            $size_data['crop'] = array( 'center', 'center' );
        }

        unset( $size_data );

        return $sizes;
    }

    private function is_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_webp'] ) && '1' === $options['enable_webp'];
    }

    private function is_bulk_webp_generation_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return ! isset( $options['enable_bulk_webp_generation'] ) || '1' === (string) $options['enable_bulk_webp_generation'];
    }

    private function is_imagekit_cdn_active() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_imagekit_cdn'] )
            && '1' === (string) $options['enable_imagekit_cdn']
            && ! empty( $options['imagekit_endpoint'] );
    }

    private function is_image_delivery_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_image_delivery'] ) && '1' === $options['enable_image_delivery'];
    }

    private function should_bypass_local_webp() {
        if ( defined( 'LOCAL_WEBP_ENABLED' ) && ! LOCAL_WEBP_ENABLED ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                \wp_fastlayer_debug_log( '[WP FastLayer WebP] local_webp_bypass_active | reason=LOCAL_WEBP_ENABLED_false' );
            }
            return true;
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $this->is_imagekit_cdn_active() ) {
            \wp_fastlayer_debug_log( '[WP FastLayer WebP] imagekit_compatibility_mode_active | local_webp_enabled=yes' );
        }

        return false;
    }

    private function should_optimize_image_delivery() {
        return $this->is_enabled() && $this->is_image_delivery_enabled() && $this->is_webp_supported() && ! $this->should_bypass_local_webp();
    }

    private function is_frontend_debug_enabled() {
        if ( null !== $this->frontend_debug_enabled ) {
            return $this->frontend_debug_enabled;
        }

        if ( defined( 'WP_FASTLAYER_WEBP_DEBUG' ) ) {
            $this->frontend_debug_enabled = (bool) WP_FASTLAYER_WEBP_DEBUG;
            return $this->frontend_debug_enabled;
        }

        $options = get_option( 'wp_fastlayer_options', array() );
        $this->frontend_debug_enabled = isset( $options['debug_webp_frontend'] ) && '1' === (string) $options['debug_webp_frontend'];

        return $this->frontend_debug_enabled;
    }

    private function get_frontend_debug_page_context() {
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

        if ( empty( $request_uri ) ) {
            return home_url( '/' );
        }

        return esc_url_raw( home_url( $request_uri ) );
    }

    private function debug_log_frontend( $message, $context = array() ) {
        if ( ! $this->is_frontend_debug_enabled() ) {
            return;
        }

        $payload = array_merge(
            array(
                'page' => $this->get_frontend_debug_page_context(),
            ),
            is_array( $context ) ? $context : array()
        );

        $line = '[WP FastLayer WebP Debug] ' . $message;
        if ( ! empty( $payload ) ) {
            $line .= ' | ' . wp_json_encode( $payload );
        }

        \wp_fastlayer_debug_log( $line );
    }

    private function debug_log_lqip( $message, $context = array() ) {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }

        $payload = is_array( $context ) ? $context : array();
        $line = '[WP FastLayer LQIP] ' . $message;
        if ( ! empty( $payload ) ) {
            $line .= ' | ' . wp_json_encode( $payload );
        }

        \wp_fastlayer_debug_log( $line );
    }

    private function start_frontend_debug_run( $content ) {
        if ( ! $this->is_frontend_debug_enabled() ) {
            return;
        }

        $detected_total = preg_match_all( '/<img\b/i', (string) $content, $all_matches );
        $lazy_detected  = preg_match_all( '/<img\b[^>]*\b(?:data-src|data-lazy-src)=/i', (string) $content, $lazy_matches );

        $this->frontend_debug_run = array(
            'images_detected'              => (int) $detected_total,
            'converted_to_picture'         => 0,
            'skipped_total'                => 0,
            'skipped_already_optimized'    => 0,
            'skipped_missing_webp'         => 0,
            'skipped_lazyload'             => 0,
            'replacement_success'          => 0,
            'replacement_failure'          => 0,
            'elementor_detected'           => 0,
            'regex_match_standard'         => 0,
            'regex_match_lazy'             => 0,
            'lazy_detected'                => (int) $lazy_detected,
            'srcset_generated'             => 0,
            'srcset_missing'               => 0,
            'webp_exists'                  => 0,
            'webp_missing'                 => 0,
            'webp_generated_on_demand'     => 0,
            'webp_generation_failed'       => 0,
        );

        $this->debug_log_frontend(
            'Content filter start',
            array(
                'images_detected' => (int) $detected_total,
                'lazy_detected'   => (int) $lazy_detected,
                'content_length'  => strlen( (string) $content ),
            )
        );
    }

    private function bump_frontend_debug_stat( $key, $amount = 1 ) {
        if ( ! $this->is_frontend_debug_enabled() || ! is_array( $this->frontend_debug_run ) ) {
            return;
        }

        if ( ! isset( $this->frontend_debug_run[ $key ] ) ) {
            $this->frontend_debug_run[ $key ] = 0;
        }

        $this->frontend_debug_run[ $key ] += (int) $amount;
    }

    private function finish_frontend_debug_run() {
        if ( ! $this->is_frontend_debug_enabled() || ! is_array( $this->frontend_debug_run ) ) {
            return;
        }

        $summary = $this->frontend_debug_run;
        $summary['skipped_total'] = max(
            0,
            (int) $summary['images_detected'] - (int) $summary['converted_to_picture']
        );

        $this->debug_log_frontend( 'Content filter summary', $summary );
        $this->frontend_debug_run = null;
    }

    public function is_webp_supported() {
        $details = $this->get_webp_support_details();
        return isset( $details['supported'] ) && 'yes' === $details['supported'];
    }

    public function get_webp_support_details() {
        $details = array(
            'gd_installed'     => extension_loaded( 'gd' ) ? 'yes' : 'no',
            'imagewebp'        => function_exists( 'imagewebp' ) ? 'yes' : 'no',
            'gd_webp'          => 'no',
            'imagick_installed'=> class_exists( 'Imagick' ) ? 'yes' : 'no',
            'imagick_webp'     => 'no',
            'supported'        => 'no',
        );

        if ( 'yes' === $details['gd_installed'] ) {
            if ( function_exists( 'gd_info' ) ) {
                $info = gd_info();
                if ( isset( $info['WebP Support'] ) ) {
                    $details['gd_webp'] = $info['WebP Support'] ? 'yes' : 'no';
                } elseif ( defined( 'IMG_WEBP' ) && ( imagetypes() & IMG_WEBP ) ) {
                    $details['gd_webp'] = 'yes';
                } elseif ( function_exists( 'imagewebp' ) ) {
                    $details['gd_webp'] = 'yes';
                }
            } elseif ( defined( 'IMG_WEBP' ) && ( imagetypes() & IMG_WEBP ) ) {
                $details['gd_webp'] = 'yes';
            } elseif ( function_exists( 'imagewebp' ) ) {
                $details['gd_webp'] = 'yes';
            }
        }

        if ( 'yes' === $details['imagick_installed'] ) {
            try {
                $formats = \Imagick::queryFormats( 'WEBP' );
                if ( is_array( $formats ) && in_array( 'WEBP', $formats, true ) ) {
                    $details['imagick_webp'] = 'yes';
                }
            } catch ( \Exception $e ) {
                $details['imagick_webp'] = 'no';
            }
        }

        if ( 'yes' === $details['gd_webp'] || 'yes' === $details['imagick_webp'] ) {
            $details['supported'] = 'yes';
        }

        return $details;
    }

    private function is_supported_image( $path ) {
        return (bool) preg_match( '/\.(jpe?g|png)$/i', $path );
    }

    private function get_webp_path( $path ) {
        return preg_replace( '/\.(jpe?g|png)$/i', '.webp', $path );
    }

    public function filter_attachment_image_srcset( $srcset, $size, $image_src, $image_meta, $attachment_id ) {
        if ( ! $this->should_optimize_image_delivery() || empty( $attachment_id ) || ! $this->is_internal_url( $image_src ) ) {
            return $srcset;
        }

        if ( ! empty( $srcset ) ) {
            // Apply adaptive srcset generation even on existing srcsets to prune oversized candidates.
            $is_lcp = $this->is_lcp_image( array(), '' );
            $target_width = 555; // Conservative default when context is limited.
            $filtered = $this->filter_srcset_candidates( $srcset, $target_width, $is_lcp, $attachment_id );
            return $filtered;
        }

        $image_meta = is_array( $image_meta ) ? $image_meta : wp_get_attachment_metadata( $attachment_id );
        if ( empty( $image_meta ) ) {
            return $srcset;
        }

        $size_array = array( absint( $image_meta['width'] ), absint( $image_meta['height'] ) );
        $calculated = wp_calculate_image_srcset( $size_array, $image_src, $image_meta, $attachment_id );

        return ! empty( $calculated ) ? $calculated : $srcset;
    }

    public function filter_attachment_image_sizes( $sizes, $size, $image_src, $image_meta, $attachment_id ) {
        if ( ! $this->should_optimize_image_delivery() || empty( $attachment_id ) || ! $this->is_internal_url( $image_src ) ) {
            return $sizes;
        }

        if ( ! empty( $sizes ) ) {
            return $sizes;
        }

        $image_meta = is_array( $image_meta ) ? $image_meta : wp_get_attachment_metadata( $attachment_id );
        if ( empty( $image_meta ) ) {
            return $sizes;
        }

        $size_array = array( absint( $image_meta['width'] ), absint( $image_meta['height'] ) );
        $calculated = wp_calculate_image_sizes( $size_array, $image_src, $image_meta, $attachment_id );
        if ( empty( $calculated ) ) {
            return $sizes;
        }

        return $this->normalize_sizes_for_target_width( $calculated, '' );
    }

    public function filter_calculated_image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
        if ( ! $this->should_optimize_image_delivery() || empty( $attachment_id ) || ! $this->is_internal_url( $image_src ) ) {
            return $sources;
        }

        return $sources;
    }

    public function filter_calculated_image_sizes( $sizes, $size_array, $image_src, $image_meta, $attachment_id ) {
        if ( ! $this->should_optimize_image_delivery() || empty( $attachment_id ) || ! $this->is_internal_url( $image_src ) ) {
            return $sizes;
        }

        return $sizes;
    }

    public function filter_image_downsize( $image, $id, $size ) {
        if ( ! $this->should_optimize_image_delivery() || empty( $id ) || is_array( $image ) ) {
            return $image;
        }

        return $image;
    }

    private function get_attachment_srcset( $attachment_id, $size = 'full' ) {
        if ( ! $attachment_id ) {
            return '';
        }

        return wp_get_attachment_image_srcset( $attachment_id, $size ) ?: '';
    }

    private function get_attachment_sizes( $attachment_id, $size = 'full' ) {
        if ( ! $attachment_id ) {
            return '';
        }

        return wp_get_attachment_image_sizes( $attachment_id, $size ) ?: '';
    }

    private function add_srcset_and_sizes_to_image_tag( $html, $srcset, $sizes ) {
        if ( ! empty( $srcset ) ) {
            $html = $this->set_image_attribute( $html, 'srcset', $srcset );
        }

        if ( ! empty( $sizes ) ) {
            $html = $this->set_image_attribute( $html, 'sizes', $sizes );
        }

        return $html;
    }

    private function add_data_srcset_and_sizes_to_image_tag( $html, $srcset, $sizes ) {
        if ( empty( $srcset ) ) {
            return $html;
        }

        $html = $this->set_image_attribute( $html, 'data-srcset', $srcset );
        $html = $this->set_image_attribute( $html, 'data-lazy-srcset', $srcset );

        if ( empty( $sizes ) ) {
            $sizes = $this->infer_image_sizes_from_html( $html );
        }

        $html = $this->set_image_attribute( $html, 'data-sizes', $sizes );
        $html = $this->set_image_attribute( $html, 'data-lazy-sizes', $sizes );

        return $html;
    }

    private function set_image_attribute( $html, $attribute, $value ) {
        if ( '' === $value || ! is_string( $value ) ) {
            return $html;
        }

        $escaped = esc_attr( $value );
        $pattern = '/\b' . preg_quote( $attribute, '/' ) . '\s*=\s*("|\').*?\1/i';

        if ( preg_match( $pattern, $html ) ) {
            return preg_replace( $pattern, $attribute . '="' . $escaped . '"', $html, 1 );
        }

        return preg_replace( '/<img/i', '<img ' . $attribute . '="' . $escaped . '"', $html, 1 );
    }

    private function infer_image_sizes_from_html( $html ) {
        if ( $this->is_compact_image_context( $html ) ) {
            $target = max( 150, min( 300, $this->infer_target_width_from_html( $html, 200 ) ) );
            return '(max-width: 480px) min(50vw, ' . absint( $target ) . 'px), ' . absint( $target ) . 'px';
        }

        $target_width = $this->infer_target_width_from_html( $html );
        if ( $target_width > 0 ) {
            return '(max-width: ' . absint( $target_width ) . 'px) 100vw, ' . absint( $target_width ) . 'px';
        }

        return '100vw';
    }

    private function normalize_sizes_for_target_width( $sizes, $html ) {
        $target_width = $this->infer_target_width_from_html( $html );
        if ( $target_width < 1 ) {
            return $sizes;
        }

        return '(max-width: ' . absint( $target_width ) . 'px) 100vw, ' . absint( $target_width ) . 'px';
    }

    private function is_compact_image_context( $html ) {
        $context = strtolower( trim( wp_strip_all_tags( (string) $html ) ) );
        $signals = array( 'logo', 'brand', 'carousel', 'slider', 'client', 'partner' );

        foreach ( $signals as $signal ) {
            if ( false !== strpos( $context, $signal ) ) {
                return true;
            }
        }

        return false;
    }

    private function infer_target_width_from_html( $html, $default = 0 ) {
        $width = $this->extract_attribute_value( $html, 'width' );
        if ( is_numeric( $width ) ) {
            return absint( $width );
        }

        $style = $this->extract_attribute_value( $html, 'style' );
        if ( preg_match( '/(?:^|;)\s*max-width\s*:\s*(\d+)px/i', $style, $matches ) ) {
            return absint( $matches[1] );
        }

        if ( preg_match( '/(?:^|;)\s*width\s*:\s*(\d+)px/i', $style, $matches ) ) {
            return absint( $matches[1] );
        }

        if ( preg_match( '/(?:^|;)\s*width\s*:\s*(\d+)%/i', $style, $matches ) ) {
            if ( preg_match( '/(?:^|;)\s*max-width\s*:\s*(\d+)px/i', $style, $max_matches ) ) {
                return absint( $max_matches[1] );
            }
        }

        $sizes = $this->extract_attribute_value( $html, 'sizes' );
        if ( preg_match( '/(?:^|,)\s*min\(100vw,\s*(\d+)px\)/i', trim( $sizes ), $matches ) ) {
            return absint( $matches[1] );
        }

        if ( preg_match( '/(?:^|,)\s*(\d+)px\s*$/', trim( $sizes ), $matches ) ) {
            return absint( $matches[1] );
        }

        return absint( $default );
    }

    private function infer_target_width_from_attributes( $attr, $default = 0 ) {
        if ( ! is_array( $attr ) ) {
            return absint( $default );
        }

        if ( ! empty( $attr['width'] ) && is_numeric( $attr['width'] ) ) {
            return absint( $attr['width'] );
        }

        if ( ! empty( $attr['style'] ) ) {
            $style = (string) $attr['style'];
            if ( preg_match( '/(?:^|;)\s*max-width\s*:\s*(\d+)px/i', $style, $matches ) ) {
                return absint( $matches[1] );
            }

            if ( preg_match( '/(?:^|;)\s*width\s*:\s*(\d+)px/i', $style, $matches ) ) {
                return absint( $matches[1] );
            }
        }

        if ( ! empty( $attr['sizes'] ) && preg_match( '/(?:^|,)\s*min\(100vw,\s*(\d+)px\)/i', (string) $attr['sizes'], $matches ) ) {
            return absint( $matches[1] );
        }

        if ( ! empty( $attr['sizes'] ) && preg_match( '/(?:^|,)\s*(\d+)px\s*$/', (string) $attr['sizes'], $matches ) ) {
            return absint( $matches[1] );
        }

        $class = isset( $attr['class'] ) ? strtolower( (string) $attr['class'] ) : '';
        if ( '' !== $class ) {
            if ( preg_match( '/logo|brand|carousel|slider|client|partner|icon|avatar|thumb|thumbnail/', $class ) ) {
                return 200;
            }

            if ( false !== strpos( $class, 'elementor' ) ) {
                return 768;
            }
        }

        return absint( $default );
    }

    private function ensure_attachment_subsizes( $attachment_id ) {
        $attachment_id = absint( $attachment_id );
        if ( ! $attachment_id || isset( $this->ensured_subsizes[ $attachment_id ] ) || ! function_exists( 'wp_update_image_subsizes' ) ) {
            return;
        }

        $this->ensured_subsizes[ $attachment_id ] = true;
        $metadata = wp_get_attachment_metadata( $attachment_id );

        if ( empty( $metadata ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
            wp_update_image_subsizes( $attachment_id );
        }
    }

    private function find_best_size_for_width( $attachment_id, $target_width, $fallback_size = 'full' ) {
        $target_width = absint( $target_width );
        if ( $target_width < 1 ) {
            return $fallback_size;
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
            return $fallback_size;
        }

        $closest_above_name = '';
        $closest_above_width = 0;
        $closest_below_name = '';
        $closest_below_width = 0;

        foreach ( $metadata['sizes'] as $name => $size_data ) {
            if ( empty( $size_data['width'] ) ) {
                continue;
            }

            $candidate_width = absint( $size_data['width'] );
            if ( $candidate_width >= $target_width ) {
                if ( 0 === $closest_above_width || $candidate_width < $closest_above_width ) {
                    $closest_above_width = $candidate_width;
                    $closest_above_name  = $name;
                }
            } elseif ( $candidate_width > $closest_below_width ) {
                $closest_below_width = $candidate_width;
                $closest_below_name  = $name;
            }
        }

        if ( '' !== $closest_above_name ) {
            return $closest_above_name;
        }

        if ( '' !== $closest_below_name ) {
            return $closest_below_name;
        }

        return $fallback_size;
    }

    private function resolve_attachment_size_name( $attachment_id, $size, $html ) {
        $this->ensure_attachment_subsizes( $attachment_id );

        $default_width = 0;
        if ( $this->is_compact_image_context( $html ) ) {
            $default_width = 200;
        } elseif ( false !== stripos( (string) $html, 'elementor' ) ) {
            $default_width = 768;
        }

        $target_width = $this->infer_target_width_from_html( $html, $default_width );
        if ( $target_width > 0 ) {
            $resolved = $this->find_best_size_for_width( $attachment_id, $target_width, $size );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                \wp_fastlayer_debug_log( '[WP FastLayer WebP] responsive_variant_selected | attachment_id=' . absint( $attachment_id ) . ' | target_width=' . absint( $target_width ) . ' | selected_size=' . ( is_string( $resolved ) ? $resolved : wp_json_encode( $resolved ) ) );
            }
            return $resolved;
        }

        if ( $this->is_compact_image_context( $html ) ) {
            return $this->find_best_size_for_width( $attachment_id, 200, $size );
        }

        return $size;
    }

    private function calculate_attachment_srcset( $attachment_id, $size_name, $source_url ) {
        $image = wp_get_attachment_image_src( $attachment_id, $size_name );
        $metadata = wp_get_attachment_metadata( $attachment_id );

        if ( ! is_array( $image ) || empty( $metadata ) || empty( $source_url ) ) {
            return '';
        }

        $size_array = array( (int) $image[1], (int) $image[2] );
        $calculated = wp_calculate_image_srcset( $size_array, $source_url, $metadata, $attachment_id );

        return $calculated ? $calculated : '';
    }

    private function calculate_attachment_sizes( $attachment_id, $size_name, $source_url, $fallback_sizes, $html = '' ) {
        $image = wp_get_attachment_image_src( $attachment_id, $size_name );
        $metadata = wp_get_attachment_metadata( $attachment_id );

        if ( ! is_array( $image ) || empty( $metadata ) || empty( $source_url ) ) {
            return $this->normalize_sizes_for_target_width( $fallback_sizes, $html );
        }

        $size_array = array( (int) $image[1], (int) $image[2] );
        $calculated = wp_calculate_image_sizes( $size_array, $source_url, $metadata, $attachment_id );

        $sizes = ! empty( $calculated ) ? $calculated : $fallback_sizes;

        // Attempt to generate a safer, smarter `sizes` value when current
        // sizes are generic or likely to cause oversized downloads.
        $smart = $this->infer_smart_sizes( $sizes, $html, $attachment_id );
        if ( false !== $smart ) {
            $sizes = $smart;
        }

        return $this->normalize_sizes_for_target_width( $sizes, $html );
    }

    /**
     * Infer a conservative, smart `sizes` attribute based on HTML hints and
     * attachment/context. Returns `false` when no safe improvement is available
     * (caller should preserve original sizes).
     */
    private function infer_smart_sizes( $sizes, $html, $attachment_id = 0 ) {
        $original = trim( (string) $sizes );

        // If sizes is empty, or plainly generic `100vw`, or a large `min(100vw, Npx)`
        // where Npx is very large, we attempt to compute a safer sizes value.
        $needs_improve = false;
        if ( '' === $original || '100vw' === strtolower( $original ) ) {
            $needs_improve = true;
        }

        if ( preg_match( '/min\(100vw,\s*(\d+)px\)/i', $original, $m ) ) {
            $maxpx = absint( $m[1] );
            if ( $maxpx > 1200 ) {
                $needs_improve = true;
            }
        }

        if ( preg_match( '/\(max-width:\s*(\d+)px\)\s*100vw,\s*(\d+)px/i', $original, $m ) ) {
            $maxpx = absint( $m[2] );
            if ( $maxpx > 1200 ) {
                $needs_improve = true;
            }
        }

        if ( ! $needs_improve ) {
            return false;
        }

        // First, try to infer a real target width from inline HTML attributes/styles.
        $target = $this->infer_target_width_from_html( $html );
        if ( $target > 0 ) {
            $sizes = '(max-width: 768px) 100vw, ' . absint( $target ) . 'px';
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                \wp_fastlayer_debug_log( '[WP FastLayer Sizes] detected_container_width=' . absint( $target ) . ' generated_sizes=' . $sizes . ' | attachment=' . absint( $attachment_id ) );
            }
            return $sizes;
        }

        // Next, try attributes-derived hints (width, style, classes).
        $target_attr = $this->infer_target_width_from_attributes( (array) $this->extract_attributes_from_html( $html ), 0 );
        if ( $target_attr > 0 ) {
            $sizes = '(max-width: 768px) 100vw, ' . absint( $target_attr ) . 'px';
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                \wp_fastlayer_debug_log( '[WP FastLayer Sizes] detected_container_width=' . absint( $target_attr ) . ' generated_sizes=' . $sizes . ' | attachment=' . absint( $attachment_id ) );
            }
            return $sizes;
        }

        // Conservative Elementor-specific fallback: many Elementor widgets render
        // images at constrained widths inside columns — use a reasonable default
        // rather than a full-viewport `100vw` which can trigger very large
        // downloads.
        if ( false !== stripos( (string) $html, 'elementor' ) ) {
            $sizes = '(max-width: 768px) 100vw, 555px';
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                \wp_fastlayer_debug_log( '[WP FastLayer Sizes] elementor_hint used generated_sizes=' . $sizes . ' | attachment=' . absint( $attachment_id ) );
            }
            return $sizes;
        }

        // Generic conservative fallback for oversized default sizes, when we
        // cannot infer a more precise layout width safely.
        $sizes = '(max-width: 768px) 100vw, 768px';
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            \wp_fastlayer_debug_log( '[WP FastLayer Sizes] generic_fallback_generated_sizes=' . $sizes . ' | original=' . $original . ' | attachment=' . absint( $attachment_id ) );
        }

        return $sizes;
    }

    /**
     * Extracts HTML attributes from a tag string into an associative array.
     * Returns an empty array on failure. This is intentionally conservative and
     * does not attempt DOM parsing — only simple attribute extraction.
     */
    private function extract_attributes_from_html( $html ) {
        $attrs = array();
        if ( empty( $html ) || ! is_string( $html ) ) {
            return $attrs;
        }

        if ( preg_match_all( '/(\w+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $html, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $m ) {
                $key = strtolower( $m[1] );
                $val = isset( $m[2] ) && '' !== $m[2] ? $m[2] : ( isset( $m[3] ) && '' !== $m[3] ? $m[3] : ( isset( $m[4] ) ? $m[4] : '' ) );
                $attrs[ $key ] = $val;
            }
        }

        return $attrs;
    }

    /**
     * Detects if an image is likely an LCP (Largest Contentful Paint / hero) image.
     * Conservative detection: only returns true for high-confidence patterns.
     */
    private function is_lcp_image( $attr, $html ) {
        if ( ! is_array( $attr ) ) {
            return false;
        }

        $classes = isset( $attr['class'] ) ? strtolower( (string) $attr['class'] ) : '';
        $id = isset( $attr['id'] ) ? strtolower( (string) $attr['id'] ) : '';

        // High-confidence LCP patterns.
        $lcp_signals = array(
            'hero',
            'banner',
            'featured',
            'feature-image',
            'cover',
            'masthead',
            'main-hero',
            'header-image',
        );

        foreach ( $lcp_signals as $signal ) {
            if ( false !== strpos( $classes, $signal ) || false !== strpos( $id, $signal ) ) {
                return true;
            }
        }

        // Elementor-specific LCP detection: section background or full-width container.
        if ( false !== strpos( $classes, 'elementor' ) ) {
            if ( false !== strpos( $classes, 'elementor-container' ) || false !== strpos( $html, 'data-elementor-type="section"' ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filter srcset candidates to avoid unnecessarily large downloads.
     * Conservative: only removes candidates that are much larger than the
     * detected or likely rendered width. Preserves LCP/hero images.
     * Returns filtered srcset string or original if filtering cannot improve.
     */
    private function filter_srcset_candidates( $srcset, $target_width, $is_lcp, $attachment_id = 0 ) {
        if ( empty( $srcset ) || $target_width < 100 ) {
            return $srcset;
        }

        // Parse srcset into candidates.
        $candidates = array();
        if ( preg_match_all( '/(\S+)\s+(\d+)w/i', $srcset, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $m ) {
                $url = trim( $m[1] );
                $width = absint( $m[2] );
                $candidates[] = array( 'url' => $url, 'width' => $width );
            }
        }

        if ( empty( $candidates ) ) {
            return $srcset;
        }

        // Determine safe maximum width for this image context.
        $max_width = $target_width;
        if ( $is_lcp ) {
            // For LCP images, allow up to 1.5x the target (preserve quality for hero images).
            $max_width = (int) round( $target_width * 1.5 );
        } else {
            // For regular images, allow up to 1.2x the target to account for DPR.
            $max_width = (int) round( $target_width * 1.2 );
        }

        // Filter: keep candidates up to max_width.
        $filtered = array();
        $original_count = count( $candidates );
        foreach ( $candidates as $candidate ) {
            if ( $candidate['width'] <= $max_width ) {
                $filtered[] = $candidate;
            }
        }

        if ( empty( $filtered ) ) {
            // Safety: if all candidates were filtered, keep the closest one.
            usort( $candidates, function( $a, $b ) use ( $max_width ) {
                $diff_a = abs( $a['width'] - $max_width );
                $diff_b = abs( $b['width'] - $max_width );
                return $diff_a - $diff_b;
            } );
            $filtered[] = $candidates[0];
        }

        $filtered_count = count( $filtered );
        if ( $filtered_count < $original_count && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            \wp_fastlayer_debug_log( '[WP FastLayer Srcset] candidate_pruning | target_width=' . absint( $target_width ) . ' | original_count=' . $original_count . ' | filtered_count=' . $filtered_count . ' | is_lcp=' . ( $is_lcp ? 'yes' : 'no' ) . ' | attachment=' . absint( $attachment_id ) );
        }

        // Rebuild srcset.
        $new_srcset_parts = array();
        foreach ( $filtered as $candidate ) {
            $new_srcset_parts[] = $candidate['url'] . ' ' . $candidate['width'] . 'w';
        }

        return implode( ', ', $new_srcset_parts );
    }

    /**
     * Select an optimal fallback src based on rendered width and available candidates.
     * Avoids using the original/full image when a smaller, appropriate candidate exists.
     * Conservative: only overrides if confident a smaller size is better.
     */
    private function select_optimal_fallback_src( $original_src, $srcset, $target_width, $is_lcp, $attachment_id = 0 ) {
        if ( empty( $srcset ) || $target_width < 100 ) {
            return $original_src;
        }

        // Parse srcset candidates.
        $candidates = array();
        if ( preg_match_all( '/(\S+)\s+(\d+)w/i', $srcset, $matches, PREG_SET_ORDER ) ) {
            foreach ( $matches as $m ) {
                $url = trim( $m[1] );
                $width = absint( $m[2] );
                $candidates[] = array( 'url' => $url, 'width' => $width );
            }
        }

        if ( empty( $candidates ) ) {
            return $original_src;
        }

        // Sort by width ascending.
        usort( $candidates, function( $a, $b ) {
            return $a['width'] - $b['width'];
        } );

        // Find the best match: closest candidate >= target_width, or closest below if none above.
        $best = null;
        foreach ( $candidates as $candidate ) {
            if ( $candidate['width'] >= $target_width ) {
                $best = $candidate;
                break;
            }
        }

        if ( ! $best ) {
            $best = end( $candidates );
        }

        if ( $best && $best['url'] !== $original_src ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                $original_width = 0;
                if ( preg_match( '/\/(\d+)(?:x\d+)?(?:\.webp)?(?:\?|$)/i', $original_src, $m ) ) {
                    $original_width = absint( $m[1] );
                }
                \wp_fastlayer_debug_log( '[WP FastLayer Srcset] fallback_src_optimized | target_width=' . absint( $target_width ) . ' | selected_width=' . $best['width'] . ' | original_width=' . $original_width . ' | is_lcp=' . ( $is_lcp ? 'yes' : 'no' ) . ' | attachment=' . absint( $attachment_id ) );
            }
            return $best['url'];
        }

        return $original_src;
    }

    private function get_webp_url( $path ) {
        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit( wp_normalize_path( $upload_dir['basedir'] ) );
        $base_url   = trailingslashit( $upload_dir['baseurl'] );
        $path       = wp_normalize_path( $path );

        if ( strpos( $path, $base_dir ) !== 0 ) {
            return false;
        }

        return $base_url . ltrim( str_replace( $base_dir, '', $path ), '/' );
    }

    public function generate_webp_images( $metadata, $attachment_id ) {
        if ( $this->should_bypass_local_webp() ) {
            \wp_fastlayer_debug_log( '[WP FastLayer WebP] local_webp_bypassed | attachment_id=' . absint( $attachment_id ) );
            return $metadata;
        }

        if ( ! $this->is_enabled() || ! $this->is_webp_supported() ) {
            return $metadata;
        }

        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! $this->is_supported_image( $file ) || ! file_exists( $file ) ) {
            return $metadata;
        }

        $this->generate_webp_for_file( $file );
        if ( $this->is_progressive_image_loading_enabled() && $this->is_lqip_placeholders_enabled() ) {
            $this->generate_lqip_for_file( $file );
        }

        if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            foreach ( $metadata['sizes'] as $size_data ) {
                if ( ! empty( $size_data['file'] ) ) {
                    $size_file = path_join( dirname( $file ), $size_data['file'] );
                    if ( file_exists( $size_file ) ) {
                        $this->generate_webp_for_file( $size_file );
                        if ( $this->is_progressive_image_loading_enabled() && $this->is_lqip_placeholders_enabled() ) {
                            $this->generate_lqip_for_file( $size_file );
                        }
                    }
                }
            }
        }

        return $metadata;
    }

    public function generate_webp_on_upload( $attachment_id ) {
        if ( $this->should_bypass_local_webp() ) {
            \wp_fastlayer_debug_log( '[WP FastLayer WebP] local_webp_bypassed | upload_attachment_id=' . absint( $attachment_id ) );
            return;
        }

        if ( ! $this->is_enabled() || ! $this->is_webp_supported() ) {
            return;
        }

        if ( function_exists( 'wp_update_image_subsizes' ) ) {
            wp_update_image_subsizes( $attachment_id );
        }

        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! $this->is_supported_image( $file ) || ! file_exists( $file ) ) {
            return;
        }

        $generated = false;
        if ( $this->generate_webp_for_file( $file ) ) {
            $generated = true;
            if ( $this->is_progressive_image_loading_enabled() && $this->is_lqip_placeholders_enabled() ) {
                $this->generate_lqip_for_file( $file );
            }
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            $base_dir = dirname( $file );
            foreach ( $metadata['sizes'] as $size_data ) {
                if ( empty( $size_data['file'] ) ) {
                    continue;
                }

                $size_file = path_join( $base_dir, $size_data['file'] );
                if ( file_exists( $size_file ) && $this->is_supported_image( $size_file ) ) {
                    if ( $this->generate_webp_for_file( $size_file ) ) {
                        $generated = true;
                        if ( $this->is_progressive_image_loading_enabled() && $this->is_lqip_placeholders_enabled() ) {
                            $this->generate_lqip_for_file( $size_file );
                        }
                    }
                }
            }
        }

        if ( $generated ) {
            $this->set_upload_conversion_notice( sprintf( __( 'FastLayer generated WebP files for uploaded image "%s".', 'fastlayer' ), basename( $file ) ) );
        }

        if ( $generated && class_exists( 'WP_FastLayer\Page_Cache' ) ) {
            Page_Cache::get_instance()->clear_all_cache();
        }
    }

    public function print_upload_conversion_notice() {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return;
        }

        $transient = 'wp_fastlayer_webp_upload_notice_' . $user_id;
        $notice = get_transient( $transient );
        if ( ! $notice || ! is_array( $notice ) || empty( $notice['message'] ) ) {
            return;
        }

        delete_transient( $transient );

        $class = isset( $notice['type'] ) && 'error' === $notice['type'] ? 'notice notice-error wpfastlayer-notice' : 'notice notice-success wpfastlayer-notice';
        echo '<div class="' . esc_attr( $class ) . '"><p>' . wp_kses_post( $notice['message'] ) . '</p></div>';
    }

    public function print_nginx_webp_notice() {
        if ( ! $this->is_enabled() || ! $this->is_image_delivery_enabled() ) {
            return;
        }

        if ( $this->server_honours_htaccess() ) {
            return;
        }

        $dismissed = get_user_meta( get_current_user_id(), 'wp_fastlayer_dismissed_nginx_webp_notice', true );
        if ( $dismissed ) {
            return;
        }

        $upload_dir = wp_upload_dir();
        $uploads_path = isset( $upload_dir['basedir'] ) ? $upload_dir['basedir'] : '';
        $uploads_url  = isset( $upload_dir['baseurl'] ) ? $upload_dir['baseurl'] : '';

        $nginx_http_config = <<<'NGINX'
# ============================================================
# STEP 1 of 2 - paste this INSIDE the http { ... } context
# ============================================================
# "map" is only valid in the http context. Putting it inside a
# server block makes nginx refuse to start with:
#   nginx: [emerg] "map" directive is not allowed here
#
# Good places for this:
#   - the http { } block of your nginx.conf, or
#   - a file in your http-level include directory, e.g.
#     /etc/nginx/conf.d/fastlayer-webp.conf
#
map $http_accept $fastlayer_webp_suffix {
    default         "";
    "~*image/webp"  ".webp";
}
NGINX;

        $nginx_server_config = <<<'NGINX'
# ============================================================
# STEP 2 of 2 - paste this INSIDE your server { ... } block
# ============================================================
# IMPORTANT: place this location ABOVE any existing location
# that already matches images, for example:
#     location ~* \.(?:jpg|jpeg|gif|png|ico|xml)$
# nginx evaluates regex locations in the order they appear in
# the server block and stops at the first match. If a stock
# image location is listed first it wins, this location never
# runs, and WebP delivery silently stays switched off while
# nginx still starts cleanly and every request still succeeds.
# ============================================================
# FastLayer stores the WebP copy next to the original with the
# extension replaced:
#
#   /wp-content/uploads/2026/08/photo.jpg   (original)
#   /wp-content/uploads/2026/08/photo.webp  (FastLayer copy)
#
# So the request for "photo.jpg" must look for "photo.webp", NOT
# for "photo.jpg.webp". The capture below strips the original
# extension before the .webp suffix is appended.
#
# Scope is limited to JPEG and PNG. CSS, JS, fonts, PDF, XML and
# SVG are untouched, and a direct request for a .webp file is
# served normally by your existing rules.
#
location ~* ^(?<fastlayer_image_base>.+)\.(?:jpe?g|png)$ {
    add_header Vary Accept always;

    # try_files tests the generated WebP sibling first, then the
    # original image.
    #
    # $fastlayer_webp_suffix is ".webp" only when the client sends
    # "Accept: image/webp", and empty otherwise. With an empty suffix
    # the first candidate becomes the extensionless path (photo),
    # which does not exist, so the second candidate $uri - the
    # original image - is served.
    #
    # The regular expression capture strips the original extension
    # first, so this resolves photo.jpg -> photo.webp rather than
    # photo.jpg -> photo.jpg.webp.
    #
    # When FastLayer has not generated a WebP copy, the first
    # candidate is simply missing and $uri is served unchanged.
    try_files $fastlayer_image_base$fastlayer_webp_suffix $uri =404;
}
NGINX;

        $message = sprintf(
            /* translators: %1$s: uploads path, %2$s: nginx http context config, %3$s: nginx server block config */
            __( '<strong>FastLayer WebP Delivery:</strong> Your server appears to be nginx (or another server that does not process .htaccess). nginx serves existing static images directly and never reaches WordPress, so direct image requests need two small server-level rules. Your generated WebP files live in %1$s. Paste the two blocks below exactly where indicated &mdash; the <code>map</code> block goes in the <code>http</code> context and the <code>location</code> block goes in your <code>server</code> block. Placing the <code>map</code> block inside <code>server</code> will stop nginx from starting. The <code>location</code> block must be placed <strong>above</strong> any existing <code>jpg</code>/<code>jpeg</code>/<code>png</code> location block, because nginx only ever runs the first location that matches. Step 1 of 2:%2$s Step 2 of 2:%3$s', 'fastlayer' ),
            esc_html( $uploads_path ),
            '<pre style="background:#f1f1f1;padding:1em;overflow:auto;margin:1em 0;">' . esc_html( $nginx_http_config ) . '</pre>',
            '<pre style="background:#f1f1f1;padding:1em;overflow:auto;margin:1em 0;">' . esc_html( $nginx_server_config ) . '</pre>'
        );

        echo '<div class="notice notice-info wpfastlayer-notice is-dismissible" data-notice="wp_fastlayer_nginx_webp">';
        echo '<p>' . wp_kses_post( $message ) . '</p>';
        echo '<p><a href="#" class="button button-secondary wp_fastlayer_dismiss_nginx_notice" data-nonce="' . esc_attr( wp_create_nonce( 'wp_fastlayer_dismiss_nginx_webp_notice' ) ) . '">' . esc_html__( 'Dismiss', 'fastlayer' ) . '</a></p>';
        echo '</div>';
    }

    public function ajax_dismiss_nginx_webp_notice() {
        check_ajax_referer( 'wp_fastlayer_dismiss_nginx_webp_notice', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Insufficient permissions.', 'fastlayer' ) );
        }

        update_user_meta( get_current_user_id(), 'wp_fastlayer_dismissed_nginx_webp_notice', true );
        wp_send_json_success();
    }

    private function set_upload_conversion_notice( $message, $type = 'success' ) {
        if ( ! is_admin() ) {
            return;
        }

        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return;
        }

        $transient = 'wp_fastlayer_webp_upload_notice_' . $user_id;
        set_transient(
            $transient,
            array(
                'message' => wp_kses_post( $message ),
                'type'    => $type,
            ),
            30
        );
    }

    public function bulk_generate_webp() {
        if ( $this->should_bypass_local_webp() ) {
            \wp_fastlayer_debug_log( '[WP FastLayer WebP] bulk_webp_bypassed' );
            return 0;
        }

        if ( ! $this->is_bulk_webp_generation_enabled() ) {
            \wp_fastlayer_debug_log( '[WP FastLayer WebP] bulk_webp_disabled' );
            return 0;
        }

        if ( ! $this->is_webp_supported() ) {
            return 0;
        }

        $page = 1;
        $batch_size = 20;
        $count = 0;
        $max_pages = 1; // Limit synchronous execution to 1 batch (20 images) to prevent timeouts

        while ( $page <= $max_pages ) {
            $result = $this->bulk_generate_webp_batch( $page, $batch_size );
            $count += $result['converted'];
            if ( ! $result['has_more'] ) {
                break;
            }
            $page++;
        }

        return $count;
    }

    public function bulk_generate_webp_batch( $page = 1, $batch_size = 20 ) {
        if ( $this->should_bypass_local_webp() ) {
            \wp_fastlayer_debug_log( '[WP FastLayer WebP] bulk_webp_batch_bypassed' );
            return array(
                'processed' => 0,
                'converted' => 0,
                'failed'    => 0,
                'total'     => 0,
                'page'      => $page,
                'has_more'  => false,
                'next_page' => $page,
            );
        }

        if ( ! $this->is_bulk_webp_generation_enabled() ) {
            \wp_fastlayer_debug_log( '[WP FastLayer WebP] bulk_webp_batch_disabled' );
            return array(
                'processed' => 0,
                'converted' => 0,
                'failed'    => 0,
                'total'     => 0,
                'page'      => $page,
                'has_more'  => false,
                'next_page' => $page,
                'overall_processed' => 0,
                'remaining' => 0,
                'percentage' => 0,
            );
        }

        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }

        $page = max( 1, absint( $page ) );
        $batch_size = max( 1, min( 50, absint( $batch_size ) ) );

        $query = new \WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => $this->bulk_supported_mime_types,
                'posts_per_page' => $batch_size,
                'paged'          => $page,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'no_found_rows'  => false,
            )
        );

        $processed = 0;
        $converted = 0;
        $failed = 0;

        if ( $query->have_posts() ) {
            foreach ( $query->posts as $attachment_id ) {
                $processed++;

                $result = $this->convert_attachment_to_webp( (int) $attachment_id );
                if ( ! empty( $result['converted'] ) ) {
                    $converted++;
                } else {
                    $failed++;
                }
            }
        }

        $total = absint( $query->found_posts );
        $has_more = $page < absint( $query->max_num_pages );
        $overall_processed = min( $total, ( ( $page - 1 ) * $batch_size ) + $processed );
        $remaining = max( 0, $total - $overall_processed );
        $percentage = $total > 0 ? min( 100, (int) round( ( $overall_processed / $total ) * 100 ) ) : 0;

        if ( ! $has_more && $processed > 0 && class_exists( 'WP_FastLayer\Page_Cache' ) ) {
            Page_Cache::get_instance()->clear_all_cache();
        }

        return array(
            'processed' => $processed,
            'converted' => $converted,
            'failed'    => $failed,
            'total'     => $total,
            'page'      => $page,
            'has_more'  => $has_more,
            'next_page' => $has_more ? $page + 1 : $page,
            'overall_processed' => $overall_processed,
            'remaining' => $remaining,
            'percentage' => $percentage,
        );
    }

    private function get_webp_attachment_count() {
        global $wpdb;

        $mime_types = $this->bulk_supported_mime_types;
        $placeholders = implode( ',', array_fill( 0, count( $mime_types ), '%s' ) );

        $sql_args = array_merge(
            array( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status = 'inherit' AND post_mime_type IN ($placeholders)" ),
            $mime_types
        );

        return (int) $wpdb->get_var( call_user_func_array( array( $wpdb, 'prepare' ), $sql_args ) );
    }

    public function save_last_run_summary( $status, $duration ) {
        $duration = max( 0, absint( $duration ) );
        $status = sanitize_key( $status );
        $completed_at = (int) current_time( 'timestamp' );

        update_option(
            'wp_fastlayer_webp_last_run',
            array(
                'status'       => $status,
                'duration'     => $duration,
                'completed_at' => $completed_at,
                'started_at'   => $duration > 0 ? max( 0, $completed_at - $duration ) : $completed_at,
            ),
            false
        );
    }

    public function get_conversion_summary() {
        $bulk_enabled = $this->is_bulk_webp_generation_enabled();
        $summary = array(
            'total' => 0,
            'converted' => 0,
            'remaining' => 0,
            'percentage' => 0,
            'supported' => $this->is_webp_supported(),
            'bulk_generation_enabled' => $bulk_enabled,
            'last_run_at' => __( 'No completed run yet', 'fastlayer' ),
            'last_run_duration' => __( 'Not available', 'fastlayer' ),
            'last_run_status' => __( 'No history', 'fastlayer' ),
            'resume_message' => __( 'Start a conversion run to create WebP files for your existing media library.', 'fastlayer' ),
        );

        if ( ! $bulk_enabled ) {
            $summary['resume_message'] = __( 'Bulk WebP generation is currently disabled. Existing generated WebP files will continue working normally.', 'fastlayer' );
        }

        $query = new \WP_Query(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => $this->bulk_supported_mime_types,
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'no_found_rows'  => true,
            )
        );

        if ( $query->have_posts() ) {
            $summary['total'] = count( $query->posts );

            foreach ( $query->posts as $attachment_id ) {
                if ( $this->attachment_has_webp( (int) $attachment_id ) ) {
                    $summary['converted']++;
                }
            }

            $summary['remaining'] = max( 0, $summary['total'] - $summary['converted'] );
            $summary['percentage'] = $summary['total'] > 0 ? (int) round( ( $summary['converted'] / $summary['total'] ) * 100 ) : 0;
        }

        $last_run = get_option( 'wp_fastlayer_webp_last_run', array() );
        if ( ! empty( $last_run['completed_at'] ) ) {
            $summary['last_run_at'] = wp_date(
                get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
                (int) $last_run['completed_at']
            );
            $summary['last_run_duration'] = $this->format_duration( isset( $last_run['duration'] ) ? (int) $last_run['duration'] : 0 );
            $summary['last_run_status'] = $this->get_last_run_status_label( isset( $last_run['status'] ) ? $last_run['status'] : '' );
        }

        if ( $summary['remaining'] > 0 && $summary['converted'] > 0 ) {
            $resume_message = __( 'Resume from remaining only. Images that already have WebP files will be skipped automatically.', 'fastlayer' );
            if ( ! empty( $last_run['completed_at'] ) ) {
                $resume_message = sprintf(
                    /* translators: %1$s is the last run status, %2$s is the last run duration, %3$s is the resume message. */
                    __( '%1$s in %2$s. %3$s', 'fastlayer' ),
                    $summary['last_run_status'],
                    $summary['last_run_duration'],
                    $resume_message
                );
            }
            $summary['resume_message'] = $resume_message;
        } elseif ( $summary['remaining'] > 0 ) {
            $summary['resume_message'] = __( 'WebP conversion will process the remaining eligible images in your media library.', 'fastlayer' );
        } elseif ( $summary['total'] > 0 ) {
            $summary['resume_message'] = __( 'All eligible media library images already have WebP files.', 'fastlayer' );
        }

        return $summary;
    }

    private function get_last_run_status_label( $status ) {
        switch ( $status ) {
            case 'success':
                return __( 'Completed', 'fastlayer' );
            case 'cancelled':
                return __( 'Cancelled', 'fastlayer' );
            case 'error':
                return __( 'Failed', 'fastlayer' );
            case 'empty':
                return __( 'No work needed', 'fastlayer' );
            default:
                return __( 'No history', 'fastlayer' );
        }
    }

    private function format_duration( $seconds ) {
        $seconds = max( 0, absint( $seconds ) );
        if ( 0 === $seconds ) {
            return __( 'Less than a second', 'fastlayer' );
        }

        if ( $seconds < MINUTE_IN_SECONDS ) {
            return sprintf( _n( '%s second', '%s seconds', $seconds, 'fastlayer' ), number_format_i18n( $seconds ) );
        }

        if ( $seconds < HOUR_IN_SECONDS ) {
            $minutes = floor( $seconds / MINUTE_IN_SECONDS );
            $remaining_seconds = $seconds % MINUTE_IN_SECONDS;
            if ( 0 === $remaining_seconds ) {
                return sprintf( _n( '%s minute', '%s minutes', $minutes, 'fastlayer' ), number_format_i18n( $minutes ) );
            }

            return sprintf(
                __( '%1$s min %2$s sec', 'fastlayer' ),
                number_format_i18n( $minutes ),
                number_format_i18n( $remaining_seconds )
            );
        }

        $hours = floor( $seconds / HOUR_IN_SECONDS );
        $minutes = floor( ( $seconds % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );

        if ( 0 === $minutes ) {
            return sprintf( _n( '%s hour', '%s hours', $hours, 'fastlayer' ), number_format_i18n( $hours ) );
        }

        return sprintf(
            __( '%1$s hr %2$s min', 'fastlayer' ),
            number_format_i18n( $hours ),
            number_format_i18n( $minutes )
        );
    }

    private function attachment_has_webp( $attachment_id ) {
        $file = get_attached_file( $attachment_id );
        if ( ! $file || ! $this->is_supported_image( $file ) || ! file_exists( $file ) ) {
            return false;
        }

        return file_exists( $this->get_webp_path( $file ) );
    }

    private function generate_webp_for_file( $path ) {
        if ( ! $this->is_supported_image( $path ) || ! file_exists( $path ) || ! is_readable( $path ) ) {
            return false;
        }

        $webp_path = $this->get_webp_path( $path );
        if ( file_exists( $webp_path ) && filemtime( $webp_path ) >= filemtime( $path ) ) {
            return true;
        }

        $target_dir = dirname( $webp_path );
        if ( ! is_dir( $target_dir ) && ! wp_mkdir_p( $target_dir ) ) {
            return false;
        }

        if ( ! is_writable( $target_dir ) ) {
            return false;
        }

        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

        if ( function_exists( 'imagewebp' ) ) {
            $result = $this->generate_webp_via_gd( $path, $webp_path, $ext );
            if ( ! $result ) {
                error_log( 'WP FastLayer WebP GD conversion failed for: ' . $path );
            }
            return $result;
        }

        if ( class_exists( 'Imagick' ) ) {
            $result = $this->generate_webp_via_imagick( $path, $webp_path );
            if ( ! $result ) {
                error_log( 'WP FastLayer WebP Imagick conversion failed for: ' . $path );
            }
            return $result;
        }

        return false;
    }

    private function generate_webp_via_gd( $path, $webp_path, $ext ) {
        switch ( $ext ) {
            case 'jpg':
            case 'jpeg':
                $image = @imagecreatefromjpeg( $path );
                break;
            case 'png':
                $image = @imagecreatefrompng( $path );
                if ( $image ) {
                    imagepalettetotruecolor( $image );
                    imagealphablending( $image, false );
                    imagesavealpha( $image, true );
                }
                break;
            default:
                return false;
        }

        if ( ! $image ) {
            return false;
        }

        $quality = $this->get_webp_quality();
        $saved = @imagewebp( $image, $webp_path, $quality );
        imagedestroy( $image );

        return (bool) $saved;
    }

    private function generate_webp_via_imagick( $path, $webp_path ) {
        try {
            $quality = $this->get_webp_quality();
            $image = new \Imagick( $path );
            $image->setImageFormat( 'webp' );
            $image->setImageCompression( \Imagick::COMPRESSION_WEBP );
            $image->setImageCompressionQuality( $quality );
            $image->setOption( 'webp:method', '6' );
            $image->writeImage( $webp_path );
            $image->clear();
            $image->destroy();
            return true;
        } catch ( \Exception $e ) {
            error_log( 'WP FastLayer WebP conversion failed: ' . $e->getMessage() );
            return false;
        }
    }

    private function generate_lqip_for_file( $path ) {
        if ( ! $this->is_supported_image( $path ) || ! file_exists( $path ) || ! is_readable( $path ) ) {
            $this->debug_log_lqip( 'Placeholder generation skipped (unsupported or unreadable source)', array( 'source' => $path ) );
            return false;
        }

        $lqip_path = $this->get_lqip_path( $path );
        if ( file_exists( $lqip_path ) && filemtime( $lqip_path ) >= filemtime( $path ) ) {
            $this->debug_log_lqip( 'Placeholder skipped (up-to-date)', array( 'source' => $path, 'placeholder' => $lqip_path ) );
            return true;
        }

        $target_dir = dirname( $lqip_path );
        if ( ! is_dir( $target_dir ) && ! wp_mkdir_p( $target_dir ) ) {
            $this->debug_log_lqip( 'Placeholder generation failed (directory create failed)', array( 'target_dir' => $target_dir, 'placeholder' => $lqip_path ) );
            return false;
        }

        if ( ! is_writable( $target_dir ) ) {
            $this->debug_log_lqip( 'Placeholder generation failed (target directory not writable)', array( 'target_dir' => $target_dir, 'placeholder' => $lqip_path ) );
            return false;
        }

        $width = $this->get_lqip_width();
        $quality = $this->get_lqip_quality();
        $ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

        if ( function_exists( 'imagewebp' ) ) {
            $result = $this->generate_lqip_via_gd( $path, $lqip_path, $ext, $width, $quality );
            if ( $result ) {
                $this->debug_log_lqip( 'Placeholder generated', array( 'source' => $path, 'placeholder' => $lqip_path, 'quality' => $quality, 'width' => $width ) );
            } else {
                $this->debug_log_lqip( 'Placeholder generation failed', array( 'source' => $path, 'placeholder' => $lqip_path, 'engine' => 'gd' ) );
            }
            return $result;
        }

        if ( class_exists( 'Imagick' ) ) {
            $result = $this->generate_lqip_via_imagick( $path, $lqip_path, $width, $quality );
            if ( $result ) {
                $this->debug_log_lqip( 'Placeholder generated', array( 'source' => $path, 'placeholder' => $lqip_path, 'quality' => $quality, 'width' => $width ) );
            } else {
                $this->debug_log_lqip( 'Placeholder generation failed', array( 'source' => $path, 'placeholder' => $lqip_path, 'engine' => 'imagick' ) );
            }
            return $result;
        }

        $this->debug_log_lqip( 'Placeholder generation failed (no supported image engine)', array( 'source' => $path, 'placeholder' => $lqip_path ) );
        return false;
    }

    private function generate_lqip_via_gd( $path, $lqip_path, $ext, $width, $quality ) {
        switch ( $ext ) {
            case 'jpg':
            case 'jpeg':
                $source = @imagecreatefromjpeg( $path );
                break;
            case 'png':
                $source = @imagecreatefrompng( $path );
                if ( $source ) {
                    imagepalettetotruecolor( $source );
                    imagealphablending( $source, false );
                    imagesavealpha( $source, true );
                }
                break;
            default:
                return false;
        }

        if ( ! $source ) {
            return false;
        }

        $original_width = imagesx( $source );
        $original_height = imagesy( $source );
        if ( $original_width <= 0 || $original_height <= 0 ) {
            imagedestroy( $source );
            return false;
        }

        $target_width = min( $width, $original_width );
        $target_height = (int) round( ( $original_height / $original_width ) * $target_width );
        $destination = imagecreatetruecolor( $target_width, $target_height );

        if ( 'png' === $ext ) {
            imagealphablending( $destination, false );
            imagesavealpha( $destination, true );
        }

        imagecopyresampled( $destination, $source, 0, 0, 0, 0, $target_width, $target_height, $original_width, $original_height );
        $saved = @imagewebp( $destination, $lqip_path, $quality );
        imagedestroy( $source );
        imagedestroy( $destination );

        return (bool) $saved;
    }

    private function generate_lqip_via_imagick( $path, $lqip_path, $width, $quality ) {
        try {
            $image = new \Imagick( $path );
            $image->setImageFormat( 'webp' );
            $image->setImageCompression( \Imagick::COMPRESSION_WEBP );
            $image->setImageCompressionQuality( $quality );
            $image->resizeImage( $width, 0, \Imagick::FILTER_LANCZOS, 1 );
            $image->setOption( 'webp:method', '6' );
            $image->writeImage( $lqip_path );
            $image->clear();
            $image->destroy();
            return true;
        } catch ( \Exception $e ) {
            error_log( 'WP FastLayer LQIP conversion failed: ' . $e->getMessage() );
            return false;
        }
    }

    private function add_progressive_placeholder_to_picture( $html, $src ) {
        if ( ! $this->should_optimize_image_delivery() || ! $this->is_progressive_image_loading_enabled() || ! $this->is_lqip_placeholders_enabled() ) {
            return $html;
        }

        if ( ! $this->is_internal_url( $src ) ) {
            return $html;
        }

        $path = $this->url_to_path( $src );
        if ( ! $path || ! file_exists( $path ) || ! $this->is_supported_image( $path ) ) {
            return $html;
        }

        $lqip_path = $this->get_lqip_path( $path );
        if ( ! file_exists( $lqip_path ) ) {
            $this->generate_lqip_for_file( $path );
        }

        $lqip_url = file_exists( $lqip_path ) ? $this->get_lqip_url( $lqip_path ) : false;
        if ( ! $lqip_url ) {
            $this->debug_log_lqip( 'Placeholder fallback triggered', array( 'source' => $src, 'path' => $path, 'placeholder_path' => $lqip_path ) );
            return $html;
        }

        $style = 'background-image:url(' . esc_url( $lqip_url ) . '); background-size:cover; background-position:center; background-repeat:no-repeat;';
        if ( false !== stripos( $html, 'data-wp-fastlayer-lqip=' ) ) {
            return $html;
        }

        if ( preg_match( '/<picture\b([^>]*)>/i', $html, $matches ) ) {
            $attributes = $matches[1];
            if ( preg_match( '/\bstyle\s*=\s*("|\')(.*?)\1/i', $attributes, $style_matches ) ) {
                $existing_style = trim( $style_matches[2] );
                $new_style = $existing_style . ' ' . $style;
                $html = preg_replace( '/\bstyle\s*=\s*("|\')(.*?)\1/i', 'style="' . esc_attr( $new_style ) . '"', $html, 1 );
            } else {
                $html = preg_replace( '/<picture\b/i', '<picture data-wp-fastlayer-lqip="1" style="' . esc_attr( $style ) . '"', $html, 1 );
            }

            if ( false === stripos( $html, 'data-wp-fastlayer-lqip=' ) ) {
                $html = preg_replace( '/<picture\b/i', '<picture data-wp-fastlayer-lqip="1"', $html, 1 );
            }

            $this->debug_log_lqip( 'Placeholder applied to picture', array( 'source' => $src, 'placeholder_url' => $lqip_url ) );
        }

        return $html;
    }

    public function enqueue_progressive_image_loading_assets() {
        if ( is_admin() || $this->should_bypass_local_webp() || ! $this->is_enabled() || ! $this->is_image_delivery_enabled() || ! $this->is_progressive_image_loading_enabled() ) {
            return;
        }

        // Both values are integers, so they are cast instead of HTML-escaped:
        // style element content is raw text and entity encoding would corrupt the CSS.
        $blur     = (int) $this->get_lqip_blur_intensity();
        $duration = (int) $this->get_lqip_fade_duration();

        $css = 'picture[data-wp-fastlayer-lqip]{position:relative;display:inline-block;overflow:hidden;background-color:#f5f5f5;line-height:0;}'
            . 'picture[data-wp-fastlayer-lqip]::before{content:"";position:absolute;inset:0;background-image:inherit;background-size:inherit;background-position:inherit;background-repeat:inherit;filter:blur(' . $blur . 'px);transform:scale(1.05);opacity:1;transition:opacity ' . $duration . 'ms ease;pointer-events:none;}'
            . 'picture[data-wp-fastlayer-lqip].wpfl-loaded::before{opacity:0;}'
            . 'picture[data-wp-fastlayer-lqip] img{position:relative;z-index:1;}';

        wp_register_style( 'wp-fastlayer-lqip', false, array(), WP_FASTLAYER_VERSION );
        wp_enqueue_style( 'wp-fastlayer-lqip' );
        wp_add_inline_style( 'wp-fastlayer-lqip', $css );

        /*
         * The runtime stays inline on purpose. The equivalent file
         * assets/js/lqip-frontend.js would be picked up by minify_js(), whose
         * newline-to-space collapsing turns a "//" comment into a trailing
         * comment and swallows the code on the following line, breaking the
         * error handler. Registering a handle without a src keeps this script
         * out of both the minifier and the combine/defer filters.
         */
        $js = <<<'JS'
(function () {
    'use strict';
    function clearPlaceholder(picture) {
        picture.classList.add('wpfl-loaded');
    }
    function handleImage(picture, img) {
        if (img.complete && img.naturalWidth > 0) {
            clearPlaceholder(picture);
            return;
        }
        img.addEventListener('load', function () { clearPlaceholder(picture); }, { once: true });
        img.addEventListener('error', function () { clearPlaceholder(picture); }, { once: true });
    }
    function init() {
        document.querySelectorAll('picture[data-wp-fastlayer-lqip]').forEach(function (picture) {
            var img = picture.querySelector('img');
            if (img) { handleImage(picture, img); }
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
JS;

        wp_register_script( 'wp-fastlayer-lqip', false, array(), WP_FASTLAYER_VERSION, array( 'in_footer' => true ) );
        wp_enqueue_script( 'wp-fastlayer-lqip' );
        wp_add_inline_script( 'wp-fastlayer-lqip', $js );
    }

    public function add_webp_srcset_to_image_attributes( $attr, $attachment, $size ) {
        if ( $this->should_bypass_local_webp() ) {
            \wp_fastlayer_debug_log( '[WP FastLayer WebP] responsive_optimizer_bypassed | attachment=' . ( is_object( $attachment ) && ! empty( $attachment->ID ) ? absint( $attachment->ID ) : 0 ) );
            return $attr;
        }

        if ( ! $this->should_optimize_image_delivery() ) {
            return $attr;
        }

        $attachment_id = 0;
        if ( is_array( $attachment ) && ! empty( $attachment['ID'] ) ) {
            $attachment_id = absint( $attachment['ID'] );
        } elseif ( is_object( $attachment ) && ! empty( $attachment->ID ) ) {
            $attachment_id = absint( $attachment->ID );
        }

        if ( ! $attachment_id || ! isset( $attr['src'] ) || ! $this->is_internal_url( $attr['src'] ) ) {
            return $attr;
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $incoming_src = $attr['src'] ?? '';
            \wp_fastlayer_debug_log( '[WP FastLayer Srcset] add_webp_srcset_to_image_attributes_entry | attachment=' . absint( $attachment_id ) . ' | incoming_src=' . substr( $incoming_src, -50 ) );
        }

        $this->ensure_attachment_subsizes( $attachment_id );

        $resolved_size = $size;
        $target_width = $this->infer_target_width_from_attributes( $attr, 0 );
        if ( $target_width > 0 ) {
            $resolved_size = $this->find_best_size_for_width( $attachment_id, $target_width, $size );
        }

        $resolved_image = wp_get_attachment_image_src( $attachment_id, $resolved_size );
        if ( is_array( $resolved_image ) && ! empty( $resolved_image[0] ) ) {
            $attr['src'] = $resolved_image[0];

            if ( isset( $attr['data-src'] ) ) {
                $attr['data-src'] = $resolved_image[0];
            }

            if ( isset( $attr['data-lazy-src'] ) ) {
                $attr['data-lazy-src'] = $resolved_image[0];
            }
        }

        if ( ! empty( $attr['class'] ) && preg_match( '/elementor/i', $attr['class'] ) ) {
            $this->bump_frontend_debug_stat( 'elementor_detected' );
            $this->debug_log_frontend(
                'Elementor image attributes detected',
                array(
                    'attachment_id' => $attachment_id,
                    'size'          => is_array( $size ) ? implode( 'x', array_map( 'absint', $size ) ) : (string) $size,
                )
            );
        }

        $computed_srcset = wp_get_attachment_image_srcset( $attachment_id, $resolved_size );
        if ( ! empty( $computed_srcset ) ) {
            // Apply adaptive srcset generation: detect LCP status and filter candidates.
            $is_lcp = $this->is_lcp_image( $attr, '' );
            $effective_target = $target_width > 0 ? $target_width : 555;
            $filtered_srcset = $this->filter_srcset_candidates( $computed_srcset, $effective_target, $is_lcp, $attachment_id );
            
            // Select an optimal fallback src to avoid oversized downloads.
            $original_src = $attr['src'] ?? '';
            if ( ! empty( $filtered_srcset ) && ! $is_lcp ) {
                $optimized_src = $this->select_optimal_fallback_src( $original_src, $filtered_srcset, $effective_target, false, $attachment_id );
                if ( $optimized_src !== $original_src ) {
                    $attr['src'] = $optimized_src;
                    if ( isset( $attr['data-src'] ) ) {
                        $attr['data-src'] = $optimized_src;
                    }
                    if ( isset( $attr['data-lazy-src'] ) ) {
                        $attr['data-lazy-src'] = $optimized_src;
                    }
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        \wp_fastlayer_debug_log( '[WP FastLayer Srcset] fallback_src_updated | attachment=' . absint( $attachment_id ) . ' | target_width=' . absint( $effective_target ) );
                    }
                }
            }
            
            $attr['srcset'] = $filtered_srcset;

            if ( isset( $attr['data-srcset'] ) ) {
                $attr['data-srcset'] = $filtered_srcset;
            }

            if ( isset( $attr['data-lazy-srcset'] ) ) {
                $attr['data-lazy-srcset'] = $filtered_srcset;
            }
        } elseif ( empty( $attr['srcset'] ) ) {
            $attr['srcset'] = '';
        }

        $computed_sizes = wp_get_attachment_image_sizes( $attachment_id, $resolved_size );
        if ( empty( $computed_sizes ) ) {
            $computed_sizes = $this->infer_sizes_from_attributes( $attr, $target_width );
        } else {
            $computed_sizes = $this->normalize_sizes_for_target_width( $computed_sizes, '' );
        }

        if ( ! empty( $computed_sizes ) ) {
            $attr['sizes'] = $computed_sizes;

            if ( isset( $attr['data-sizes'] ) ) {
                $attr['data-sizes'] = $computed_sizes;
            }

            if ( isset( $attr['data-lazy-sizes'] ) ) {
                $attr['data-lazy-sizes'] = $computed_sizes;
            }
        }

        if ( ! empty( $attr['srcset'] ) ) {
            $this->bump_frontend_debug_stat( 'srcset_generated' );
        } else {
            $this->bump_frontend_debug_stat( 'srcset_missing' );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $final_src = $attr['src'] ?? '';
            \wp_fastlayer_debug_log( '[WP FastLayer Srcset] add_webp_srcset_to_image_attributes_exit | attachment=' . absint( $attachment_id ) . ' | final_src=' . substr( $final_src, -50 ) . ' | has_srcset=' . ( ! empty( $attr['srcset'] ) ? 'yes' : 'no' ) );
        }

        $this->debug_log_frontend(
            'Attachment attribute pass',
            array(
                'attachment_id' => $attachment_id,
                'resolved_size' => is_array( $resolved_size ) ? implode( 'x', array_map( 'absint', $resolved_size ) ) : (string) $resolved_size,
                'has_srcset'    => ! empty( $attr['srcset'] ) ? 'yes' : 'no',
                'has_sizes'     => ! empty( $attr['sizes'] ) ? 'yes' : 'no',
            )
        );

        return $attr;
    }

    private function infer_sizes_from_attributes( $attr, $preferred_width = 0 ) {
        if ( ! empty( $attr['sizes'] ) ) {
            return $this->normalize_sizes_for_target_width( $attr['sizes'], '' );
        }

        $target_width = absint( $preferred_width );
        if ( $target_width < 1 ) {
            $target_width = $this->infer_target_width_from_attributes( $attr, 0 );
        }

        if ( $target_width > 0 ) {
            return '(max-width: ' . $target_width . 'px) 100vw, ' . $target_width . 'px';
        }

        return '100vw';
    }

    public function wrap_attachment_image_with_picture( $html, $attachment_id, $size, $icon, $attr ) {
        if ( $this->should_bypass_local_webp() || ! $this->is_enabled() || ! $this->is_image_delivery_enabled() || $icon || empty( $html ) || false !== stripos( $html, '<picture' ) || false !== stripos( $html, 'data-wp-fastlayer-picture=' ) ) {
            if ( $this->should_bypass_local_webp() ) {
                \wp_fastlayer_debug_log( '[WP FastLayer WebP] wrap_attachment_image_with_picture_bypassed | attachment_id=' . absint( $attachment_id ) );
            }
            return $html;
        }

        $resolved_size = $size;
        if ( $attachment_id ) {
            $resolved_size = $this->resolve_attachment_size_name( $attachment_id, $size, $html );
        }

        $srcset = $this->extract_attribute_value( $html, 'srcset' );
        $sizes  = $this->extract_attribute_value( $html, 'sizes' );
        $src    = $this->extract_attribute_value( $html, 'src' );
        
        $original_src_in_html = $src;

        // IMPORTANT: Preserve optimized src from add_webp_srcset_to_image_attributes.
        // Only call wp_get_attachment_image_src if src is missing/empty.
        if ( empty( $src ) && $attachment_id ) {
            $image_data = wp_get_attachment_image_src( $attachment_id, $resolved_size );
            if ( is_array( $image_data ) && ! empty( $image_data[0] ) ) {
                $src = $image_data[0];
                $html = $this->set_image_attribute( $html, 'src', $src );
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    \wp_fastlayer_debug_log( '[WP FastLayer Srcset] wrap_picture_fallback_src_filled | attachment=' . absint( $attachment_id ) . ' | src_was_empty=yes' );
                }
            }
        } elseif ( ! empty( $src ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // Log that we preserved the optimized src from HTML (set by add_webp_srcset_to_image_attributes).
            \wp_fastlayer_debug_log( '[WP FastLayer Srcset] wrap_picture_src_preserved | attachment=' . absint( $attachment_id ) . ' | src=' . substr( $src, -40 ) );
        }

        if ( empty( $srcset ) && $attachment_id ) {
            $srcset = $this->get_attachment_srcset( $attachment_id, $resolved_size );
        }

        if ( $attachment_id && ! empty( $src ) ) {
            $calculated_srcset = $this->calculate_attachment_srcset( $attachment_id, $resolved_size, $src );
            if ( ! empty( $calculated_srcset ) ) {
                $srcset = $calculated_srcset;
            }
        }

        if ( empty( $sizes ) && $attachment_id ) {
            $sizes = $this->get_attachment_sizes( $attachment_id, $resolved_size );
        }

        if ( empty( $sizes ) ) {
            $sizes = $this->infer_image_sizes_from_html( $html );
        }

        if ( $attachment_id && ! empty( $src ) ) {
            $sizes = $this->calculate_attachment_sizes( $attachment_id, $resolved_size, $src, $sizes, $html );
        }

        $html = $this->add_srcset_and_sizes_to_image_tag( $html, $srcset, $sizes );

        $webp_srcset = '';
        if ( ! empty( $srcset ) ) {
            $webp_srcset = $this->build_webp_srcset_from_srcset( $srcset, $src, $attachment_id, $resolved_size );
        }

        if ( empty( $webp_srcset ) && ! empty( $srcset ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                \wp_fastlayer_debug_log( '[WP FastLayer WebP] skipped_webp_source_creation | attachment=' . absint( $attachment_id ) . ' | srcset_count=' . count( array_filter( array_map( 'trim', explode( ',', $srcset ) ) ) ) );
            }

            $picture = '<picture data-wp-fastlayer-picture="1">' . $html . '</picture>';
            return $this->add_progressive_placeholder_to_picture( $picture, $src );
        }

        if ( empty( $webp_srcset ) ) {
            $image_data = wp_get_attachment_image_src( $attachment_id, $resolved_size );
            if ( is_array( $image_data ) && ! empty( $image_data[0] ) ) {
                $src = $image_data[0];
            }

            if ( ! empty( $src ) && $this->is_internal_url( $src ) ) {
                $src_path = $this->url_to_path( $src );
                // get_webp_path() returns any non-jpg/png path unchanged, so
                // without this check an SVG or GIF would pass file_exists()
                // and be advertised to the browser as image/webp.
                if ( $src_path && $this->is_supported_image( $src_path ) ) {
                    $webp_path = $this->get_webp_path( $src_path );
                    if ( file_exists( $webp_path ) ) {
                        $webp_url = $this->get_webp_url( $webp_path );
                        if ( $webp_url ) {
                            // Prefer building a responsive WebP srcset from attachment metadata.
                            $responsive_webp = '';
                            if ( ! empty( $attachment_id ) ) {
                                $responsive_webp = $this->build_webp_srcset_from_attachment( $attachment_id, $src );
                            }
                            if ( ! empty( $responsive_webp ) ) {
                                $webp_srcset = $responsive_webp;
                            } else {
                                // Fallback to single WebP URL when no responsive variants available.
                                $webp_srcset = esc_url( $webp_url );
                            }
                        }
                    }
                }
            }
        }

        if ( empty( $webp_srcset ) ) {
            $this->bump_frontend_debug_stat( 'replacement_failure' );
            $this->bump_frontend_debug_stat( 'skipped_missing_webp' );
            $this->debug_log_frontend(
                'Picture wrap skipped (missing WebP srcset)',
                array(
                    'attachment_id' => absint( $attachment_id ),
                )
            );
            return $html;
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $candidates = 0;
            if ( '' !== trim( (string) $webp_srcset ) ) {
                $candidates = count( array_filter( array_map( 'trim', explode( ',', (string) $webp_srcset ) ) ) );
            }
            \wp_fastlayer_debug_log( '[WP FastLayer Active Source] builder=wrap_attachment_image_with_picture | attachment=' . absint( $attachment_id ) . ' | candidates=' . $candidates . ' | single_fallback=' . ( $candidates <= 1 ? 'yes' : 'no' ) . ' | srcset=' . substr( $webp_srcset, 0, 200 ) );
        }
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $candidates = 0;
            if ( '' !== trim( (string) $webp_srcset ) ) {
                $candidates = count( array_filter( array_map( 'trim', explode( ',', (string) $webp_srcset ) ) ) );
            }
            \wp_fastlayer_debug_log( '[WP FastLayer Active Source] builder=replace_image_tag_with_picture | attachment=' . absint( $attachment_id ) . ' | candidates=' . $candidates . ' | single_fallback=' . ( $candidates <= 1 ? 'yes' : 'no' ) . ' | srcset=' . substr( $webp_srcset, 0, 200 ) );
        }
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $candidates = 0;
            if ( '' !== trim( (string) $webp_srcset ) ) {
                $candidates = count( array_filter( array_map( 'trim', explode( ',', (string) $webp_srcset ) ) ) );
            }
            \wp_fastlayer_debug_log( '[WP FastLayer Active Source] builder=replace_data_src_image_tag_with_picture | attachment=' . absint( $attachment_id ) . ' | candidates=' . $candidates . ' | single_fallback=' . ( $candidates <= 1 ? 'yes' : 'no' ) . ' | srcset=' . substr( $webp_srcset, 0, 200 ) );
        }
        // If we only have a single WebP URL but the original JPG srcset exists,
        // build a responsive WebP srcset inline from the JPG candidates.
        if ( is_string( $webp_srcset ) && strpos( $webp_srcset, ',' ) === false && ! empty( $srcset ) ) {
            $candidates = array_filter( array_map( 'trim', explode( ',', $srcset ) ) );
            $webp_candidates = array();
            foreach ( $candidates as $candidate ) {
                if ( $candidate === '' ) {
                    continue;
                }
                $parts = preg_split( '/\s+/', $candidate );
                $descriptor = '';
                if ( count( $parts ) > 1 ) {
                    $descriptor = array_pop( $parts );
                }
                $url = implode( ' ', $parts );
                // Replace common raster extensions with .webp, preserving query string.
                $webp_url = preg_replace( '/\.(jpe?g|png)(\?.*)?$/i', '.webp$2', $url );
                if ( $webp_url === $url ) {
                    $webp_url = $url . '.webp';
                }
                $webp_candidates[] = $webp_url . ( $descriptor ? ' ' . $descriptor : '' );
            }
            if ( ! empty( $webp_candidates ) ) {
                $webp_srcset = implode( ', ', $webp_candidates );
            }
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            \wp_fastlayer_debug_log( '[WP FastLayer Active Source] final_webp_srcset=' . substr( (string) $webp_srcset, 0, 200 ) );
        }

        $source = '<source type="image/webp" srcset="' . esc_attr( $webp_srcset ) . '"';
        if ( ! empty( $sizes ) ) {
            $source .= ' sizes="' . esc_attr( $sizes ) . '"';
        }
        $source .= '>';

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            \wp_fastlayer_debug_log( '[WP FastLayer WebP] webp_source_output | attachment=' . absint( $attachment_id ) . ' | srcset=' . substr( $webp_srcset, 0, 200 ) . ' | sizes=' . substr( $sizes, 0, 200 ) );
        }

        $html = $this->set_image_attribute( $html, 'data-wp-fastlayer-optimized', '1' );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $final_src_in_html = $this->extract_attribute_value( $html, 'src' );
            \wp_fastlayer_debug_log( '[WP FastLayer Srcset] wrap_picture_final_html | attachment=' . absint( $attachment_id ) . ' | final_src_in_html=' . substr( $final_src_in_html, -50 ) );
        }

        $this->bump_frontend_debug_stat( 'converted_to_picture' );
        $this->bump_frontend_debug_stat( 'replacement_success' );
        $this->debug_log_frontend(
            'Picture generated from attachment image',
            array(
                'attachment_id' => absint( $attachment_id ),
                'has_sizes'     => ! empty( $sizes ) ? 'yes' : 'no',
            )
        );

        $picture = '<picture data-wp-fastlayer-picture="1">' . $source . $html . '</picture>';
        return $this->add_progressive_placeholder_to_picture( $picture, $src );
    }

    public function replace_content_images_with_webp( $content ) {
        if ( $this->should_bypass_local_webp() || ! $this->is_enabled() || ! $this->is_image_delivery_enabled() || is_admin() || empty( $content ) ) {
            if ( $this->should_bypass_local_webp() ) {
                \wp_fastlayer_debug_log( '[WP FastLayer WebP] content_webp_rewrite_bypassed' );
            }
            return $content;
        }

        $this->start_frontend_debug_run( $content );

        $placeholders = array();
        $content = preg_replace_callback(
            '/<picture\b[^>]*>.*?<\/picture>/is',
            function ( $matches ) use ( &$placeholders ) {
                $key = '__WP_FASTLAYER_PICTURE_PLACEHOLDER_' . count( $placeholders ) . '__';
                $placeholders[ $key ] = $matches[0];
                $this->bump_frontend_debug_stat( 'skipped_already_optimized' );
                return $key;
            },
            $content
        );

        if ( $this->is_frontend_debug_enabled() ) {
            $standard_matches = preg_match_all(
                '/<img\s+(?![^>]*\b(?:data-src|data-lazy-src)=)([^>]*?)src=("|\')([^"\']+\.(?:jpe?g|png)(?:\?[^"\']*)?)\2([^>]*?)>/i',
                $content,
                $standard_match_output
            );
            $lazy_matches = preg_match_all(
                '/<img\s+([^>]*?)\b(data-src|data-lazy-src)=("|\')([^"\']+\.(?:jpe?g|png)(?:\?[^"\']*)?)\3([^>]*?)>/i',
                $content,
                $lazy_match_output
            );
            $this->bump_frontend_debug_stat( 'regex_match_standard', (int) $standard_matches );
            $this->bump_frontend_debug_stat( 'regex_match_lazy', (int) $lazy_matches );
            $this->debug_log_frontend(
                'Regex matches collected',
                array(
                    'standard' => (int) $standard_matches,
                    'lazy'     => (int) $lazy_matches,
                )
            );
        }

        $replaced = preg_replace_callback(
            '/<img\s+(?![^>]*\b(?:data-src|data-lazy-src)=)([^>]*?)src=("|\')([^"\']+\.(?:jpe?g|png)(?:\?[^"\']*)?)\2([^>]*?)>/i',
            array( $this, 'replace_image_tag_with_picture' ),
            $content
        );
        if ( null !== $replaced ) {
            $content = $replaced;
        }

        $replaced = preg_replace_callback(
            '/<img\s+([^>]*?)\b(data-src|data-lazy-src)=("|\')([^"\']+\.(?:jpe?g|png)(?:\?[^"\']*)?)\3([^>]*?)>/i',
            array( $this, 'replace_data_src_image_tag_with_picture' ),
            $content
        );
        if ( null !== $replaced ) {
            $content = $replaced;
        }

        if ( ! empty( $placeholders ) ) {
            $content = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $content );
        }

        $this->finish_frontend_debug_run();

        return $content;
    }

    private function replace_image_tag_with_picture( $matches ) {
        $attrs = $matches[1];
        $quote = $matches[2];
        $src   = $matches[3];
        $rest  = $matches[4];

        if ( false !== stripos( $matches[0], 'elementor' ) ) {
            $this->bump_frontend_debug_stat( 'elementor_detected' );
        }

        if ( ! $this->is_internal_url( $src ) ) {
            $this->bump_frontend_debug_stat( 'replacement_failure' );
            return $matches[0];
        }

        $attachment_id = attachment_url_to_postid( $src );
        $resolved_size = 'full';
        if ( $attachment_id ) {
            $resolved_size = $this->resolve_attachment_size_name( $attachment_id, 'full', $matches[0] );
            $resolved_image = wp_get_attachment_image_src( $attachment_id, $resolved_size );
            if ( is_array( $resolved_image ) && ! empty( $resolved_image[0] ) ) {
                $src = $resolved_image[0];
            }
        }

        $srcset = $this->extract_attribute_value( $matches[0], 'srcset' );
        if ( empty( $srcset ) && $attachment_id ) {
            $srcset = $this->get_attachment_srcset( $attachment_id, $resolved_size );
            $sizes  = $this->get_attachment_sizes( $attachment_id, $resolved_size );
        } else {
            $sizes = $this->extract_attribute_value( $matches[0], 'sizes' );
            if ( empty( $sizes ) && $attachment_id ) {
                $sizes = $this->get_attachment_sizes( $attachment_id, $resolved_size );
            }
        }

        if ( $attachment_id ) {
            $calculated_srcset = $this->calculate_attachment_srcset( $attachment_id, $resolved_size, $src );
            if ( ! empty( $calculated_srcset ) ) {
                $srcset = $calculated_srcset;
            }
        }

        if ( ! empty( $srcset ) ) {
            $this->bump_frontend_debug_stat( 'srcset_generated' );
        } else {
            $this->bump_frontend_debug_stat( 'srcset_missing' );
        }

        if ( empty( $sizes ) ) {
            $sizes = $this->infer_image_sizes_from_html( $matches[0] );
        }

        if ( $attachment_id ) {
            $sizes = $this->calculate_attachment_sizes( $attachment_id, $resolved_size, $src, $sizes, $matches[0] );
        }

        $webp_srcset = '';
        if ( ! empty( $srcset ) ) {
            $webp_srcset = $this->build_webp_srcset_from_srcset( $srcset, $src, $attachment_id, $resolved_size );
        }

        if ( empty( $webp_srcset ) && ! empty( $srcset ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                \wp_fastlayer_debug_log( '[WP FastLayer WebP] skipped_webp_source_creation | attachment=' . absint( $attachment_id ) . ' | srcset_count=' . count( array_filter( array_map( 'trim', explode( ',', $srcset ) ) ) ) );
            }

            $img_tag = '<img ' . $attrs . 'src=' . $quote . esc_url( $src ) . $quote . $rest . '>';
            $img_tag = $this->add_srcset_and_sizes_to_image_tag( $img_tag, $srcset, $sizes );
            $img_tag = $this->set_image_attribute( $img_tag, 'data-wp-fastlayer-optimized', '1' );
            $picture = '<picture data-wp-fastlayer-picture="1">' . $img_tag . '</picture>';
            return $this->add_progressive_placeholder_to_picture( $picture, $src );
        }

        if ( empty( $webp_srcset ) ) {
            $path = $this->url_to_path( $src );
            if ( ! $path ) {
                $this->bump_frontend_debug_stat( 'replacement_failure' );
                return $matches[0];
            }

            $webp_url = $this->ensure_webp_for_url( $src );
            if ( ! $webp_url ) {
                $this->bump_frontend_debug_stat( 'replacement_failure' );
                $this->bump_frontend_debug_stat( 'skipped_missing_webp' );
                return $matches[0];
            }

            // Prefer generating a responsive WebP srcset from attachment metadata.
            $responsive_webp = '';
            if ( ! empty( $attachment_id ) ) {
                $responsive_webp = $this->build_webp_srcset_from_attachment( $attachment_id, $src );
            }
            if ( ! empty( $responsive_webp ) ) {
                $webp_srcset = $responsive_webp;
            } else {
                $webp_srcset = esc_url( $webp_url );
            }
        }

        $source = '<source type="image/webp" srcset="' . esc_attr( $webp_srcset ) . '"';
        if ( ! empty( $sizes ) ) {
            $source .= ' sizes="' . esc_attr( $sizes ) . '"';
        }
        $source .= '>';

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            \wp_fastlayer_debug_log( '[WP FastLayer WebP] webp_source_output | attachment=' . absint( $attachment_id ) . ' | srcset=' . substr( $webp_srcset, 0, 200 ) . ' | sizes=' . substr( $sizes, 0, 200 ) );
        }

        $img_tag = '<img ' . $attrs . 'src=' . $quote . esc_url( $src ) . $quote . $rest . '>';
        $img_tag = $this->add_srcset_and_sizes_to_image_tag( $img_tag, $srcset, $sizes );
        $img_tag = $this->set_image_attribute( $img_tag, 'data-wp-fastlayer-optimized', '1' );

        $this->bump_frontend_debug_stat( 'converted_to_picture' );
        $this->bump_frontend_debug_stat( 'replacement_success' );
        $this->debug_log_frontend(
            'Image converted to picture',
            array(
                'mode'          => 'standard',
                'attachment_id' => absint( $attachment_id ),
                'has_srcset'    => ! empty( $srcset ) ? 'yes' : 'no',
                'has_webp'      => ! empty( $webp_srcset ) ? 'yes' : 'no',
            )
        );

        $picture = '<picture data-wp-fastlayer-picture="1">' . $source . $img_tag . '</picture>';
        return $this->add_progressive_placeholder_to_picture( $picture, $src );
    }

    private function replace_data_src_image_tag_with_picture( $matches ) {
        $attrs    = $matches[1];
        $src_attr = $matches[2];
        $quote    = $matches[3];
        $src      = $matches[4];
        $rest     = $matches[5];

        if ( false !== stripos( $matches[0], 'elementor' ) ) {
            $this->bump_frontend_debug_stat( 'elementor_detected' );
        }

        if ( ! $this->is_internal_url( $src ) ) {
            $this->bump_frontend_debug_stat( 'skipped_lazyload' );
            $this->bump_frontend_debug_stat( 'replacement_failure' );
            return $matches[0];
        }

        $attachment_id = attachment_url_to_postid( $src );
        $resolved_size = 'full';
        if ( $attachment_id ) {
            $resolved_size = $this->resolve_attachment_size_name( $attachment_id, 'full', $matches[0] );
            $resolved_image = wp_get_attachment_image_src( $attachment_id, $resolved_size );
            if ( is_array( $resolved_image ) && ! empty( $resolved_image[0] ) ) {
                $src = $resolved_image[0];
            }
        }

        $srcset = $this->extract_attribute_value( $matches[0], 'data-srcset' );
        if ( empty( $srcset ) ) {
            $srcset = $this->extract_attribute_value( $matches[0], 'data-lazy-srcset' );
        }

        if ( empty( $srcset ) ) {
            if ( $attachment_id ) {
                $srcset = wp_get_attachment_image_srcset( $attachment_id, $resolved_size );
                $sizes  = wp_get_attachment_image_sizes( $attachment_id, $resolved_size );
            }
        } else {
            $sizes = $this->extract_attribute_value( $matches[0], 'data-sizes' );
            if ( empty( $sizes ) ) {
                $sizes = $this->extract_attribute_value( $matches[0], 'data-lazy-sizes' );
            }
            if ( empty( $sizes ) ) {
                if ( $attachment_id ) {
                    $sizes = wp_get_attachment_image_sizes( $attachment_id, $resolved_size );
                }
            }
        }

        if ( $attachment_id ) {
            $calculated_srcset = $this->calculate_attachment_srcset( $attachment_id, $resolved_size, $src );
            if ( ! empty( $calculated_srcset ) ) {
                $srcset = $calculated_srcset;
            }
        }

        if ( ! empty( $srcset ) ) {
            $this->bump_frontend_debug_stat( 'srcset_generated' );
        } else {
            $this->bump_frontend_debug_stat( 'srcset_missing' );
        }

        if ( empty( $sizes ) ) {
            $sizes = $this->infer_image_sizes_from_html( $matches[0] );
        }

        if ( $attachment_id ) {
            $sizes = $this->calculate_attachment_sizes( $attachment_id, $resolved_size, $src, $sizes, $matches[0] );
        }

        $webp_srcset = '';
        if ( ! empty( $srcset ) ) {
            $webp_srcset = $this->build_webp_srcset_from_srcset( $srcset, $src, $attachment_id, $resolved_size );
        }

        if ( empty( $webp_srcset ) && ! empty( $srcset ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                \wp_fastlayer_debug_log( '[WP FastLayer WebP] skipped_webp_source_creation | attachment=' . absint( $attachment_id ) . ' | srcset_count=' . count( array_filter( array_map( 'trim', explode( ',', $srcset ) ) ) ) );
            }

            $img_tag = '<img ' . $attrs . ' ' . $src_attr . '=' . $quote . esc_url( $src ) . $quote . $rest . '>';
            $img_tag = $this->add_srcset_and_sizes_to_image_tag( $img_tag, $srcset, $sizes );
            $img_tag = $this->set_image_attribute( $img_tag, 'data-wp-fastlayer-optimized', '1' );
            $picture = '<picture data-wp-fastlayer-picture="1">' . $img_tag . '</picture>';
            return $this->add_progressive_placeholder_to_picture( $picture, $src );
        }

        if ( empty( $webp_srcset ) ) {
            $webp_url = $this->ensure_webp_for_url( $src );
            if ( ! $webp_url ) {
                $this->bump_frontend_debug_stat( 'skipped_lazyload' );
                $this->bump_frontend_debug_stat( 'replacement_failure' );
                $this->bump_frontend_debug_stat( 'skipped_missing_webp' );
                return $matches[0];
            }
            // Prefer generating a responsive WebP srcset using attachment metadata.
            $responsive_webp = '';
            if ( ! empty( $attachment_id ) ) {
                $responsive_webp = $this->build_webp_srcset_from_attachment( $attachment_id, $src );
            }
            if ( ! empty( $responsive_webp ) ) {
                $webp_srcset = $responsive_webp;
            } else {
                $webp_srcset = esc_url( $webp_url );
            }
        }

        $source = '<source type="image/webp" srcset="' . esc_attr( $webp_srcset ) . '"';
        if ( ! empty( $sizes ) ) {
            $source .= ' sizes="' . esc_attr( $sizes ) . '"';
        }
        $source .= '>';

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            \wp_fastlayer_debug_log( '[WP FastLayer WebP] webp_source_output | attachment=' . absint( $attachment_id ) . ' | srcset=' . substr( $webp_srcset, 0, 200 ) . ' | sizes=' . substr( $sizes, 0, 200 ) );
        }

        $img_tag = '<img ' . $attrs . ' ' . $src_attr . '=' . $quote . esc_url( $src ) . $quote . $rest . '>';
        $img_tag = $this->set_image_attribute( $img_tag, 'src', $src );
        $img_tag = $this->add_data_srcset_and_sizes_to_image_tag( $img_tag, $srcset, $sizes );
        $img_tag = $this->set_image_attribute( $img_tag, 'data-wp-fastlayer-optimized', '1' );

        $this->bump_frontend_debug_stat( 'converted_to_picture' );
        $this->bump_frontend_debug_stat( 'replacement_success' );
        $this->debug_log_frontend(
            'Image converted to picture',
            array(
                'mode'          => 'lazy',
                'attachment_id' => absint( $attachment_id ),
                'src_attr'      => $src_attr,
                'has_srcset'    => ! empty( $srcset ) ? 'yes' : 'no',
                'has_webp'      => ! empty( $webp_srcset ) ? 'yes' : 'no',
            )
        );

        $picture = '<picture data-wp-fastlayer-picture="1">' . $source . $img_tag . '</picture>';
        return $this->add_progressive_placeholder_to_picture( $picture, $src );
    }

    public function cleanup_nested_picture_structures( $content ) {
        if ( $this->should_bypass_local_webp() || ! $this->is_enabled() || ! $this->is_image_delivery_enabled() || is_admin() || empty( $content ) ) {
            if ( $this->should_bypass_local_webp() ) {
                \wp_fastlayer_debug_log( '[WP FastLayer WebP] cleanup_nested_picture_structures_bypassed' );
            }
            return $content;
        }

        // Pattern to match nested picture structures: <picture...><source...><picture...>...</picture></picture>
        $replaced = preg_replace_callback(
            '/<picture\b([^>]*)>\s*(<source[^>]*>(?:\s*<source[^>]*>)*)\s*<picture\b[^>]*>(.*?)<\/picture>\s*<\/picture>/is',
            function ( $matches ) {
                $outer_attrs = $matches[1];
                $sources = $matches[2];
                $inner_content = $matches[3];

                // Extract img tag and data attributes from inner content
                if ( preg_match( '/<img\b([^>]*)>/i', $inner_content, $img_matches ) ) {
                    $img_tag = $img_matches[0];
                } else {
                    // If no img tag found, return original (shouldn't happen)
                    return $matches[0];
                }

                // Rebuild as single picture wrapper with outer attributes, sources, and inner img
                return '<picture' . $outer_attrs . '>' . $sources . $img_tag . '</picture>';
            },
            $content
        );

        if ( null === $replaced ) {
            return $content;
        }

        return $replaced;
    }

    private function convert_attachment_to_webp( $attachment_id ) {
        if ( $this->should_bypass_local_webp() ) {
            \wp_fastlayer_debug_log( '[WP FastLayer WebP] convert_attachment_to_webp_bypassed | attachment_id=' . absint( $attachment_id ) );
            return array(
                'converted' => false,
            );
        }

        $converted = false;

        if ( function_exists( 'wp_update_image_subsizes' ) ) {
            wp_update_image_subsizes( $attachment_id );
        }

        $file = get_attached_file( $attachment_id );
        if ( $file && $this->is_supported_image( $file ) && file_exists( $file ) ) {
            if ( $this->generate_webp_for_file( $file ) ) {
                $converted = true;
            }
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) && ! empty( $file ) ) {
            $base_dir = dirname( $file );
            foreach ( $metadata['sizes'] as $size_data ) {
                if ( empty( $size_data['file'] ) ) {
                    continue;
                }

                $size_file = path_join( $base_dir, $size_data['file'] );
                if ( file_exists( $size_file ) && $this->is_supported_image( $size_file ) ) {
                    if ( $this->generate_webp_for_file( $size_file ) ) {
                        $converted = true;
                    }
                }
            }
        }

        return array(
            'converted' => $converted,
        );
    }

    private function build_webp_srcset_from_srcset( $srcset, $base_url = '', $attachment_id = 0, $resolved_size = '' ) {
        $sources = array_map( 'trim', explode( ',', (string) $srcset ) );
        $webp_entries = array();

        // If srcset is too small, attempt to derive a full responsive srcset from WP metadata.
        if ( count( $sources ) <= 1 && ! empty( $base_url ) ) {
            $attachment_id = $attachment_id ?: attachment_url_to_postid( $base_url );
            if ( $attachment_id ) {
                $resolved_size = $resolved_size ?: 'full';
                $fallback_srcset = wp_get_attachment_image_srcset( $attachment_id, $resolved_size );
                if ( ! empty( $fallback_srcset ) ) {
                    $sources = array_map( 'trim', explode( ',', (string) $fallback_srcset ) );
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        \wp_fastlayer_debug_log( '[WP FastLayer WebP] srcset_fallback_to_native | attachment=' . absint( $attachment_id ) . ' | candidate_count=' . count( $sources ) );
                    }
                }
            }
        }

        foreach ( $sources as $source ) {
            if ( '' === $source ) {
                continue;
            }

            $parts = preg_split( '/\s+/', $source, 2 );
            if ( empty( $parts[0] ) ) {
                continue;
            }

            $url = $this->normalize_srcset_candidate_url( $parts[0], $base_url );
            $descriptor = isset( $parts[1] ) ? trim( $parts[1] ) : '';

            if ( ! $this->is_internal_url( $url ) ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    \wp_fastlayer_debug_log( '[WP FastLayer WebP] skipped_external_candidate | url=' . esc_url_raw( $url ) );
                }
                continue;
            }

            $webp_url = $this->ensure_webp_for_url( $url );
            if ( ! $webp_url ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    \wp_fastlayer_debug_log( '[WP FastLayer WebP] skipped_missing_webp_variant | url=' . esc_url_raw( $url ) );
                }
                continue;
            }

            $entry = esc_url( $webp_url );
            if ( '' !== $descriptor ) {
                $entry .= ' ' . $descriptor;
            }

            $webp_entries[] = $entry;
        }

        if ( ! empty( $webp_entries ) ) {
            $webp_entries = array_unique( $webp_entries );
        }

        if ( empty( $webp_entries ) && ! empty( $attachment_id ) ) {
            $fallback_srcset = wp_get_attachment_image_srcset( $attachment_id, $resolved_size ?: 'full' );
            if ( ! empty( $fallback_srcset ) && $fallback_srcset !== $srcset ) {
                $fallback_sources = array_map( 'trim', explode( ',', (string) $fallback_srcset ) );
                foreach ( $fallback_sources as $source ) {
                    if ( '' === $source ) {
                        continue;
                    }

                    $parts = preg_split( '/\s+/', $source, 2 );
                    if ( empty( $parts[0] ) ) {
                        continue;
                    }

                    $url = $this->normalize_srcset_candidate_url( $parts[0], $base_url );
                    $descriptor = isset( $parts[1] ) ? trim( $parts[1] ) : '';

                    if ( ! $this->is_internal_url( $url ) ) {
                        continue;
                    }

                    $webp_url = $this->ensure_webp_for_url( $url );
                    if ( ! $webp_url ) {
                        continue;
                    }

                    $entry = esc_url( $webp_url );
                    if ( '' !== $descriptor ) {
                        $entry .= ' ' . $descriptor;
                    }

                    $webp_entries[] = $entry;
                }

                if ( ! empty( $webp_entries ) ) {
                    $webp_entries = array_unique( $webp_entries );
                }
            }
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            \wp_fastlayer_debug_log( '[WP FastLayer WebP] webp_srcset_generated | input=' . count( $sources ) . ' | output=' . count( $webp_entries ) . ' | base_url=' . substr( $base_url, -60 ) );
        }

        $this->debug_log_frontend(
            'WebP srcset build status',
            array(
                'input_sources'  => count( $sources ),
                'output_sources' => count( $webp_entries ),
                'base_url'       => $base_url,
            )
        );

        return implode( ', ', $webp_entries );
    }

    /**
     * Build a responsive WebP srcset from attachment metadata sizes.
     * Returns empty string when none could be generated.
     */
    private function build_webp_srcset_from_attachment( $attachment_id, $base_url = '' ) {
        if ( empty( $attachment_id ) ) {
            return '';
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( empty( $metadata ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
            return '';
        }

        $base_dir_url = trailingslashit( dirname( wp_get_attachment_url( $attachment_id ) ) );
        $entries = array();

        foreach ( $metadata['sizes'] as $size_name => $size_data ) {
            if ( empty( $size_data['file'] ) || empty( $size_data['width'] ) ) {
                continue;
            }

            $candidate_url = $base_dir_url . $size_data['file'];
            if ( ! $this->is_internal_url( $candidate_url ) ) {
                continue;
            }

            $webp_url = $this->ensure_webp_for_url( $candidate_url );
            if ( ! $webp_url ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    \wp_fastlayer_debug_log( '[WP FastLayer WebP] missing_webp_variant | candidate=' . substr( $candidate_url, -120 ) );
                }
                continue;
            }

            $entries[] = array( 'url' => esc_url( $webp_url ), 'width' => absint( $size_data['width'] ) );
        }

        // Include the full-size as a candidate at the end if available
        $full_url = wp_get_attachment_url( $attachment_id );
        if ( $full_url && ! empty( $metadata['width'] ) ) {
            $full_webp = $this->ensure_webp_for_url( $full_url );
            if ( $full_webp ) {
                $entries[] = array( 'url' => esc_url( $full_webp ), 'width' => absint( $metadata['width'] ) );
            }
        }

        if ( empty( $entries ) ) {
            return '';
        }

        // Sort by width ascending and dedupe by url+width.
        usort( $entries, function( $a, $b ) {
            return $a['width'] - $b['width'];
        } );

        $parts = array();
        $seen = array();
        foreach ( $entries as $e ) {
            $key = $e['url'] . '|' . $e['width'];
            if ( isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $parts[] = $e['url'] . ' ' . $e['width'] . 'w';
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            \wp_fastlayer_debug_log( '[WP FastLayer WebP] build_webp_srcset_from_attachment | attachment=' . absint( $attachment_id ) . ' | candidates=' . count( $parts ) );
        }

        return implode( ', ', $parts );
    }

    private function normalize_srcset_candidate_url( $candidate_url, $base_url = '' ) {
        $candidate_url = trim( (string) $candidate_url );
        if ( '' === $candidate_url ) {
            return '';
        }

        $parsed = wp_parse_url( $candidate_url );
        if ( ! empty( $parsed['scheme'] ) || ! empty( $parsed['host'] ) ) {
            return $candidate_url;
        }

        if ( 0 === strpos( $candidate_url, '//' ) ) {
            $scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
            if ( empty( $scheme ) ) {
                $scheme = is_ssl() ? 'https' : 'http';
            }

            return $scheme . ':' . $candidate_url;
        }

        if ( '/' === substr( $candidate_url, 0, 1 ) ) {
            return home_url( $candidate_url );
        }

        $base_url = trim( (string) $base_url );
        if ( '' !== $base_url ) {
            $base_parts = wp_parse_url( $base_url );
            if ( ! empty( $base_parts['scheme'] ) && ! empty( $base_parts['host'] ) ) {
                $base_path = isset( $base_parts['path'] ) ? (string) $base_parts['path'] : '/';
                $base_dir  = trailingslashit( dirname( $base_path ) );

                return $base_parts['scheme'] . '://' . $base_parts['host'] . $base_dir . ltrim( $candidate_url, '/' );
            }
        }

        return home_url( '/' . ltrim( $candidate_url, '/' ) );
    }

    private function ensure_webp_for_url( $url ) {
        if ( empty( $url ) || ! $this->is_internal_url( $url ) ) {
            return false;
        }

        $path = $this->url_to_path( $url );
        if ( ! $path || ! $this->is_supported_image( $path ) || ! file_exists( $path ) ) {
            return false;
        }

        $webp_path = $this->get_webp_path( $path );
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            \wp_fastlayer_debug_log( '[WP FastLayer WebP] local_webp_detection | source_url=' . esc_url_raw( $url ) . ' | webp_exists=' . ( file_exists( $webp_path ) ? 'yes' : 'no' ) );
        }

        if ( ! file_exists( $webp_path ) ) {
            $this->bump_frontend_debug_stat( 'webp_missing' );
            $this->generate_webp_for_file( $path );
            if ( $this->is_progressive_image_loading_enabled() && $this->is_lqip_placeholders_enabled() ) {
                $this->generate_lqip_for_file( $path );
            }
            if ( file_exists( $webp_path ) ) {
                $this->bump_frontend_debug_stat( 'webp_generated_on_demand' );
            } else {
                $this->bump_frontend_debug_stat( 'webp_generation_failed' );
            }
        }

        if ( ! file_exists( $webp_path ) ) {
            $this->debug_log_frontend(
                'WebP file missing',
                array(
                    'url'  => esc_url_raw( $url ),
                    'path' => $path,
                )
            );
            return false;
        }

        $this->bump_frontend_debug_stat( 'webp_exists' );

        return $this->get_webp_url( $webp_path );
    }

    private function extract_attribute_value( $html, $attribute ) {
        $pattern = '/\b' . preg_quote( $attribute, '/' ) . '\s*=\s*("|\')(.*?)\1/i';
        if ( preg_match( $pattern, $html, $matches ) ) {
            return html_entity_decode( $matches[2], ENT_QUOTES, get_bloginfo( 'charset' ) );
        }

        return '';
    }

    private function get_webp_quality() {
        $options = get_option( 'wp_fastlayer_options', array() );
        $quality = isset( $options['webp_quality'] ) ? absint( $options['webp_quality'] ) : 80;
        return min( 100, max( 50, $quality ) );
    }

    private function is_progressive_image_loading_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_progressive_image_loading'] ) && '1' === (string) $options['enable_progressive_image_loading'];
    }

    private function is_lqip_placeholders_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_lqip_placeholders'] ) && '1' === (string) $options['enable_lqip_placeholders'];
    }

    private function get_lqip_quality() {
        $options = get_option( 'wp_fastlayer_options', array() );
        $quality = isset( $options['lqip_quality'] ) ? absint( $options['lqip_quality'] ) : 25;
        return min( 50, max( 1, $quality ) );
    }

    private function get_lqip_width() {
        $options = get_option( 'wp_fastlayer_options', array() );
        $width = isset( $options['lqip_width'] ) ? absint( $options['lqip_width'] ) : 40;
        return min( 80, max( 20, $width ) );
    }

    private function get_lqip_blur_intensity() {
        $options = get_option( 'wp_fastlayer_options', array() );
        $blur = isset( $options['lqip_blur_intensity'] ) ? absint( $options['lqip_blur_intensity'] ) : 20;
        return min( 50, max( 0, $blur ) );
    }

    private function get_lqip_fade_duration() {
        $options = get_option( 'wp_fastlayer_options', array() );
        $duration = isset( $options['lqip_fade_duration'] ) ? absint( $options['lqip_fade_duration'] ) : 250;
        return min( 1000, max( 0, $duration ) );
    }

    private function get_lqip_path( $path ) {
        return preg_replace( '/\.(jpe?g|png)$/i', '.lqip.webp', $path );
    }

    private function get_lqip_url( $path ) {
        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit( wp_normalize_path( $upload_dir['basedir'] ) );
        $base_url   = trailingslashit( $upload_dir['baseurl'] );
        $path       = wp_normalize_path( $path );

        if ( strpos( $path, $base_dir ) !== 0 ) {
            return false;
        }

        return $base_url . ltrim( str_replace( $base_dir, '', $path ), '/' );
    }

    private function is_internal_url( $url ) {
        $parsed = wp_parse_url( $url );
        if ( empty( $parsed['host'] ) ) {
            return true;
        }

        $site = wp_parse_url( site_url() );
        return isset( $site['host'] ) && strtolower( $parsed['host'] ) === strtolower( $site['host'] );
    }

    public function maybe_update_webp_rewrite_rules() {
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        if ( ! function_exists( 'insert_with_markers' ) || ! function_exists( 'get_home_path' ) ) {
            return;
        }

        if ( $this->should_bypass_local_webp() || ! $this->is_enabled() || ! $this->server_honours_htaccess() ) {
            // nginx, IIS and other stacks never read .htaccess, so writing the
            // rules would be a no-op that only risks confusing other tools.
            // Removing is guarded too, so a disabled state never leaves an
            // empty marker block behind on a server that ignores the file.
            if ( $this->server_honours_htaccess() && $this->has_webp_rewrite_rules() ) {
                $this->remove_webp_rewrite_rules();
            }
            return;
        }

        $this->add_webp_rewrite_rules();
    }

    /**
     * Whether the FastLayer WebP marker block is currently present.
     *
     * @return bool
     */
    private function has_webp_rewrite_rules() {
        $htaccess_file = trailingslashit( get_home_path() ) . '.htaccess';
        if ( ! file_exists( $htaccess_file ) || ! is_readable( $htaccess_file ) ) {
            return false;
        }

        $contents = file_get_contents( $htaccess_file );
        if ( false === $contents ) {
            return false;
        }

        return false !== strpos( $contents, '# BEGIN WP FastLayer WebP' );
    }

    /**
     * Whether the current server actually processes .htaccess files.
     *
     * Only Apache and LiteSpeed do. This deliberately does not rely on
     * got_mod_rewrite(), which is not available on every request.
     *
     * @return bool
     */
    private function server_honours_htaccess() {
        if ( ! empty( $GLOBALS['is_apache'] ) ) {
            return true;
        }

        $server_software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
        if ( '' !== $server_software && ( false !== strpos( $server_software, 'apache' ) || false !== strpos( $server_software, 'litespeed' ) ) ) {
            return true;
        }

        return false;
    }

    private function add_webp_rewrite_rules() {
        $htaccess_file = trailingslashit( get_home_path() ) . '.htaccess';
        if ( ! file_exists( $htaccess_file ) ) {
            // Not a write failure: there is no .htaccess to extend on this
            // server, so it is only noted rather than reported as an error.
            \wp_fastlayer_debug_log( 'WP FastLayer WebP: no .htaccess file was found, so the WebP rewrite rules were not installed.' );
            return;
        }

        if ( ! is_writable( $htaccess_file ) ) {
            $this->report_htaccess_failure( 'the WebP rewrite rules could not be installed because ' . $htaccess_file . ' is not writable.' );
            return;
        }

        $rules = array(
            '<IfModule mod_mime.c>',
            'AddType image/webp .webp',
            '</IfModule>',
            '<IfModule mod_rewrite.c>',
            'RewriteEngine On',
            'RewriteCond %{HTTP_ACCEPT} image/webp',
            'RewriteCond %{REQUEST_FILENAME} (.+)\.(jpe?g|png)$ [NC]',
            'RewriteCond %1.webp -f',
            'RewriteRule ^(.+)\.(jpe?g|png)$ $1.webp [T=image/webp,E=accept:1,L]',
            '</IfModule>',
            '<IfModule mod_headers.c>',
            // Applied to every negotiated source format, not only to the
            // rewritten response. A cache that stored the JPEG without Vary
            // would otherwise be free to hand it to WebP-capable clients, and
            // a cache that stored it with Vary would still be able to mix the
            // two representations up.
            '<FilesMatch "\.(jpe?g|png)$">',
            'Header append Vary Accept',
            '</FilesMatch>',
            '</IfModule>',
        );

        // insert_with_markers() reports its own failures: it returns false when
        // the file cannot be opened or written, and true once the rules are on
        // disk. The result used to be discarded, which left WebP enabled with no
        // rules and no indication of why.
        if ( ! insert_with_markers( $htaccess_file, 'WP FastLayer WebP', $rules ) ) {
            $this->report_htaccess_failure( 'insert_with_markers() reported a failure while installing the WebP rewrite rules in ' . $htaccess_file . '.' );
        }
    }

    private function remove_webp_rewrite_rules() {
        $htaccess_file = trailingslashit( get_home_path() ) . '.htaccess';
        if ( ! file_exists( $htaccess_file ) ) {
            return;
        }

        if ( ! is_writable( $htaccess_file ) ) {
            $this->report_htaccess_failure( 'the WebP rewrite rules could not be removed because ' . $htaccess_file . ' is not writable. They are still active.' );
            return;
        }

        if ( ! insert_with_markers( $htaccess_file, 'WP FastLayer WebP', array() ) ) {
            $this->report_htaccess_failure( 'insert_with_markers() reported a failure while removing the WebP rewrite rules from ' . $htaccess_file . '. They may still be active.' );
        }
    }

    /**
     * Reports a root .htaccess failure through Bootstrap so it reaches the admin.
     */
    private function report_htaccess_failure( $message ) {
        \wp_fastlayer_debug_log( 'WP FastLayer WebP: .htaccess write failure: ' . $message );

        if ( class_exists( 'WP_FastLayer\\Bootstrap' ) ) {
            \WP_FastLayer\Bootstrap::record_write_failure( '.htaccess', $message );
        }
    }

    /**
     * Serves the generated WebP representation from PHP.
     *
     * The .htaccess rewrite in add_webp_rewrite_rules() is the primary
     * mechanism and runs entirely inside the web server. This is the fallback
     * for the stacks that never reach it: IIS, Apache without mod_rewrite, or
     * an nginx configuration that routes uploads into PHP instead of handing
     * them to the filesystem.
     *
     * It only ever responds when every one of these is true:
     *   - WebP and image delivery are both enabled,
     *   - the request is a front-end uploads request for .jpg, .jpeg or .png,
     *   - that original file exists inside the uploads directory,
     *   - a sibling .webp exists inside the same uploads directory,
     *   - the client advertises image/webp.
     *
     * Anything else falls through untouched, so the original image is served
     * exactly as it was before.
     */
    public function maybe_serve_webp_representation() {
        if ( ! $this->should_serve_webp_representation() ) {
            return;
        }

        $webp_file = $this->resolve_webp_file_for_request();
        if ( false === $webp_file ) {
            return;
        }

        $this->send_webp_file( $webp_file );
    }

    /**
     * Gate for the PHP fallback, evaluated before any filesystem work.
     *
     * @return bool
     */
    private function should_serve_webp_representation() {
        if ( headers_sent() ) {
            return false;
        }

        if ( ! $this->is_enabled() || ! $this->is_image_delivery_enabled() ) {
            return false;
        }

        if ( $this->should_bypass_local_webp() ) {
            return false;
        }

        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return false;
        }

        if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
            return false;
        }

        return $this->client_accepts_webp();
    }

    /**
     * Whether the client asked for image/webp.
     *
     * A bare substring check would also match "image/webp;q=0", which is how a
     * client opts out, so the quality value is parsed before answering.
     *
     * @return bool
     */
    private function client_accepts_webp() {
        if ( empty( $_SERVER['HTTP_ACCEPT'] ) || ! is_string( $_SERVER['HTTP_ACCEPT'] ) ) {
            return false;
        }

        $accept = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) );
        if ( '' === $accept ) {
            return false;
        }

        foreach ( explode( ',', $accept ) as $media_range ) {
            $segments    = explode( ';', $media_range );
            $media_type  = strtolower( trim( array_shift( $segments ) ) );
            if ( 'image/webp' !== $media_type ) {
                continue;
            }

            $quality = 1.0;
            foreach ( $segments as $segment ) {
                if ( 0 === stripos( trim( $segment ), 'q=' ) ) {
                    $quality = (float) substr( trim( $segment ), 2 );
                }
            }

            return $quality > 0;
        }

        return false;
    }

    /**
     * Maps the current request to a sibling .webp inside the uploads directory.
     *
     * The path is taken from REQUEST_URI rather than from any query argument,
     * so no user-controlled value can select a file: the request path has to
     * sit under the uploads URL, name a format FastLayer converts from, and
     * resolve to two real files that both stay inside the real uploads
     * directory once symlinks and ".." have been collapsed.
     *
     * @return string|false Absolute path to the WebP file, or false.
     */
    private function resolve_webp_file_for_request() {
        if ( empty( $_SERVER['REQUEST_URI'] ) || ! is_string( $_SERVER['REQUEST_URI'] ) ) {
            return false;
        }

        $request_path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
        if ( ! is_string( $request_path ) || '' === $request_path ) {
            return false;
        }

        $request_path = wp_normalize_path( rawurldecode( $request_path ) );

        // Only the formats FastLayer generates WebP files from are negotiable.
        if ( ! preg_match( '/\.(jpe?g|png)$/i', $request_path ) ) {
            return false;
        }

        if ( false !== strpos( $request_path, "\0" ) ) {
            return false;
        }

        foreach ( explode( '/', $request_path ) as $segment ) {
            if ( '..' === $segment || '.' === $segment ) {
                return false;
            }
        }

        $upload_dir = wp_upload_dir( null, false );
        if ( ! empty( $upload_dir['error'] ) || empty( $upload_dir['basedir'] ) || empty( $upload_dir['baseurl'] ) ) {
            return false;
        }

        $base_dir = realpath( $upload_dir['basedir'] );
        if ( false === $base_dir ) {
            return false;
        }
        $base_dir = wp_normalize_path( untrailingslashit( $base_dir ) );

        $base_path = wp_parse_url( $upload_dir['baseurl'], PHP_URL_PATH );
        if ( ! is_string( $base_path ) || '' === $base_path ) {
            return false;
        }
        $base_path = untrailingslashit( wp_normalize_path( rawurldecode( $base_path ) ) );

        // The trailing slash keeps /wp-content/uploads-private/ out.
        if ( $base_path . '/' !== substr( $request_path, 0, strlen( $base_path ) + 1 ) ) {
            return false;
        }

        $relative_path = substr( $request_path, strlen( $base_path ) + 1 );
        if ( '' === $relative_path ) {
            return false;
        }

        // The original has to exist. A WebP is only ever a representation of a
        // real source image, never something served for an arbitrary path.
        $original_file = $this->confine_to_directory( $base_dir . '/' . $relative_path, $base_dir );
        if ( false === $original_file || ! is_file( $original_file ) ) {
            return false;
        }

        $webp_file = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $original_file );
        if ( ! is_string( $webp_file ) ) {
            return false;
        }

        $webp_file = $this->confine_to_directory( $webp_file, $base_dir );
        if ( false === $webp_file || ! is_file( $webp_file ) || ! is_readable( $webp_file ) ) {
            return false;
        }

        return $webp_file;
    }

    /**
     * Resolves a path and refuses anything that ends up outside $base_dir.
     *
     * @param string $path     Candidate path.
     * @param string $base_dir Resolved, normalised base directory.
     * @return string|false
     */
    private function confine_to_directory( $path, $base_dir ) {
        $resolved = realpath( $path );
        if ( false === $resolved ) {
            return false;
        }

        $resolved = wp_normalize_path( $resolved );

        if ( 0 !== strpos( $resolved, $base_dir . '/' ) ) {
            return false;
        }

        return $resolved;
    }

    /**
     * Writes the WebP response and ends the request.
     *
     * readfile() is used deliberately: it streams the file without handing a
     * filesystem path to a shell, and the request ends here so WordPress never
     * goes on to build a page for an image.
     *
     * @param string $file Absolute path to an existing WebP file.
     */
    private function send_webp_file( $file ) {
        $file_size   = filesize( $file );
        $file_time   = filemtime( $file );
        $method      = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
        $last_modified = false !== $file_time ? gmdate( 'D, d M Y H:i:s', $file_time ) . ' GMT' : '';
        $etag        = '"' . md5( 'fastlayer-webp|' . $file . '|' . $file_size . '|' . $file_time ) . '"';

        if ( $this->request_is_not_modified( $etag, $file_time ) ) {
            if ( ! headers_sent() ) {
                status_header( 304 );
                header( 'Vary: Accept' );
                header( 'ETag: ' . $etag );
                if ( '' !== $last_modified ) {
                    header( 'Last-Modified: ' . $last_modified );
                }
            }
            exit;
        }

        if ( ! headers_sent() ) {
            header( 'Content-Type: image/webp' );
            // Required: the same URL answers with two different byte streams.
            header( 'Vary: Accept' );
            header( 'X-Content-Type-Options: nosniff' );
            if ( '' !== $last_modified ) {
                header( 'Last-Modified: ' . $last_modified );
            }
            header( 'ETag: ' . $etag );
            if ( false !== $file_size ) {
                header( 'Content-Length: ' . $file_size );
            }
            // Matches the lifetime WordPress gives uploads, so a representation
            // and the original it was built from age out together.
            header( 'Cache-Control: public, max-age=2592000' );
        }

        if ( 'HEAD' === $method ) {
            exit;
        }

        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        if ( function_exists( 'fastcgi_finish_request' ) ) {
            fastcgi_finish_request();
        }

        readfile( $file );
        exit;
    }

    /**
     * @param string $etag      ETag for the WebP representation.
     * @param int|false $file_time File modification time.
     * @return bool
     */
    private function request_is_not_modified( $etag, $file_time ) {
        if ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) {
            $if_none_match = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) );
            if ( '' !== $if_none_match && ( '*' === $if_none_match || false !== strpos( $if_none_match, $etag ) ) ) {
                return true;
            }
        }

        if ( false !== $file_time && isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) {
            $since = strtotime( (string) wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) );
            if ( false !== $since && $file_time <= $since ) {
                return true;
            }
        }

        return false;
    }

    private function url_to_path( $url ) {
        $url = esc_url_raw( $url );
        $parsed_url = wp_parse_url( $url );
        if ( empty( $parsed_url['path'] ) ) {
            return false;
        }

        $request_path = rawurldecode( $parsed_url['path'] );
        $upload_dir = wp_upload_dir();
        $base_url   = trailingslashit( $upload_dir['baseurl'] );
        $base_dir   = trailingslashit( wp_normalize_path( $upload_dir['basedir'] ) );

        $upload_path = wp_parse_url( $base_url, PHP_URL_PATH );
        if ( ! empty( $upload_path ) ) {
            $upload_path = untrailingslashit( rawurldecode( $upload_path ) );
            if ( 0 === strpos( $request_path, $upload_path ) ) {
                $relative = ltrim( substr( $request_path, strlen( $upload_path ) ), '/' );
                return wp_normalize_path( $base_dir . $relative );
            }
        }

        if ( '/' === substr( $request_path, 0, 1 ) ) {
            $abs_path = wp_normalize_path( ABSPATH . ltrim( $request_path, '/' ) );
            if ( file_exists( $abs_path ) ) {
                return $abs_path;
            }
        }

        if ( ! empty( $parsed_url['host'] ) && ! $this->is_internal_url( $url ) ) {
            return false;
        }

        return wp_normalize_path( ABSPATH . ltrim( $request_path, '/' ) );
    }
}
