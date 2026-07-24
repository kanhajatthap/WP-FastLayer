<?php
namespace WP_FastLayer;
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CDN {
    private static $instance = null;
    const IMAGEKIT_FALLBACK_WIDTH = 400;
    private $lcp_candidate = array();
    private $lcp_report_saved = false;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_filter( 'style_loader_src', array( $this, 'rewrite_url' ), 10, 2 );
        add_filter( 'script_loader_src', array( $this, 'rewrite_url' ), 10, 2 );
        add_filter( 'wp_get_attachment_url', array( $this, 'rewrite_url' ) );
        add_filter( 'wp_get_attachment_image_src', array( $this, 'rewrite_image_src' ), 10, 4 );
        add_filter( 'wp_calculate_image_srcset', array( $this, 'rewrite_image_srcset' ), 10, 5 );
        add_filter( 'wp_get_attachment_image_attributes', array( $this, 'rewrite_image_attributes' ), 20, 3 );
        add_filter( 'the_content', array( $this, 'rewrite_html_content_images' ), 1001 );
        add_filter( 'post_thumbnail_html', array( $this, 'rewrite_html_content_images' ), 1001 );
        add_filter( 'widget_text_content', array( $this, 'rewrite_html_content_images' ), 1001 );
        add_filter( 'elementor/frontend/the_content', array( $this, 'rewrite_html_content_images' ), 1001 );
        add_filter( 'woocommerce_single_product_image_html', array( $this, 'rewrite_html_content_images' ), 1001 );
        add_filter( 'woocommerce_single_product_image_thumbnail_html', array( $this, 'rewrite_html_content_images' ), 1001 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_imagekit_runtime_debugger' ), 99 );
        add_action( 'wp_ajax_wp_fastlayer_image_debug_collect', array( $this, 'handle_image_runtime_debug_collect' ) );
        add_action( 'wp_head', array( $this, 'print_smart_lcp_preload_hint' ), 5 );
        add_action( 'wp_footer', array( $this, 'print_smart_lcp_debug_console_log' ), 9999 );
    }

    public function print_smart_lcp_preload_hint() {
        if ( ! $this->is_smart_lcp_enabled() || ! $this->is_lcp_preload_enabled() || empty( $this->lcp_candidate['preload_url'] ) ) {
            return;
        }

        $url = esc_url( (string) $this->lcp_candidate['preload_url'] );
        if ( '' === $url ) {
            return;
        }

        $imagesrcset = '';
        $imagesizes = '';
        if ( ! empty( $this->lcp_candidate['is_img'] ) ) {
            $imagesrcset = ! empty( $this->lcp_candidate['srcset'] ) ? (string) $this->lcp_candidate['srcset'] : '';
            $imagesizes = ! empty( $this->lcp_candidate['sizes'] ) ? (string) $this->lcp_candidate['sizes'] : '';
        }

        echo '<link rel="preload" as="image" href="' . esc_attr( $url ) . '"';
        if ( '' !== $imagesrcset ) {
            echo ' imagesrcset="' . esc_attr( $imagesrcset ) . '"';
        }
        if ( '' !== $imagesizes ) {
            echo ' imagesizes="' . esc_attr( $imagesizes ) . '"';
        }
        echo ' data-wpfl-lcp="1" />' . "\n";

        $this->lcp_candidate['preload_applied'] = true;
        $this->persist_lcp_report();
        $this->debug_lcp_log( 'preload_applied', array( 'preload_url' => $this->lcp_candidate['preload_url'] ) );
    }

    public function print_smart_lcp_debug_console_log() {
        if ( ! $this->is_lcp_debug_enabled() || empty( $this->lcp_candidate ) ) {
            return;
        }

        $payload = wp_json_encode( $this->get_lcp_debug_payload() );
        if ( ! is_string( $payload ) || '' === $payload ) {
            return;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode() is the correct escaping function for inline JSON.
        echo '<script>(function(){if(!window.console){return;}console.log("[WP FastLayer LCP]",' . $payload . ');})();</script>' . "\n";
    }

    public function enqueue_imagekit_runtime_debugger() {
        if ( ! $this->should_enqueue_runtime_debugger() ) {
            return;
        }

        wp_enqueue_script(
            'wp-fastlayer-imagekit-runtime-debug',
            WP_FASTLAYER_URL . 'assets/js/imagekit-runtime-debug.js',
            array(),
            WP_FASTLAYER_VERSION,
            true
        );

        wp_localize_script(
            'wp-fastlayer-imagekit-runtime-debug',
            'wpFastLayerImageRuntime',
            array(
                'enabled' => true,
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'wp_fastlayer_image_debug_collect' ),
                'oversizeRatio' => $this->get_imagekit_max_oversize_ratio(),
                'maxOversizeRatio' => $this->get_imagekit_max_oversize_ratio(),
                'runtimeCorrection' => $this->is_imagekit_runtime_correction_enabled(),
                'overlayEnabled' => $this->is_imagekit_runtime_overlay_enabled(),
                'buckets' => $this->get_imagekit_width_buckets(),
                'collectAction' => 'wp_fastlayer_image_debug_collect',
                'collectLimit' => 40,
            )
        );
    }

    public function handle_image_runtime_debug_collect() {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            wp_send_json_error( array( 'message' => 'debug_disabled' ), 403 );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
        }

        check_ajax_referer( 'wp_fastlayer_image_debug_collect', 'nonce' );

        $raw_entries = isset( $_POST['entries'] ) ? wp_unslash( $_POST['entries'] ) : '';
        $decoded = json_decode( (string) $raw_entries, true );
        if ( ! is_array( $decoded ) ) {
            wp_send_json_error( array( 'message' => 'invalid_payload' ), 400 );
        }

        $existing = get_option( 'wp_fastlayer_image_runtime_debug_samples', array() );
        $existing = is_array( $existing ) ? $existing : array();
        $captured = array();

        foreach ( $decoded as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $rendered_width = isset( $entry['renderedWidth'] ) ? absint( $entry['renderedWidth'] ) : 0;
            $requested_width = isset( $entry['requestedWidth'] ) ? absint( $entry['requestedWidth'] ) : 0;
            $oversize_ratio = isset( $entry['oversizeRatio'] ) ? (float) $entry['oversizeRatio'] : 0.0;
            $image_url = isset( $entry['imageUrl'] ) ? esc_url_raw( (string) $entry['imageUrl'] ) : '';

            if ( $rendered_width <= 0 || $requested_width <= 0 || '' === $image_url ) {
                continue;
            }

            $row = array(
                'timestamp' => time(),
                'pageUrl' => isset( $entry['pageUrl'] ) ? esc_url_raw( (string) $entry['pageUrl'] ) : '',
                'imageUrl' => $image_url,
                'renderedWidth' => $rendered_width,
                'requestedWidth' => $requested_width,
                'oversizeRatio' => round( $oversize_ratio, 3 ),
                'srcsetWidths' => isset( $entry['srcsetWidths'] ) && is_array( $entry['srcsetWidths'] ) ? array_values( array_filter( array_map( 'absint', $entry['srcsetWidths'] ) ) ) : array(),
                'mobileViewportResult' => isset( $entry['mobileViewportResult'] ) ? absint( $entry['mobileViewportResult'] ) : 0,
                'desktopViewportResult' => isset( $entry['desktopViewportResult'] ) ? absint( $entry['desktopViewportResult'] ) : 0,
                'runtimeCorrectionApplied' => ! empty( $entry['runtimeCorrectionApplied'] ),
            );

            $captured[] = $row;
            $this->debug_log(
                'detected_rendered_width',
                array(
                    'detected_rendered_width' => $row['renderedWidth'],
                    'requested_transform_width' => $row['requestedWidth'],
                    'oversize_ratio' => $row['oversizeRatio'],
                    'runtime_width_correction_applied' => $row['runtimeCorrectionApplied'],
                )
            );
        }

        if ( empty( $captured ) ) {
            wp_send_json_success( array( 'saved' => 0 ) );
        }

        $merged = array_merge( $existing, $captured );
        if ( count( $merged ) > 250 ) {
            $merged = array_slice( $merged, -250 );
        }

        update_option( 'wp_fastlayer_image_runtime_debug_samples', $merged, false );
        wp_send_json_success( array( 'saved' => count( $captured ) ) );
    }

    public function rewrite_url( $url ) {
        if ( $this->is_imagekit_enabled() && $this->is_imagekit_url( $url ) ) {
            $this->debug_log( 'imagekit_skip', array( 'reason' => 'already_imagekit_url', 'original_url' => $url ) );
            return $url;
        }

        if ( $this->is_imagekit_enabled() && $this->is_local_image_url( $url ) ) {
            return $this->rewrite_to_imagekit( $url );
        }

        if ( ! $this->is_enabled() ) {
            return $url;
        }

        return $this->rewrite_internal_url( $url );
    }

    public function rewrite_image_src( $image, $attachment_id, $size, $icon ) {
        if ( ! $this->is_enabled() || empty( $image[0] ) ) {
            if ( ! $this->is_imagekit_enabled() || empty( $image[0] ) ) {
                return $image;
            }

            $image[0] = $this->rewrite_to_imagekit( $image[0] );
            return $image;
        }

        if ( $this->is_imagekit_enabled() ) {
            $image[0] = $this->rewrite_to_imagekit( $image[0] );
            return $image;
        }

        $image[0] = $this->rewrite_internal_url( $image[0] );
        return $image;
    }

    public function rewrite_image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
        if ( ! $this->is_imagekit_enabled() || ! is_array( $sources ) ) {
            return $sources;
        }

        foreach ( $sources as $width => &$source ) {
            if ( empty( $source['url'] ) ) {
                continue;
            }

            $source['url'] = $this->rewrite_to_imagekit( $source['url'], is_numeric( $width ) ? (int) $width : 0 );
        }

        unset( $source );

        return $sources;
    }

    public function rewrite_image_attributes( $attr, $attachment, $size ) {
        if ( ! $this->should_process_frontend_rewrite() || ! is_array( $attr ) ) {
            return $attr;
        }

        $this->debug_log( 'hook_wp_get_attachment_image_attributes', array( 'attachment_id' => is_object( $attachment ) ? (int) $attachment->ID : 0 ) );

        if ( $this->is_imagekit_enabled() ) {
            // Detect display width from width attribute, sizes, inline style, or attachment fallback.
            $display_width = $this->detect_display_width_from_image_attributes( $attr, $attachment, $size );

            foreach ( array( 'src', 'data-src', 'data-lazy-src', 'poster' ) as $key ) {
                if ( empty( $attr[ $key ] ) || ! is_string( $attr[ $key ] ) ) {
                    continue;
                }

                $rewrite_width = ( 'poster' === $key ) ? 0 : $display_width;
                $rewritten = $this->rewrite_to_imagekit( $attr[ $key ], $rewrite_width );
                if ( $rewritten !== $attr[ $key ] ) {
                    $this->debug_log(
                        'attr_url_rewritten',
                        array(
                            'attribute'              => $key,
                            'original_url'           => $attr[ $key ],
                            'generated_transform_width' => $rewrite_width,
                            'rewritten_url'          => $rewritten,
                        )
                    );
                    $attr[ $key ] = $rewritten;
                }
            }

            foreach ( array( 'srcset', 'data-srcset', 'data-lazy-srcset' ) as $key ) {
                if ( empty( $attr[ $key ] ) || ! is_string( $attr[ $key ] ) ) {
                    continue;
                }

                $attr[ $key ] = $this->rewrite_srcset_string( $attr[ $key ] );
            }

            if ( ! empty( $attr['style'] ) && is_string( $attr['style'] ) ) {
                $attr['style'] = $this->rewrite_inline_style_urls( $attr['style'] );
            }
        }

        if ( $this->is_smart_lcp_enabled() && empty( $this->lcp_candidate ) ) {
            $probe = $this->build_lcp_probe_from_img_attributes( $attr, 'wp_attachment_image', 0 );
            if ( $this->is_valid_lcp_probe( $probe ) ) {
                $attr = $this->apply_lcp_image_attributes( $attr );
                $this->set_lcp_candidate( $probe, 'wp_attachment_image' );
            }
        }

        return $attr;
    }

    public function rewrite_html_content_images( $html ) {
        if ( ! is_string( $html ) || '' === $html ) {
            return $html;
        }

        if ( ! $this->should_process_frontend_rewrite() ) {
            $this->debug_log( 'hook_html_rewrite_skipped', array( 'reason' => 'frontend_guard', 'hook' => current_filter() ) );
            return $html;
        }

        if ( ! $this->is_imagekit_enabled() && ! $this->is_smart_lcp_enabled() ) {
            $this->debug_log( 'hook_html_rewrite_skipped', array( 'reason' => 'imagekit_disabled', 'hook' => current_filter() ) );
            return $html;
        }

        $this->debug_log( 'hook_html_rewrite_executing', array( 'hook' => current_filter() ) );

        if ( $this->is_imagekit_enabled() ) {
            // First pass: rewrite srcset entries (widths from w-descriptors).
            $html = preg_replace_callback(
                '#(?<attr>\b(?:srcset|data-srcset|data-lazy-srcset)\s*=\s*["\"])(?<srcset>[^"\"]+)(?<end>["\"])#i',
                function ( $matches ) {
                    $srcset    = isset( $matches['srcset'] ) ? $matches['srcset'] : '';
                    $rewritten = $this->rewrite_srcset_string( $srcset );
                    return $matches['attr'] . $rewritten . $matches['end'];
                },
                $html
            );

            // Second pass: rewrite src/data-src with width detected from sibling attributes in the same img tag.
            $rewritten_html = preg_replace_callback(
                '#<img(?<inner>[^>]+)>#i',
                function ( $img_matches ) {
                    $inner = $img_matches['inner'];

                    $display_width = $this->detect_display_width_from_img_tag( $inner );

                    $new_inner = preg_replace_callback(
                        '#(?<attr>\b(?:src|data-src|data-lazy-src)\s*=\s*["\'])(?<url>[^"\']+)(?<end>["\'])#i',
                        function ( $attr_matches ) use ( $display_width ) {
                            $original  = isset( $attr_matches['url'] ) ? $attr_matches['url'] : '';
                            $rewritten = $this->rewrite_to_imagekit( $original, $display_width );

                            if ( $rewritten !== $original ) {
                                $this->debug_log(
                                    'html_attr_url_rewritten',
                                    array(
                                        'original_url'             => $original,
                                        'generated_transform_width' => $display_width,
                                        'rewritten_url'            => $rewritten,
                                    )
                                );
                            }

                            return $attr_matches['attr'] . $rewritten . $attr_matches['end'];
                        },
                        $inner
                    );

                    return '<img' . ( is_string( $new_inner ) ? $new_inner : $inner ) . '>';
                },
                $html
            );

            if ( is_string( $rewritten_html ) ) {
                $html = $rewritten_html;
            }

            $html = $this->rewrite_inline_style_urls( $html );
        }

        if ( $this->is_smart_lcp_enabled() ) {
            $html = $this->apply_smart_lcp_optimization_to_html( $html );
        }

        return $html;
    }

    private function is_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_cdn'] ) && '1' === $options['enable_cdn'] && ! empty( $options['cdn_cname'] );
    }

    private function is_imagekit_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );

        return isset( $options['enable_imagekit_cdn'] )
            && '1' === (string) $options['enable_imagekit_cdn']
            && ! empty( $options['imagekit_endpoint'] );
    }

    private function get_cdn_base_url() {
        $options = get_option( 'wp_fastlayer_options', array() );
        $cdn = isset( $options['cdn_cname'] ) ? trim( $options['cdn_cname'] ) : '';
        if ( empty( $cdn ) ) {
            return '';
        }

        if ( strpos( $cdn, 'http://' ) !== 0 && strpos( $cdn, 'https://' ) !== 0 ) {
            $cdn = 'https://' . $cdn;
        }

        return untrailingslashit( $cdn );
    }

    private function rewrite_internal_url( $url ) {
        if ( empty( $url ) ) {
            return $url;
        }

        $cdn_base = $this->get_cdn_base_url();
        if ( empty( $cdn_base ) ) {
            return $url;
        }

        $parsed = wp_parse_url( $url );
        $site    = wp_parse_url( site_url() );
        if ( empty( $parsed['host'] ) || empty( $site['host'] ) || strtolower( $parsed['host'] ) !== strtolower( $site['host'] ) ) {
            return $url;
        }

        $path = isset( $parsed['path'] ) ? $parsed['path'] : '';
        if ( empty( $path ) ) {
            return $url;
        }

        $query = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';
        return $cdn_base . $path . $query;
    }

    private function rewrite_to_imagekit( $url, $width = 0, $is_background_image = false ) {
        return $this->build_imagekit_transform_url( $url, $width, $is_background_image );
    }

    private function build_imagekit_transform_url( $url, $width = 0, $is_background_image = false ) {
        if ( empty( $url ) || ! is_string( $url ) ) {
            return $url;
        }

        if ( $this->is_imagekit_url( $url ) ) {
            $this->debug_log( 'imagekit_skip', array( 'reason' => 'already_imagekit_url', 'original_url' => $url ) );
            return $url;
        }

        if ( ! $this->is_local_image_url( $url ) ) {
            $this->debug_log( 'imagekit_skip', array( 'reason' => 'empty_or_not_local_image', 'original_url' => $url ) );
            return $url;
        }

        $options = get_option( 'wp_fastlayer_options', array() );
        $endpoint = isset( $options['imagekit_endpoint'] ) ? trim( (string) $options['imagekit_endpoint'] ) : '';

        if ( '' === $endpoint ) {
            $this->debug_log( 'imagekit_skip', array( 'reason' => 'missing_endpoint', 'original_url' => $url ) );
            return $url;
        }

        if ( strpos( $endpoint, 'http://' ) !== 0 && strpos( $endpoint, 'https://' ) !== 0 ) {
            $endpoint = 'https://' . ltrim( $endpoint, '/' );
        }

        $endpoint = untrailingslashit( $endpoint );

        $parsed = wp_parse_url( $url );
        if ( empty( $parsed['path'] ) ) {
            $this->debug_log( 'imagekit_skip', array( 'reason' => 'missing_path', 'original_url' => $url ) );
            return $url;
        }

        $query_args = array();
        if ( ! empty( $parsed['query'] ) ) {
            wp_parse_str( $parsed['query'], $query_args );
        }

        $uploads = wp_upload_dir();
        $uploads_path = wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
        $uploads_path = is_string( $uploads_path ) ? untrailingslashit( $uploads_path ) : '';

        if ( '' === $uploads_path || 0 !== strpos( $parsed['path'], $uploads_path ) ) {
            $this->debug_log(
                'imagekit_skip',
                array(
                    'reason' => 'uploads_path_mismatch',
                    'original_url' => $url,
                    'url_path' => isset( $parsed['path'] ) ? $parsed['path'] : '',
                    'uploads_path' => $uploads_path,
                )
            );
            return $url;
        }

        $normalized_path = preg_replace( '#/+#', '/', (string) $parsed['path'] );
        $normalized_path = is_string( $normalized_path ) ? $normalized_path : (string) $parsed['path'];
        $normalized_path = '/' . ltrim( $normalized_path, '/' );
        $relative_uploads_path = ltrim( substr( $normalized_path, strlen( $uploads_path ) ), '/' );
        $mode = $this->get_imagekit_origin_path_mode( $options );
        $rewritten_path = 'uploads_relative_path' === $mode ? $relative_uploads_path : ltrim( $normalized_path, '/' );
        $rewritten_path = $this->normalize_imagekit_rewritten_path( $rewritten_path, $mode );
        $rewritten_path = $this->strip_endpoint_prefix_from_rewritten_path( $endpoint, $rewritten_path );

        if ( '' === $rewritten_path ) {
            $this->debug_log( 'imagekit_skip', array( 'reason' => 'empty_rewritten_path', 'original_url' => $url ) );
            return $url;
        }

        $duplicate_segments = $this->detect_duplicate_path_segments( $rewritten_path );

        $existing_transforms = array();
        if ( ! empty( $query_args['tr'] ) && is_string( $query_args['tr'] ) ) {
            $existing_transforms = array_filter( array_map( 'trim', explode( ',', $query_args['tr'] ) ) );
            $this->debug_log( 'detected_existing_transform', array( 'existing_transforms' => $existing_transforms ) );
        }

        unset( $query_args['tr'] );
        $query_args['tr'] = implode( ',', $this->merge_imagekit_transforms( $existing_transforms, $width, $is_background_image ) );

        $endpoint_url = $this->build_imagekit_endpoint_url( $endpoint, $rewritten_path );
        $endpoint_parsed = wp_parse_url( $endpoint_url );
        $new_url = $this->build_url_from_parts( $endpoint_parsed, $query_args );

        $this->log_output(
            array(
                'final_url'             => $new_url,
                'request_accept_header' => $_SERVER['HTTP_ACCEPT'] ?? null,
                'is_imagekit_url'       => false !== strpos( $new_url, 'imagekit.io' ),
                'original_url'          => $url,
                'selected_rewrite_mode' => $mode,
                'normalized_path'       => $normalized_path,
                'final_rewritten_path'  => '/' . $rewritten_path,
                'duplicate_path_segments' => $duplicate_segments,
                'is_background_image'   => $is_background_image,
            )
        );

        return $new_url;
    }

    private function ensure_imagekit_transform_url( $url, $width = 0 ) {
        $parsed = wp_parse_url( $url );
        if ( empty( $parsed['path'] ) || ! preg_match( '/\.(png|jpe?g|gif|webp|avif|svg)$/i', $parsed['path'] ) ) {
            return $url;
        }

        $query_args = array();
        if ( ! empty( $parsed['query'] ) ) {
            wp_parse_str( $parsed['query'], $query_args );
        }

        $existing_transforms = array();
        if ( ! empty( $query_args['tr'] ) && is_string( $query_args['tr'] ) ) {
            $existing_transforms = array_filter( array_map( 'trim', explode( ',', $query_args['tr'] ) ) );
        }

        $query_args['tr'] = implode( ',', $this->merge_imagekit_transforms( $existing_transforms, $width ) );
        $rebuilt = $this->build_url_from_parts( $parsed, $query_args );

        $this->log_output(
            array(
                'final_url'             => $rebuilt,
                'request_accept_header' => $_SERVER['HTTP_ACCEPT'] ?? null,
                'is_imagekit_url'       => false !== strpos( $rebuilt, 'imagekit.io' ),
            )
        );

        return $rebuilt;
    }

    private function merge_imagekit_transforms( $existing_transforms, $width = 0, $is_background_image = false ) {
        $merged = array();
        $format_transform = '';
        $quality_transform = '';
        $existing_width_transform = '';

        foreach ( (array) $existing_transforms as $transform ) {
            $transform = trim( preg_replace( '/\s+/', '', (string) $transform ) );
            if ( '' === $transform ) {
                continue;
            }

            if ( preg_match( '/^f-/i', $transform ) ) {
                if ( '' === $format_transform ) {
                    $format_transform = $transform;
                }
                continue;
            }

            if ( preg_match( '/^q-/i', $transform ) ) {
                if ( '' === $quality_transform ) {
                    $quality_transform = $transform;
                }
                continue;
            }

            if ( preg_match( '/^w-/i', $transform ) ) {
                if ( '' === $existing_width_transform ) {
                    $existing_width_transform = $transform;
                }
                continue;
            }

            $merged[] = $transform;
        }

        if ( '' === $format_transform ) {
            $format_transform = $this->get_imagekit_auto_format_transform();
        }

        if ( '' === $quality_transform ) {
            $quality_transform = 'q-auto';
        }

        if ( '' !== $format_transform ) {
            $merged[] = $format_transform;
        }

        if ( '' !== $quality_transform ) {
            $merged[] = $quality_transform;
        }

        $requested_width = absint( $width );
        $max_oversize_ratio = $this->get_imagekit_max_oversize_ratio();
        $max_allowed_width = $requested_width > 0 ? max( $requested_width, (int) floor( $requested_width * $max_oversize_ratio ) ) : 0;
        $normalized_width = $requested_width > 0 ? $this->normalize_imagekit_width( $requested_width, $max_allowed_width ) : self::IMAGEKIT_FALLBACK_WIDTH;
        $fallback_width_used = $requested_width <= 0 && '' === $existing_width_transform;
        $oversized_width_prevented = false;
        $skip_oversize_ratio_cap = false;
        $skip_runtime_correction = false;

        if ( $is_background_image ) {
            $background_min_width = $this->get_background_transform_min_width();
            $normalized_width = max( $background_min_width, $requested_width );

            if ( '' !== $existing_width_transform && preg_match( '/^w-(\d+)$/i', $existing_width_transform, $existing_match ) ) {
                $normalized_width = max( $normalized_width, (int) $existing_match[1] );
            }

            $fallback_width_used = $requested_width <= 0;
            $skip_oversize_ratio_cap = true;
            $skip_runtime_correction = true;
            $merged[] = 'w-' . absint( $normalized_width );
        } else {
            if ( $requested_width > 0 && $requested_width < 300 && $normalized_width > 600 ) {
                $normalized_width = 600;
                $oversized_width_prevented = true;
            }

            if ( $requested_width > 0 && $max_allowed_width > 0 && $normalized_width > $max_allowed_width ) {
                $oversized_width_prevented = true;
                $normalized_width = $this->normalize_imagekit_width( $requested_width, $max_allowed_width );
            }

            if ( $requested_width > 0 || '' === $existing_width_transform ) {
                $merged[] = 'w-' . absint( $normalized_width );
            } else {
                $merged[] = $existing_width_transform;
            }
        }

        $normalized = array_values( array_unique( $merged ) );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $this->debug_log(
                'normalized_transform',
                array(
                    'requested_width' => $requested_width,
                    'normalized_transform_width' => $normalized_width,
                    'requested_transform_width' => $requested_width,
                    'fallback_width_used' => $fallback_width_used,
                    'oversized_transform_blocked' => $oversized_width_prevented,
                    'skip_oversize_ratio_cap' => $skip_oversize_ratio_cap,
                    'skip_runtime_correction' => $skip_runtime_correction,
                    'is_background_image' => $is_background_image,
                    'width_buckets' => $this->get_imagekit_width_buckets(),
                    'max_oversize_ratio' => $max_oversize_ratio,
                    'existing_transforms' => $existing_transforms,
                    'normalized_transforms' => $normalized,
                )
            );
        }

        return $normalized;
    }

    private function normalize_imagekit_width( $width, $max_allowed_width = 0 ) {
        $width = absint( $width );
        if ( 0 === $width ) {
            return self::IMAGEKIT_FALLBACK_WIDTH;
        }

        $selected = $width;
        foreach ( $this->get_imagekit_width_buckets() as $bucket ) {
            if ( $width <= $bucket ) {
                $selected = $bucket;
                break;
            }
            $selected = $bucket;
        }

        if ( $max_allowed_width > 0 && $selected > $max_allowed_width ) {
            foreach ( array_reverse( $this->get_imagekit_width_buckets() ) as $bucket ) {
                if ( $bucket <= $max_allowed_width && $bucket >= $width ) {
                    return $bucket;
                }
            }

            return min( $width, $max_allowed_width );
        }

        return $selected;
    }

    private function get_imagekit_auto_format_transform() {
        $options = get_option( 'wp_fastlayer_options', array() );
        if ( isset( $options['imagekit_auto_format'] ) && '1' === (string) $options['imagekit_auto_format'] ) {
            if ( isset( $options['imagekit_auto_format_mode'] ) && 'fm-auto' === $options['imagekit_auto_format_mode'] ) {
                return 'fm-auto';
            }
            return 'f-auto';
        }

        return '';
    }

    private function build_url_from_parts( $parsed, $query_args ) {
        $scheme   = isset( $parsed['scheme'] ) ? $parsed['scheme'] . '://' : '';
        $host     = isset( $parsed['host'] ) ? $parsed['host'] : '';
        $port     = isset( $parsed['port'] ) ? ':' . absint( $parsed['port'] ) : '';
        $user     = isset( $parsed['user'] ) ? $parsed['user'] : '';
        $pass     = isset( $parsed['pass'] ) ? ':' . $parsed['pass'] : '';
        $pass     = ( '' !== $user || '' !== $pass ) ? $pass . '@' : '';
        $path     = isset( $parsed['path'] ) ? $parsed['path'] : '';
        $fragment = isset( $parsed['fragment'] ) ? '#' . $parsed['fragment'] : '';
        $query    = ! empty( $query_args ) ? '?' . http_build_query( $query_args, '', '&', PHP_QUERY_RFC3986 ) : '';

        $url = $scheme . $user . $pass . $host . $port . $path . $query . $fragment;
        return str_replace( '%2C', ',', $url );
    }

    private function is_imagekit_url( $url ) {
        if ( empty( $url ) || ! is_string( $url ) ) {
            return false;
        }

        $host = wp_parse_url( $url, PHP_URL_HOST );
        if ( empty( $host ) ) {
            return false;
        }

        return false !== stripos( $host, 'imagekit.io' );
    }

    private function is_local_image_url( $url ) {
        if ( empty( $url ) || ! is_string( $url ) ) {
            return false;
        }

        $parsed = wp_parse_url( $url );

        if ( empty( $parsed['path'] ) ) {
            return false;
        }

        if ( ! preg_match( '/\.(png|jpe?g|gif|webp|avif|svg)$/i', $parsed['path'] ) ) {
            return false;
        }

        if ( empty( $parsed['host'] ) ) {
            return true;
        }

        $site = wp_parse_url( site_url() );

        return ! empty( $site['host'] ) && strtolower( $parsed['host'] ) === strtolower( $site['host'] );
    }

    private function get_imagekit_origin_path_mode( $options ) {
        $mode = isset( $options['imagekit_origin_path_mode'] ) ? sanitize_key( (string) $options['imagekit_origin_path_mode'] ) : 'full_uploads_path';

        return in_array( $mode, array( 'full_uploads_path', 'uploads_relative_path' ), true ) ? $mode : 'full_uploads_path';
    }

    private function build_imagekit_endpoint_url( $endpoint, $rewritten_path ) {
        $endpoint_path = wp_parse_url( (string) $endpoint, PHP_URL_PATH );
        $endpoint_path = is_string( $endpoint_path ) ? '/' . ltrim( preg_replace( '#/+#', '/', $endpoint_path ), '/' ) : '';

        $normalized_rewritten_path = '/' . ltrim( preg_replace( '#/+#', '/', (string) $rewritten_path ), '/' );

        if ( '' !== $endpoint_path && '/' !== $endpoint_path ) {
            $endpoint_segments = explode( '/', trim( $endpoint_path, '/' ) );
            $rewritten_segments = explode( '/', trim( $normalized_rewritten_path, '/' ) );
            $prefix_count = count( $endpoint_segments );

            if ( $prefix_count > 0 && count( $rewritten_segments ) >= $prefix_count ) {
                $candidate_prefix = array_slice( $rewritten_segments, 0, $prefix_count );
                if ( implode( '/', $candidate_prefix ) === implode( '/', $endpoint_segments ) ) {
                    $rewritten_segments = array_slice( $rewritten_segments, $prefix_count );
                    $normalized_rewritten_path = '/' . implode( '/', $rewritten_segments );
                }
            }
        }

        return untrailingslashit( (string) $endpoint ) . '/' . ltrim( $normalized_rewritten_path, '/' );
    }

    private function normalize_imagekit_rewritten_path( $rewritten_path, $mode ) {
        $path = preg_replace( '#/+#', '/', (string) $rewritten_path );
        $path = is_string( $path ) ? $path : (string) $rewritten_path;
        $path = ltrim( $path, '/' );

        if ( 'uploads_relative_path' === $mode ) {
            $path = preg_replace( '#^wp-content/uploads/#i', '', $path );
            $path = preg_replace( '#^uploads/#i', '', $path );
        }

        $path = preg_replace( '#(^|/)uploads/uploads(/|$)#i', '$1uploads$2', $path );

        return trim( (string) $path, '/' );
    }

    private function strip_endpoint_prefix_from_rewritten_path( $endpoint, $rewritten_path ) {
        $endpoint_path = wp_parse_url( (string) $endpoint, PHP_URL_PATH );
        if ( ! is_string( $endpoint_path ) || '' === $endpoint_path || '/' === $endpoint_path ) {
            return trim( (string) $rewritten_path, '/' );
        }

        $endpoint_segments = array_values( array_filter( explode( '/', trim( $endpoint_path, '/' ) ) ) );
        $rewritten_segments = array_values( array_filter( explode( '/', trim( (string) $rewritten_path, '/' ) ) ) );

        if ( empty( $endpoint_segments ) || count( $rewritten_segments ) < count( $endpoint_segments ) ) {
            return trim( (string) $rewritten_path, '/' );
        }

        $prefix = array_slice( $rewritten_segments, 0, count( $endpoint_segments ) );
        if ( implode( '/', $prefix ) === implode( '/', $endpoint_segments ) ) {
            $rewritten_segments = array_slice( $rewritten_segments, count( $endpoint_segments ) );
        }

        return implode( '/', $rewritten_segments );
    }

    private function detect_duplicate_path_segments( $path ) {
        $segments = array_values( array_filter( explode( '/', trim( (string) $path, '/' ) ) ) );
        $duplicates = array();

        for ( $i = 1, $count = count( $segments ); $i < $count; $i++ ) {
            if ( strtolower( $segments[ $i ] ) === strtolower( $segments[ $i - 1 ] ) ) {
                $duplicates[] = $segments[ $i ];
            }
        }

        return array_values( array_unique( $duplicates ) );
    }

    private function should_process_frontend_rewrite() {
        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return false;
        }

        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return false;
        }

        return true;
    }

    private function rewrite_srcset_string( $srcset ) {
        if ( '' === $srcset || ! is_string( $srcset ) ) {
            return $srcset;
        }

        $parts = $this->parse_srcset_candidates( $srcset );

        foreach ( $parts as $index => $part ) {
            if ( '' === $part ) {
                continue;
            }

            $segments = preg_split( '/\s+/', $part, 2 );
            $candidate_url = isset( $segments[0] ) ? $segments[0] : '';
            $descriptor   = isset( $segments[1] ) ? $segments[1] : '';

            $width = 0;
            if ( preg_match( '/^(\d+)w$/i', $descriptor, $dm ) ) {
                $width = (int) $dm[1];
            }

            $this->debug_log(
                'original_srcset_candidate',
                array(
                    'candidate' => $candidate_url,
                    'descriptor' => $descriptor,
                    'srcset_candidate_width' => $width,
                )
            );

            $rewritten_url = $this->rewrite_to_imagekit( $candidate_url, $width );

            $parts[ $index ] = '' !== $descriptor ? $rewritten_url . ' ' . $descriptor : $rewritten_url;
            $this->debug_log(
                'final_srcset_candidate',
                array(
                    'final_candidate' => $parts[ $index ],
                    'srcset_candidate_width' => $width,
                )
            );
        }

        return implode( ', ', $parts );
    }

    private function parse_srcset_candidates( $srcset ) {
        $candidates = array();
        $length = strlen( $srcset );
        $offset = 0;

        while ( $offset < $length ) {
            while ( $offset < $length && ctype_space( $srcset[ $offset ] ) ) {
                $offset++;
            }

            if ( $offset >= $length ) {
                break;
            }

            $url = '';
            while ( $offset < $length ) {
                $char = $srcset[ $offset ];

                if ( ',' === $char ) {
                    $next = $offset + 1 < $length ? $srcset[ $offset + 1 ] : '';
                    if ( '' === $next || ctype_space( $next ) ) {
                        $offset++;
                        break;
                    }
                }

                if ( ctype_space( $char ) ) {
                    break;
                }

                $url .= $char;
                $offset++;
            }

            while ( $offset < $length && ctype_space( $srcset[ $offset ] ) ) {
                $offset++;
            }

            $descriptor = '';
            while ( $offset < $length && ! ctype_space( $srcset[ $offset ] ) && ',' !== $srcset[ $offset ] ) {
                $descriptor .= $srcset[ $offset ];
                $offset++;
            }

            while ( $offset < $length && ',' !== $srcset[ $offset ] ) {
                if ( ! ctype_space( $srcset[ $offset ] ) ) {
                    break;
                }
                $offset++;
            }

            if ( ',' === ( $srcset[ $offset ] ?? '' ) ) {
                $offset++;
            }

            $candidate = trim( $url . ( '' !== $descriptor ? ' ' . $descriptor : '' ) );
            if ( '' !== $candidate ) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    private function rewrite_inline_style_urls( $content ) {
        if ( ! is_string( $content ) || '' === $content ) {
            return $content;
        }

        $content = $this->rewrite_elementor_background_settings_urls( $content );

        $background_rewritten = preg_replace_callback(
            '#(?<declaration>background(?:-image)?\s*:[^;{}]*?)url\((?<quote>["\"]?)(?<url>(?!data:)[^\)"\']+)(?P=quote)\)#i',
            function ( $matches ) {
                $original = isset( $matches['url'] ) ? trim( $matches['url'] ) : '';
                if ( '' === $original ) {
                    return $matches[0];
                }

                $rewritten_url = $this->rewrite_to_imagekit( html_entity_decode( $original, ENT_QUOTES, 'UTF-8' ), $this->get_background_transform_min_width(), true );

                if ( $rewritten_url !== $original ) {
                    $this->debug_log(
                        'background_style_url_rewritten',
                        array(
                            'original_url' => $original,
                            'rewritten_url' => $rewritten_url,
                            'is_background_image' => true,
                        )
                    );
                }

                return $matches['declaration'] . 'url(' . $matches['quote'] . $rewritten_url . $matches['quote'] . ')';
            },
            $content
        );

        $content = is_string( $background_rewritten ) ? $background_rewritten : $content;

        $rewritten = preg_replace_callback(
            '#url\((?<quote>["\"]?)(?<url>(?!data:)[^\)"\']+)(?P=quote)\)#i',
            function ( $matches ) {
                $original = isset( $matches['url'] ) ? trim( $matches['url'] ) : '';
                if ( '' === $original ) {
                    return $matches[0];
                }

                $rewritten_url = $this->rewrite_to_imagekit( html_entity_decode( $original, ENT_QUOTES, 'UTF-8' ) );

                if ( $rewritten_url !== $original ) {
                    $this->debug_log(
                        'inline_style_url_rewritten',
                        array(
                            'original_url' => $original,
                            'rewritten_url' => $rewritten_url,
                        )
                    );
                }

                return 'url(' . $matches['quote'] . $rewritten_url . $matches['quote'] . ')';
            },
            $content
        );

        return is_string( $rewritten ) ? $rewritten : $content;
    }

    private function rewrite_elementor_background_settings_urls( $content ) {
        if ( ! is_string( $content ) || '' === $content ) {
            return $content;
        }

        $rewritten = preg_replace_callback(
            '#(?<attr>\bdata-settings\s*=\s*["\'])(?<payload>[^"\']+)(?<end>["\'])#i',
            function ( $matches ) {
                $payload = isset( $matches['payload'] ) ? (string) $matches['payload'] : '';
                if ( '' === $payload || false === stripos( $payload, 'background' ) ) {
                    return $matches[0];
                }

                $payload_rewritten = preg_replace_callback(
                    '#https?://[^"\'\s\\]+?\.(?:png|jpe?g|gif|webp|avif|svg)(?:\?[^"\'\s\\]*)?#i',
                    function ( $url_matches ) {
                        $original_url = isset( $url_matches[0] ) ? (string) $url_matches[0] : '';
                        if ( '' === $original_url ) {
                            return $original_url;
                        }

                        return $this->rewrite_to_imagekit( $original_url, $this->get_background_transform_min_width(), true );
                    },
                    $payload
                );

                return $matches['attr'] . ( is_string( $payload_rewritten ) ? $payload_rewritten : $payload ) . $matches['end'];
            },
            $content
        );

        return is_string( $rewritten ) ? $rewritten : $content;
    }

    private function get_background_transform_min_width() {
        return wp_is_mobile() ? 768 : 1600;
    }

    private function extract_width_from_sizes_attr( $sizes ) {
        if ( '' === $sizes || ! is_string( $sizes ) ) {
            return 0;
        }

        // Match the last fixed-px value in sizes (e.g. "100vw, 800px" or "(max-width:600px) 100vw, 1200px").
        if ( preg_match( '/,\s*(\d+)px\s*$/', $sizes, $m ) ) {
            return (int) $m[1];
        }

        // Match single fixed-px value with no comma.
        if ( preg_match( '/^\s*(\d+)px\s*$/', $sizes, $m ) ) {
            return (int) $m[1];
        }

        // Match min(..., Npx).
        if ( preg_match( '/min\s*\([^,]+,\s*(\d+)px\)/i', $sizes, $m ) ) {
            return (int) $m[1];
        }

        return 0;
    }

    private function resolve_attachment_id( $attachment ) {
        if ( is_object( $attachment ) && isset( $attachment->ID ) ) {
            return absint( $attachment->ID );
        }

        if ( is_numeric( $attachment ) ) {
            return absint( $attachment );
        }

        return 0;
    }

    private function detect_display_width_from_image_attributes( array $attr, $attachment = null, $size = null ) {
        $display_width = 0;
        $source = '';

        if ( ! empty( $attr['width'] ) && is_numeric( $attr['width'] ) ) {
            $display_width = (int) $attr['width'];
            $source = 'width_attribute';
        }

        if ( 0 === $display_width && ! empty( $attr['sizes'] ) ) {
            $display_width = $this->extract_width_from_sizes_attr( (string) $attr['sizes'] );
            $source = 'sizes_attribute';
        }

        if ( 0 === $display_width && ! empty( $attr['style'] ) && is_string( $attr['style'] ) ) {
            $display_width = $this->extract_width_from_style_attr( $attr['style'] );
            $source = 'style_attribute';
        }

        if ( 0 === $display_width && ! empty( $attr['container_width_hint'] ) && is_numeric( $attr['container_width_hint'] ) ) {
            $display_width = absint( $attr['container_width_hint'] );
            $source = 'container_width_hint';
        }

        if ( 0 === $display_width ) {
            foreach ( array( 'srcset', 'data-srcset', 'data-lazy-srcset' ) as $srcset_key ) {
                if ( ! empty( $attr[ $srcset_key ] ) && is_string( $attr[ $srcset_key ] ) ) {
                    $display_width = $this->extract_width_from_srcset_attr( $attr[ $srcset_key ] );
                    if ( $display_width > 0 ) {
                        $source = $srcset_key;
                        break;
                    }
                }
            }
        }

        if ( 0 === $display_width && null !== $size ) {
            $display_width = $this->extract_width_from_size_param( $size, $attachment );
            $source = 'size_parameter';
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $display_width > 0 ) {
            $this->debug_log(
                'detected_display_width',
                array(
                    'detected_display_width' => $display_width,
                    'source' => $source,
                    'oversized_width_prevented' => $display_width < self::IMAGEKIT_FALLBACK_WIDTH,
                )
            );
        }

        return $display_width;
    }

    private function detect_display_width_from_img_tag( $inner ) {
        $attributes = array();

        if ( preg_match( '/\bwidth\s*=\s*["\']?(\d+)["\']?/i', $inner, $wm ) ) {
            $attributes['width'] = $wm[1];
        }

        if ( preg_match( '/\bsizes\s*=\s*["\']([^"\']+)["\']/', $inner, $sm ) ) {
            $attributes['sizes'] = $sm[1];
        }

        if ( preg_match( '/\bstyle\s*=\s*["\']([^"\']+)["\']/', $inner, $sm2 ) ) {
            $attributes['style'] = $sm2[1];
        }

        if ( preg_match( '/\b(?:data-width|data-max-width|data-elementor-width|data-carousel-width|data-swiper-width)\s*=\s*["\']?(\d+)["\']?/i', $inner, $dw ) ) {
            $attributes['container_width_hint'] = $dw[1];
        }

        if ( empty( $attributes['container_width_hint'] ) && preg_match( '/\bclass\s*=\s*["\']([^"\']+)["\']/', $inner, $cm ) ) {
            $class_value = (string) $cm[1];
            if ( preg_match( '/\bsize-(\d+)x\d+\b/i', $class_value, $class_match ) ) {
                $attributes['container_width_hint'] = $class_match[1];
            }
        }

        foreach ( array( 'srcset', 'data-srcset', 'data-lazy-srcset' ) as $srcset_key ) {
            if ( preg_match( '/\b' . preg_quote( $srcset_key, '/' ) . '\s*=\s*["\']([^"\']+)["\']/', $inner, $sm3 ) ) {
                $attributes[ $srcset_key ] = $sm3[1];
            }
        }

        return $this->detect_display_width_from_image_attributes( $attributes );
    }

    private function extract_width_from_style_attr( $style ) {
        if ( '' === $style || ! is_string( $style ) ) {
            return 0;
        }

        if ( preg_match( '/(?:^|;)\s*width\s*:\s*(\d+)px/i', $style, $m ) ) {
            return (int) $m[1];
        }

        if ( preg_match( '/(?:^|;)\s*max-width\s*:\s*(\d+)px/i', $style, $m ) ) {
            return (int) $m[1];
        }

        return 0;
    }

    private function extract_width_from_srcset_attr( $srcset ) {
        if ( '' === $srcset || ! is_string( $srcset ) ) {
            return 0;
        }

        $parts = $this->parse_srcset_candidates( $srcset );
        $widths = array();

        foreach ( $parts as $part ) {
            if ( preg_match( '/(?:^|\s)(\d+)w$/i', trim( $part ), $m ) ) {
                $widths[] = (int) $m[1];
            }
        }

        return ! empty( $widths ) ? min( $widths ) : 0;
    }

    private function extract_width_from_size_param( $size, $attachment = null ) {
        if ( is_array( $size ) && ! empty( $size[0] ) && is_numeric( $size[0] ) ) {
            return absint( $size[0] );
        }

        $attachment_id = $this->resolve_attachment_id( $attachment );
        if ( empty( $attachment_id ) || ! is_string( $size ) ) {
            return 0;
        }

        $image_size = image_get_intermediate_size( $attachment_id, $size );
        if ( is_array( $image_size ) && ! empty( $image_size['width'] ) ) {
            return absint( $image_size['width'] );
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( is_array( $metadata ) && ! empty( $metadata['width'] ) ) {
            return min( absint( $metadata['width'] ), self::IMAGEKIT_FALLBACK_WIDTH );
        }

        return 0;
    }

    private function get_imagekit_width_buckets() {
        return array( 50, 100, 150, 200, 300, 400, 600, 768, 1024, 1280, 1600, 2048, 2560 );
    }

    private function get_imagekit_max_oversize_ratio() {
        $options = get_option( 'wp_fastlayer_options', array() );
        $ratio = isset( $options['imagekit_max_oversize_ratio'] ) ? (float) $options['imagekit_max_oversize_ratio'] : 1.5;

        if ( $ratio < 1.0 ) {
            $ratio = 1.0;
        }

        if ( $ratio > 3.0 ) {
            $ratio = 3.0;
        }

        return round( $ratio, 2 );
    }

    private function is_imagekit_runtime_correction_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );

        return ! isset( $options['imagekit_runtime_correction'] ) || '1' === (string) $options['imagekit_runtime_correction'];
    }

    private function is_imagekit_runtime_overlay_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );

        return isset( $options['imagekit_debug_overlay'] ) && '1' === (string) $options['imagekit_debug_overlay'];
    }

    private function should_enqueue_runtime_debugger() {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return false;
        }

        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            return false;
        }

        if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        return $this->is_imagekit_enabled();
    }

    private function is_smart_lcp_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );

        return isset( $options['enable_smart_lcp_optimization'] ) && '1' === (string) $options['enable_smart_lcp_optimization'];
    }

    private function is_lcp_preload_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );

        return ! isset( $options['enable_lcp_preload'] ) || '1' === (string) $options['enable_lcp_preload'];
    }

    private function is_lcp_background_support_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );

        return ! isset( $options['enable_lcp_hero_background'] ) || '1' === (string) $options['enable_lcp_hero_background'];
    }

    private function is_lcp_debug_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );

        return isset( $options['enable_lcp_debug_mode'] ) && '1' === (string) $options['enable_lcp_debug_mode'];
    }

    private function apply_smart_lcp_optimization_to_html( $html ) {
        if ( ! is_string( $html ) || '' === $html ) {
            return $html;
        }

        if ( empty( $this->lcp_candidate ) ) {
            $background_probe = $this->detect_lcp_background_probe( $html );
            if ( $this->is_valid_lcp_probe( $background_probe ) ) {
                $this->set_lcp_candidate( $background_probe, 'background' );
            }
        }

        $selected_probe = null;
        $selected_tag = '';

        if ( preg_match_all( '#<img(?P<inner>[^>]+)>#i', $html, $img_matches, PREG_SET_ORDER ) ) {
            foreach ( $img_matches as $position => $img_match ) {
                $tag = isset( $img_match[0] ) ? (string) $img_match[0] : '';
                if ( '' === $tag ) {
                    continue;
                }

                $inner = isset( $img_match['inner'] ) ? (string) $img_match['inner'] : '';
                $attr = $this->extract_img_attributes_from_inner( $inner );
                $probe = $this->build_lcp_probe_from_img_attributes( $attr, 'content_img', absint( $position ) );

                if ( ! $this->is_valid_lcp_probe( $probe ) ) {
                    continue;
                }

                if ( ! $this->is_better_lcp_probe( $selected_probe, $probe ) ) {
                    continue;
                }

                $selected_probe = $probe;
                $selected_tag = $tag;
            }
        }

        if ( is_array( $selected_probe ) && '' !== $selected_tag && ( empty( $this->lcp_candidate ) || $this->is_better_lcp_probe( $this->lcp_candidate, $selected_probe ) ) ) {
            $this->set_lcp_candidate( $selected_probe, 'content_img' );

            $updated_tag = $this->apply_lcp_attributes_to_img_tag( $selected_tag );
            if ( '' !== $updated_tag && $updated_tag !== $selected_tag ) {
                $html = $this->replace_first( $selected_tag, $updated_tag, $html );
            }
        }

        $this->persist_lcp_report();

        return $html;
    }

    private function detect_lcp_background_probe( $html ) {
        if ( ! $this->is_lcp_background_support_enabled() || ! is_string( $html ) || '' === $html ) {
            return null;
        }

        if ( ! preg_match_all( '#<(?:section|div)[^>]+>#i', $html, $blocks ) ) {
            return null;
        }

        foreach ( $blocks[0] as $block ) {
            if ( ! preg_match( '/\b(?:class|id)\s*=\s*["\']([^"\']+)["\']/i', $block, $class_match ) ) {
                continue;
            }

            $class_id = strtolower( (string) $class_match[1] );
            if ( ! preg_match( '/hero|banner|cta|featured|elementor-top-section|elementor-section/i', $class_id ) ) {
                continue;
            }

            if ( preg_match('/\bstyle\s*=\s*["\'][^"\']*background(?:-image)?\s*:\s*[^"\']*url\((?:"|\')?([^"\')]+)(?:"|\')?\)/i', $block, $bg ) ) {
                $bg_url = isset( $bg[1] ) ? trim( html_entity_decode( (string) $bg[1], ENT_QUOTES, 'UTF-8' ) ) : '';
                if ( '' !== $bg_url && $this->is_local_image_url( $bg_url ) ) {
                    return array(
                        'url' => $bg_url,
                        'preload_url' => $bg_url,
                        'source' => 'hero_background',
                        'type' => 'background-image',
                        'score' => 75,
                        'is_background' => true,
                        'preload_applied' => false,
                    );
                }
            }
        }

        return null;
    }

    private function extract_img_attributes_from_inner( $inner ) {
        $attr = array();

        foreach ( array( 'src', 'data-src', 'data-lazy-src', 'srcset', 'data-srcset', 'data-lazy-srcset', 'sizes', 'class', 'style', 'loading', 'decoding', 'fetchpriority', 'width', 'height' ) as $key ) {
            if ( preg_match( '/\b' . preg_quote( $key, '/' ) . '\s*=\s*["\']([^"\']*)["\']/i', $inner, $m ) ) {
                $attr[ $key ] = isset( $m[1] ) ? $m[1] : '';
            }
        }

        return $attr;
    }

    private function build_lcp_probe_from_img_attributes( $attr, $source = 'img', $position = 0 ) {
        if ( ! is_array( $attr ) ) {
            return null;
        }

        $url = '';
        foreach ( array( 'src', 'data-src', 'data-lazy-src' ) as $key ) {
            if ( ! empty( $attr[ $key ] ) && is_string( $attr[ $key ] ) ) {
                $url = trim( html_entity_decode( (string) $attr[ $key ], ENT_QUOTES, 'UTF-8' ) );
                if ( '' !== $url ) {
                    break;
                }
            }
        }

        if ( '' === $url ) {
            return null;
        }

        $class = isset( $attr['class'] ) ? strtolower( (string) $attr['class'] ) : '';
        $style = isset( $attr['style'] ) ? strtolower( (string) $attr['style'] ) : '';
        if ( false !== strpos( $class, 'logo' ) || false !== strpos( $class, 'icon' ) || false !== strpos( $class, 'avatar' ) || false !== strpos( $url, 'logo' ) || false !== strpos( $url, 'emoji' ) || false !== strpos( $class, 'emoji' ) ) {
            return null;
        }

        if ( false !== strpos( $class, 'swiper-slide-duplicate' ) || false !== strpos( $class, 'slick-cloned' ) || false !== strpos( $style, 'display:none' ) || false !== strpos( $style, 'visibility:hidden' ) ) {
            return null;
        }

        $width = $this->detect_display_width_from_image_attributes( $attr );
        if ( $width <= 0 ) {
            $width = 300;
        }

        if ( $width < 180 ) {
            return null;
        }

        $priority_bonus = 0;
        if ( preg_match( '/hero|banner|featured|elementor|swiper-slide-active|slick-active|carousel/i', $class ) ) {
            $priority_bonus += 20;
        }
        if ( 0 === $position ) {
            $priority_bonus += 8;
        }

        $score = $width + $priority_bonus;
        $srcset = '';
        foreach ( array( 'srcset', 'data-srcset', 'data-lazy-srcset' ) as $srcset_key ) {
            if ( ! empty( $attr[ $srcset_key ] ) && is_string( $attr[ $srcset_key ] ) ) {
                $srcset = (string) $attr[ $srcset_key ];
                break;
            }
        }

        return array(
            'url' => $url,
            'preload_url' => $url,
            'source' => $source,
            'type' => 'img',
            'score' => $score,
            'width' => $width,
            'position' => $position,
            'is_img' => true,
            'srcset' => $srcset,
            'sizes' => isset( $attr['sizes'] ) ? (string) $attr['sizes'] : '',
            'preload_applied' => false,
            'lazyload_removed' => true,
            'fetchpriority_applied' => true,
            'eager_applied' => true,
        );
    }

    private function is_valid_lcp_probe( $probe ) {
        return is_array( $probe ) && ! empty( $probe['url'] ) && is_string( $probe['url'] ) && ( $this->is_local_image_url( $probe['url'] ) || $this->is_imagekit_url( $probe['url'] ) );
    }

    private function is_better_lcp_probe( $current, $candidate ) {
        if ( ! is_array( $candidate ) ) {
            return false;
        }

        if ( ! is_array( $current ) || empty( $current ) ) {
            return true;
        }

        $current_score = isset( $current['score'] ) ? (int) $current['score'] : 0;
        $candidate_score = isset( $candidate['score'] ) ? (int) $candidate['score'] : 0;

        return $candidate_score > $current_score;
    }

    private function set_lcp_candidate( $probe, $source ) {
        if ( ! $this->is_valid_lcp_probe( $probe ) ) {
            return;
        }

        $probe['source'] = $source;
        if ( empty( $probe['final_imagekit_url'] ) ) {
            $probe['final_imagekit_url'] = (string) $probe['url'];
        }

        $this->lcp_candidate = $probe;
        $this->debug_lcp_log( 'detected_lcp_element', $this->get_lcp_debug_payload() );
    }

    private function apply_lcp_image_attributes( array $attr ) {
        $attr['fetchpriority'] = 'high';
        $attr['loading'] = 'eager';
        $attr['decoding'] = 'async';
        $attr['data-wpfl-lcp'] = '1';

        if ( empty( $attr['src'] ) ) {
            if ( ! empty( $attr['data-src'] ) ) {
                $attr['src'] = $attr['data-src'];
            } elseif ( ! empty( $attr['data-lazy-src'] ) ) {
                $attr['src'] = $attr['data-lazy-src'];
            }
        }

        if ( empty( $attr['srcset'] ) ) {
            if ( ! empty( $attr['data-srcset'] ) ) {
                $attr['srcset'] = $attr['data-srcset'];
            } elseif ( ! empty( $attr['data-lazy-srcset'] ) ) {
                $attr['srcset'] = $attr['data-lazy-srcset'];
            }
        }

        unset( $attr['data-src'], $attr['data-lazy-src'], $attr['data-srcset'], $attr['data-lazy-srcset'] );

        if ( ! empty( $attr['class'] ) ) {
            $attr['class'] = trim( preg_replace( '/\s+/', ' ', str_ireplace( array( 'lazyload', 'lazy', 'wp-lazyload', 'skip-lazy' ), '', (string) $attr['class'] ) ) );
        }

        return $attr;
    }

    private function apply_lcp_attributes_to_img_tag( $img_tag ) {
        if ( '' === (string) $img_tag ) {
            return $img_tag;
        }

        $updated = $img_tag;
        $updated = preg_replace( '/\sloading\s*=\s*["\'][^"\']*["\']/i', '', $updated );
        $updated = preg_replace( '/\sfetchpriority\s*=\s*["\'][^"\']*["\']/i', '', $updated );
        $updated = preg_replace( '/\sdecoding\s*=\s*["\'][^"\']*["\']/i', '', $updated );
        $updated = preg_replace( '/\sdata-src\s*=\s*["\']([^"\']*)["\']/i', ' src="$1"', $updated, 1 );
        $updated = preg_replace( '/\sdata-lazy-src\s*=\s*["\']([^"\']*)["\']/i', ' src="$1"', $updated, 1 );
        $updated = preg_replace( '/\sdata-srcset\s*=\s*["\']([^"\']*)["\']/i', ' srcset="$1"', $updated, 1 );
        $updated = preg_replace( '/\sdata-lazy-srcset\s*=\s*["\']([^"\']*)["\']/i', ' srcset="$1"', $updated, 1 );
        $updated = preg_replace_callback(
            '/\sclass\s*=\s*["\']([^"\']*)["\']/i',
            function( $m ) {
                $class = trim( preg_replace( '/\s+/', ' ', str_ireplace( array( 'lazyload', 'lazy', 'wp-lazyload', 'skip-lazy' ), '', (string) $m[1] ) ) );
                return '' === $class ? '' : ' class="' . esc_attr( $class ) . '"';
            },
            $updated,
            1
        );

        $trimmed = rtrim( $updated );
        if ( substr( $trimmed, -2 ) === '/>' ) {
            $trimmed = rtrim( substr( $trimmed, 0, -2 ) );
            $updated = $trimmed . ' fetchpriority="high" loading="eager" decoding="async" data-wpfl-lcp="1" />';
        } else {
            $trimmed = rtrim( substr( $trimmed, 0, -1 ) );
            $updated = $trimmed . ' fetchpriority="high" loading="eager" decoding="async" data-wpfl-lcp="1">';
        }

        return $updated;
    }

    private function replace_first( $search, $replace, $subject ) {
        $position = strpos( (string) $subject, (string) $search );
        if ( false === $position ) {
            return $subject;
        }

        return substr_replace( (string) $subject, (string) $replace, $position, strlen( (string) $search ) );
    }

    private function get_lcp_debug_payload() {
        $candidate = is_array( $this->lcp_candidate ) ? $this->lcp_candidate : array();

        return array(
            'detected_lcp_asset' => isset( $candidate['url'] ) ? $candidate['url'] : '',
            'preload_url' => isset( $candidate['preload_url'] ) ? $candidate['preload_url'] : '',
            'preload_type' => isset( $candidate['type'] ) ? $candidate['type'] : '',
            'preload_applied' => ! empty( $candidate['preload_applied'] ),
            'eager_loading_applied' => ! empty( $candidate['eager_applied'] ),
            'fetchpriority_applied' => ! empty( $candidate['fetchpriority_applied'] ),
            'lazyload_removed' => ! empty( $candidate['lazyload_removed'] ),
            'background_detection' => ! empty( $candidate['is_background'] ),
            'final_imagekit_url' => isset( $candidate['final_imagekit_url'] ) ? $candidate['final_imagekit_url'] : ( isset( $candidate['url'] ) ? $candidate['url'] : '' ),
            'source' => isset( $candidate['source'] ) ? $candidate['source'] : '',
        );
    }

    private function persist_lcp_report() {
        if ( ! $this->is_lcp_debug_enabled() || $this->lcp_report_saved || empty( $this->lcp_candidate ) ) {
            return;
        }

        set_transient( 'wp_fastlayer_lcp_last_report', $this->get_lcp_debug_payload(), 12 * HOUR_IN_SECONDS );
        $this->lcp_report_saved = true;
    }

    private function debug_lcp_log( $message, $context = array() ) {
        if ( ! $this->is_lcp_debug_enabled() || ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }

        $payload = is_array( $context ) ? $context : array();
        \wp_fastlayer_debug_log( '[WP FastLayer LCP] ' . $message . ( ! empty( $payload ) ? ' | ' . wp_json_encode( $payload ) : '' ) );
    }

    private function debug_log( $message, $context = array() ) {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }

        $payload = is_array( $context ) ? $context : array();
        \wp_fastlayer_debug_log( '[WP FastLayer ImageKit] ' . $message . ( ! empty( $payload ) ? ' | ' . wp_json_encode( $payload ) : '' ) );
    }

    private function log_output( $payload ) {
        if ( function_exists( 'log_output' ) ) {
            log_output( $payload );
            return;
        }

        $this->debug_log( 'network_delivery_debug', is_array( $payload ) ? $payload : array() );
    }
}
