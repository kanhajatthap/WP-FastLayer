<?php
namespace WP_FastLayer;
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Optimizer {
    private static $instance = null;
    private $combined_css_groups = array();
    private $combined_css_urls = array();
    private $combined_js_handles = array();
    private $combined_js_url = '';
    private $dequeued_gutenberg_styles = array();
    private $tracked_block_css_handles = array( 'wp-block-library', 'wp-block-library-theme', 'global-styles', 'classic-theme-styles' );
    private $block_css_console_report = array(
        'registered' => array(),
        'enqueued'   => array(),
        'dequeued'   => array(),
        'finalState' => array(),
    );
    private $google_fonts_icon_family_cache = array();
    private $allowed_js_types = array(
        'text/javascript',
        'module',
        'application/javascript',
        'application/ecmascript',
        'application/x-ecmascript',
        'application/x-javascript',
        'text/ecmascript',
        'text/javascript1.0',
        'text/javascript1.1',
        'text/javascript1.2',
        'text/javascript1.3',
        'text/javascript1.4',
        'text/javascript1.5',
        'text/jscript',
        'text/livescript',
        'text/x-ecmascript',
        'text/x-javascript',
    );

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_default_styles', array( $this, 'debug_trace_block_css_registration' ), 1000 );
        add_action( 'wp_enqueue_scripts', array( $this, 'minify_css' ), 999 );
        add_action( 'wp_enqueue_scripts', array( $this, 'maybe_optimize_gutenberg_block_css' ), 999 );
        add_action( 'wp_enqueue_scripts', array( $this, 'minify_js' ), 999 );
        add_action( 'wp_print_styles', array( $this, 'maybe_optimize_gutenberg_block_css_on_print_styles' ), 1 );
        add_action( 'wp_enqueue_scripts', array( $this, 'minify_js' ), 999 );
        add_action( 'wp_footer', array( $this, 'maybe_optimize_gutenberg_block_css_on_footer_fallback' ), 1 );
        add_action( 'wp_footer', array( $this, 'print_block_css_console_report' ), 10000 );
        add_action( 'wp_head', array( $this, 'print_inline_critical_css' ), 1 );
        add_filter( 'style_loader_tag', array( $this, 'maybe_combine_css_tag' ), 1, 4 );
        add_filter( 'style_loader_tag', array( $this, 'maybe_add_css_preload' ), 10, 4 );
        add_filter( 'style_loader_src', array( $this, 'enforce_google_fonts_display_swap' ), 20, 2 );
        add_filter( 'script_loader_tag', array( $this, 'maybe_combine_js_tag' ), 1, 3 );
        add_filter( 'script_loader_tag', array( $this, 'maybe_add_defer_attribute' ), 10, 3 );
        add_filter( 'script_loader_tag', array( $this, 'maybe_add_async_attribute' ), 20, 3 );
        add_filter( 'wp_inline_script_attributes', array( $this, 'maybe_delay_inline_script_attributes' ), 10, 2 );
        add_action( 'wp_footer', array( $this, 'print_delay_js_loader' ), 9999 );
        add_action( 'template_redirect', array( $this, 'start_html_buffer' ), 1 );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_lazyload_scripts' ) );
        add_filter( 'the_content', array( $this, 'lazyload_images' ) );
    }

    private function is_css_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_minify_css'] ) && '1' === $options['enable_minify_css'];
    }

    private function is_js_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_minify_js'] ) && '1' === $options['enable_minify_js'];
    }

    private function is_defer_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_js_defer'] ) && '1' === $options['enable_js_defer'];
    }

    private function is_safe_mode_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_js_safe_mode'] ) && '1' === $options['enable_js_safe_mode'];
    }

    private function is_async_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_js_async'] ) && '1' === $options['enable_js_async'];
    }

    private function is_smart_delay_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_js_smart_delay'] ) && '1' === $options['enable_js_smart_delay'];
    }

    private function is_smart_delay_debug_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_js_smart_delay_debug'] ) && '1' === $options['enable_js_smart_delay_debug'];
    }

    private function get_smart_delay_timeout() {
        $options = get_option( 'wp_fastlayer_options', array() );
        $timeout = isset( $options['smart_js_delay_timeout'] ) ? absint( $options['smart_js_delay_timeout'] ) : 6000;
        return max( 1000, min( 15000, $timeout ) );
    }

    private function get_smart_delay_exclusion_patterns() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['smart_js_delay_exclusions'] ) ? preg_split( '/\r?\n/', trim( (string) $options['smart_js_delay_exclusions'] ) ) : array();
    }

    private function get_smart_delay_critical_handles() {
        return array(
            'jquery',
            'jquery-core',
            'jquery-migrate',
            'elementor-frontend',
            'swiper',
            'wp-hooks',
            'wp-i18n',
            'wc-cart',
            'wc-checkout',
            'woocommerce',
            'wc-add-to-cart',
            'wc-cart-fragments',
            'wp-fastlayer-imagekit-runtime-debug',
            'wp-fastlayer-lazyload',
            'navigation',
            'menu',
            'webpack-runtime',
        );
    }

    private function get_smart_delay_critical_keywords() {
        return array(
            'jquery',
            'elementor-frontend',
            'swiper',
            'navigation',
            'menu',
            'webpack',
            'wp-hooks',
            'wp-i18n',
            'wc-checkout',
            'wc-cart',
            'woocommerce',
            'checkout',
            'cart',
            'login',
            'session',
            'lazyload',
            'wpfastlayerlazyload',
            'wp-fastlayer-imagekit-runtime-debug',
        );
    }

    private function is_smart_delay_excluded( $handle, $src, $tag ) {
        $handle_lc = strtolower( (string) $handle );
        $src_lc = strtolower( (string) $src );

        if ( in_array( $handle_lc, $this->get_smart_delay_critical_handles(), true ) ) {
            return true;
        }

        foreach ( $this->get_smart_delay_critical_keywords() as $keyword ) {
            if ( '' !== $keyword && ( false !== strpos( $handle_lc, $keyword ) || false !== strpos( $src_lc, $keyword ) ) ) {
                return true;
            }
        }

        $custom_patterns = $this->get_smart_delay_exclusion_patterns();
        foreach ( $custom_patterns as $raw_pattern ) {
            $pattern = trim( (string) $raw_pattern );
            if ( '' === $pattern ) {
                continue;
            }

            if ( 0 === stripos( $pattern, 'handle:' ) ) {
                $needle = trim( substr( $pattern, 7 ) );
                if ( '' !== $needle && false !== strpos( $handle_lc, strtolower( $needle ) ) ) {
                    return true;
                }
                continue;
            }

            if ( 0 === stripos( $pattern, 'regex:' ) ) {
                $regex = trim( substr( $pattern, 6 ) );
                if ( $this->is_valid_regex_pattern( $regex ) && ( preg_match( $regex, (string) $src ) === 1 || preg_match( $regex, (string) $handle ) === 1 || preg_match( $regex, (string) $tag ) === 1 ) ) {
                    return true;
                }
                continue;
            }

            if ( $this->is_valid_regex_pattern( $pattern ) && ( preg_match( $pattern, (string) $src ) === 1 || preg_match( $pattern, (string) $handle ) === 1 || preg_match( $pattern, (string) $tag ) === 1 ) ) {
                return true;
            }

            if ( '' !== $src && $this->pattern_matches_url( $pattern, (string) $src ) ) {
                return true;
            }

            if ( false !== strpos( $handle_lc, strtolower( $pattern ) ) || false !== strpos( $src_lc, strtolower( $pattern ) ) ) {
                return true;
            }
        }

        return false;
    }

    private function is_valid_regex_pattern( $pattern ) {
        if ( '' === (string) $pattern ) {
            return false;
        }

        set_error_handler( '__return_false' );
        $result = preg_match( $pattern, '' );
        restore_error_handler();

        return false !== $result;
    }

    private function has_smart_delay_dependency_risk( $handle ) {
        if ( '' === (string) $handle ) {
            return true;
        }

        global $wp_scripts;
        if ( ! isset( $wp_scripts->registered[ $handle ] ) ) {
            return true;
        }

        $script = $wp_scripts->registered[ $handle ];
        $deps = isset( $script->deps ) && is_array( $script->deps ) ? $script->deps : array();
        if ( empty( $deps ) ) {
            return false;
        }

        $critical_handles = $this->get_smart_delay_critical_handles();
        foreach ( $deps as $dependency_handle ) {
            if ( '' === (string) $dependency_handle || ! isset( $wp_scripts->registered[ $dependency_handle ] ) ) {
                return true;
            }

            $dependency_lc = strtolower( (string) $dependency_handle );
            if ( in_array( $dependency_lc, $critical_handles, true ) ) {
                return true;
            }
        }

        return false;
    }

    private function should_apply_smart_delay( $tag, $handle, $src ) {
        if ( false !== stripos( $tag, 'wp-fastlayer-delay' ) || false !== stripos( $tag, 'wpfastlayerlazyloadscript' ) ) {
            return false;
        }

        if ( $this->is_excluded_js( $src ) || $this->is_excluded_js_defer( $src ) ) {
            return false;
        }

        if ( $this->is_safe_mode_enabled() && '' !== (string) $src && $this->is_internal_url( $src ) ) {
            return false;
        }

        if ( $this->is_smart_delay_excluded( $handle, $src, $tag ) ) {
            return false;
        }

        if ( $this->has_smart_delay_dependency_risk( $handle ) ) {
            return false;
        }

        if ( '' === (string) $src && ! preg_match( '#<script[^>]*>\s*.+?\s*</script>#is', $tag ) ) {
            return false;
        }

        return true;
    }

    public function maybe_add_async_attribute( $tag, $handle, $src ) {
        if ( ! $this->is_async_enabled() || $this->is_defer_enabled() || is_admin() || empty( $src ) || false !== strpos( $tag, ' async' ) || false !== strpos( $tag, ' defer' ) ) {
            return $tag;
        }

        if ( $this->is_excluded_js( $src ) || $this->is_excluded_js_defer( $src ) ) {
            return $tag;
        }

        if ( $this->is_safe_mode_enabled() && $this->is_internal_url( $src ) ) {
            return $tag;
        }

        return str_replace( '<script ', '<script async ', $tag );
    }

    public function maybe_add_defer_attribute( $tag, $handle, $src ) {
        if ( ! $this->is_defer_enabled() || is_admin() || false !== strpos( $tag, ' defer' ) || false !== strpos( $tag, ' async' ) || false !== stripos( $tag, 'wpfastlayerlazyloadscript' ) ) {
            return $tag;
        }

        if ( $this->is_smart_delay_enabled() ) {
            if ( ! $this->should_apply_smart_delay( $tag, $handle, $src ) ) {
                if ( $this->is_smart_delay_debug_enabled() ) {
                    $this->debug_log_smart_delay( 'script_excluded', array( 'handle' => $handle, 'src' => $src ) );
                    if ( false === stripos( $tag, 'data-wpfl-delay-excluded=' ) ) {
                        $tag = str_replace( '<script ', '<script data-wpfl-delay-excluded="1" data-wpfl-handle="' . esc_attr( (string) $handle ) . '" ', $tag );
                    }
                }
                return $tag;
            }

            if ( $this->is_smart_delay_debug_enabled() ) {
                $this->debug_log_smart_delay( 'script_delayed', array( 'handle' => $handle, 'src' => $src ) );
            }

            return $this->wrap_smart_delay_script( $tag, $handle );
        }

        if ( empty( $src ) ) {
            return $tag;
        }

        if ( $this->is_excluded_js( $src ) || $this->is_excluded_js_defer( $src ) ) {
            return $tag;
        }

        if ( $this->is_safe_mode_enabled() && $this->is_internal_url( $src ) ) {
            return $tag;
        }

        return $this->wrap_delay_js_script( $tag );
    }

    public function maybe_delay_inline_script_attributes( $attributes, $data ) {
        if ( ! $this->is_defer_enabled() || ! $this->is_smart_delay_enabled() || is_admin() || ! is_array( $attributes ) ) {
            return $attributes;
        }

        $inline_id = '';
        if ( is_array( $data ) && isset( $data['id'] ) ) {
            $inline_id = (string) $data['id'];
        }

        if ( '' === $inline_id && isset( $attributes['id'] ) ) {
            $inline_id = (string) $attributes['id'];
        }

        if ( '' === $inline_id || ! preg_match( '/^(.+)-js-(before|after)$/', $inline_id, $id_matches ) ) {
            return $attributes;
        }

        $handle = isset( $id_matches[1] ) ? (string) $id_matches[1] : '';
        if ( '' === $handle || $this->is_smart_delay_excluded( $handle, '', '' ) || $this->has_smart_delay_dependency_risk( $handle ) ) {
            return $attributes;
        }

        if ( isset( $attributes['type'] ) && ! $this->is_allowed_js_type( (string) $attributes['type'] ) ) {
            return $attributes;
        }

        if ( isset( $attributes['type'] ) ) {
            $attributes['data-wpfl-type'] = (string) $attributes['type'];
        }

        $attributes['type'] = 'text/wp-fastlayer-delay';
        $attributes['data-wpfl-delayed'] = '1';
        $attributes['data-wpfl-handle'] = $handle;

        if ( $this->is_smart_delay_debug_enabled() ) {
            $this->debug_log_smart_delay( 'inline_script_delayed', array( 'handle' => $handle, 'id' => $inline_id ) );
        }

        return $attributes;
    }

    private function wrap_smart_delay_script( $tag, $handle ) {
        $type_regex = '/type\s*=\s*("|\')(.*?)\1/i';
        if ( preg_match( $type_regex, $tag, $type_matches ) ) {
            $type = trim( $type_matches[2] );
            if ( '' !== $type && ! $this->is_allowed_js_type( $type ) ) {
                return $tag;
            }
            $tag = preg_replace( $type_regex, 'data-wpfl-type=$1$2$1', $tag, 1 );
        }

        $src_regex = '/src\s*=\s*("|\')(.*?)\1/i';
        $tag = preg_replace( $src_regex, 'data-wpfl-src=$1$2$1', $tag, 1 );

        if ( false === stripos( $tag, 'type=' ) ) {
            $tag = str_replace( '<script ', '<script type="text/wp-fastlayer-delay" ', $tag );
        } else {
            $tag = str_replace( '<script ', '<script type="text/wp-fastlayer-delay" ', $tag );
        }

        if ( false === stripos( $tag, 'data-wpfl-delayed=' ) ) {
            $tag = str_replace( '<script ', '<script data-wpfl-delayed="1" ', $tag );
        }

        if ( '' !== (string) $handle && false === stripos( $tag, 'data-wpfl-handle=' ) ) {
            $tag = str_replace( '<script ', '<script data-wpfl-handle="' . esc_attr( $handle ) . '" ', $tag );
        }

        return $tag;
    }

    private function is_allowed_js_type( $type ) {
        return in_array( trim( strtolower( $type ) ), $this->allowed_js_types, true );
    }

    private function wrap_delay_js_script( $tag ) {
        $type_regex = '/type\s*=\s*("|\")(.*?)\1/i';
        if ( preg_match( $type_regex, $tag, $type_matches ) ) {
            $type = trim( $type_matches[2] );
            if ( '' !== $type && ! $this->is_allowed_js_type( $type ) ) {
                return $tag;
            }
            $tag = preg_replace( $type_regex, 'data-wpfl-type=$1$2$1', $tag, 1 );
        }

        $src_regex = '/src\s*=\s*("|\")(.*?)\1/i';
        $tag = preg_replace( $src_regex, 'data-wpfl-src=$1$2$1', $tag, 1 );

        if ( false === stripos( $tag, 'type=' ) ) {
            $tag = str_replace( '<script ', '<script type="text/wpfastlayerlazyloadscript" ', $tag );
        } else {
            $tag = str_replace( '<script ', '<script type="text/wpfastlayerlazyloadscript" ', $tag );
        }

        return $tag;
    }

    public function print_delay_js_loader() {
        if ( ! $this->is_defer_enabled() || is_admin() ) {
            return;
        }

        if ( $this->is_smart_delay_enabled() ) {
            $this->print_smart_delay_js_loader();
            return;
        }

        echo "<script>(function(){var loaded=false;function loadDelayedScripts(){if(loaded){return;}loaded=true;var scripts=document.querySelectorAll('script[type=\"text/wpfastlayerlazyloadscript\"],script[type=\"wpfastlayerlazyloadscript\"]');scripts.forEach(function(script){var newScript=document.createElement('script');Array.prototype.slice.call(script.attributes).forEach(function(attr){if('type'===attr.name||'data-wpfl-src'===attr.name||'data-wpfl-type'===attr.name){return;}newScript.setAttribute(attr.name,attr.value);} );var source=script.getAttribute('data-wpfl-src');var originalType=script.getAttribute('data-wpfl-type');if(originalType){newScript.setAttribute('type',originalType);}if(source){newScript.setAttribute('src',source);}else{newScript.text=script.textContent||script.innerText||'';}script.parentNode.replaceChild(newScript,script);});}
function triggerLoad(){loadDelayedScripts();removeListeners();}
function removeListeners(){window.removeEventListener('load',triggerLoad);document.removeEventListener('DOMContentLoaded',triggerLoad);['scroll','mousemove','mousedown','touchstart','touchmove','wheel','keydown'].forEach(function(event){window.removeEventListener(event,triggerLoad,true);});}
window.addEventListener('load',triggerLoad);
document.addEventListener('DOMContentLoaded',triggerLoad);
['scroll','mousemove','mousedown','touchstart','touchmove','wheel','keydown'].forEach(function(event){window.addEventListener(event,triggerLoad,{capture:true,passive:true,once:true});});})();</script>\n";
    }

    private function print_smart_delay_js_loader() {
        $config = array(
            'timeout' => $this->get_smart_delay_timeout(),
            'debug' => $this->is_smart_delay_debug_enabled(),
            'events' => array( 'scroll', 'mousemove', 'click', 'touchstart', 'keydown' ),
            'prefix' => '[WP FastLayer Delay]',
        );

        $config_json = wp_json_encode( $config );
        if ( ! is_string( $config_json ) || '' === $config_json ) {
            return;
        }

        echo '<script id="wp-fastlayer-smart-delay-loader">(function(cfg){if(!cfg||window.__wpFastLayerSmartDelayInit){return;}window.__wpFastLayerSmartDelayInit=true;var startedAt=Date.now();var restored=false;var fallbackTimer=0;var selector="script[type=\"text/wp-fastlayer-delay\"],script[type=\"wp-fastlayer-delay\"]";function log(label,payload){if(!cfg.debug||!window.console){return;}if(window.console.groupCollapsed){window.console.groupCollapsed(cfg.prefix+" "+label);}if(typeof payload!=="undefined"){try{window.console.log(payload);}catch(e){}}if(window.console.groupEnd){window.console.groupEnd();}}function copyAttributes(from,to){var attrs=from&&from.attributes?from.attributes:[];for(var i=0;i<attrs.length;i++){var attr=attrs[i];if(!attr||!attr.name){continue;}if("type"===attr.name||"data-wpfl-src"===attr.name||"data-wpfl-type"===attr.name||"data-wpfl-delayed"===attr.name||"data-wpfl-restored"===attr.name){continue;}to.setAttribute(attr.name,attr.value);}}function restoreScript(script){if(!script||script.getAttribute("data-wpfl-restored")==="1"||!script.parentNode){return null;}var restoredScript=document.createElement("script");copyAttributes(script,restoredScript);var source=script.getAttribute("data-wpfl-src")||"";var originalType=script.getAttribute("data-wpfl-type")||"";if(originalType){restoredScript.setAttribute("type",originalType);}if(source){restoredScript.async=false;restoredScript.setAttribute("src",source);}else{restoredScript.text=script.textContent||script.innerText||"";}restoredScript.setAttribute("data-wpfl-restored","1");script.setAttribute("data-wpfl-restored","1");script.parentNode.replaceChild(restoredScript,script);return{src:source,handle:script.getAttribute("data-wpfl-handle")||"inline"};}function getQueuedScripts(){return document.querySelectorAll(selector);}function getExcludedScripts(){return document.querySelectorAll("script[data-wpfl-delay-excluded=\"1\"]");}function removeListeners(){for(var i=0;i<cfg.events.length;i++){window.removeEventListener(cfg.events[i],onFirstInteraction,true);}}function restoreAll(reason){if(restored){return;}restored=true;removeListeners();if(fallbackTimer){window.clearTimeout(fallbackTimer);}var scripts=getQueuedScripts();var restoredCount=0;for(var i=0;i<scripts.length;i++){var restoredItem=restoreScript(scripts[i]);if(!restoredItem){continue;}restoredCount++;log("restored_script",restoredItem);}var elapsed=Date.now()-startedAt;log("execution_timing",{reason:reason,delayMs:elapsed,restored:restoredCount});try{document.dispatchEvent(new CustomEvent("wp-fastlayer:delay-restored",{detail:{reason:reason,restored:restoredCount,delayMs:elapsed}}));}catch(e){}}function onFirstInteraction(event){restoreAll(event&&event.type?event.type:"interaction");}for(var i=0;i<cfg.events.length;i++){var eventName=cfg.events[i];var listenerOptions=("scroll"===eventName||"mousemove"===eventName||"touchstart"===eventName)?{capture:true,passive:true,once:true}:{capture:true,once:true};window.addEventListener(eventName,onFirstInteraction,listenerOptions);}fallbackTimer=window.setTimeout(function(){restoreAll("timeout");},Math.max(1000,parseInt(cfg.timeout,10)||6000));var queued=getQueuedScripts();var queuedHandles=[];for(var q=0;q<queued.length;q++){queuedHandles.push(queued[q].getAttribute("data-wpfl-handle")||"inline");}var excluded=getExcludedScripts();var excludedHandles=[];for(var x=0;x<excluded.length;x++){excludedHandles.push(excluded[x].getAttribute("data-wpfl-handle")||"unknown");}log("delayed_scripts",{count:queued.length,handles:queuedHandles});log("excluded_scripts",{count:excluded.length,handles:excludedHandles});log("engine_initialized",{events:cfg.events,timeout:cfg.timeout});})(" . $config_json . ");</script>' . "\n";
    }

    private function get_exclude_css_patterns() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['exclude_css_files'] ) ? preg_split( '/\r?\n/', trim( $options['exclude_css_files'] ) ) : array();
    }

    private function get_exclude_js_patterns() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['exclude_js_files'] ) ? preg_split( '/\r?\n/', trim( $options['exclude_js_files'] ) ) : array();
    }

    private function get_exclude_js_defer_patterns() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['exclude_js_defer'] ) ? preg_split( '/\r?\n/', trim( $options['exclude_js_defer'] ) ) : array();
    }

    private function is_excluded_css( $url ) {
        $patterns = array_merge( $this->get_exclude_css_patterns(), $this->get_icon_css_exclusion_patterns() );

        return $this->is_excluded_by_patterns( $url, $patterns );
    }

    private function is_excluded_js( $url ) {
        return $this->is_excluded_by_patterns( $url, $this->get_exclude_js_patterns() );
    }

    private function is_excluded_js_defer( $url ) {
        return $this->is_excluded_by_patterns( $url, $this->get_exclude_js_defer_patterns() );
    }

    private function is_css_combine_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_css_combine'] ) && '1' === $options['enable_css_combine'];
    }

    private function is_js_combine_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_js_combine'] ) && '1' === $options['enable_js_combine'];
    }

    public function maybe_combine_css_tag( $tag, $handle, $href, $media ) {
        if ( ! $this->is_css_combine_enabled() || is_admin() || empty( $href ) ) {
            return $tag;
        }

        if ( $this->is_icon_stylesheet( $href ) ) {
            $this->debug_log( 'icon_stylesheet_detected', array( 'handle' => $handle, 'url' => $href, 'source' => 'combine_tag' ) );
            $this->debug_log( 'stylesheet_excluded_from_unused_css', array( 'handle' => $handle, 'url' => $href, 'source' => 'combine_tag' ) );
            return $tag;
        }

        if ( $this->is_excluded_css( $href ) || ! $this->is_internal_url( $href ) ) {
            return $tag;
        }

        if ( empty( $this->combined_css_groups ) ) {
            $this->combined_css_groups = $this->get_combined_css_groups();
        }

        $media_key = $this->get_css_group_key( $media );
        if ( ! isset( $this->combined_css_groups[ $media_key ] ) ) {
            return $tag;
        }

        $group_handles = $this->combined_css_groups[ $media_key ];
        if ( count( $group_handles ) < 2 || ! in_array( $handle, $group_handles, true ) ) {
            return $tag;
        }

        if ( ! isset( $this->combined_css_urls[ $media_key ] ) ) {
            $this->combined_css_urls[ $media_key ] = $this->generate_combined_css_url( $group_handles );
        }

        if ( $handle !== $group_handles[0] ) {
            return '';
        }

        return '<link rel="stylesheet" id="wp-fastlayer-combined-css-' . esc_attr( $media_key ) . '" href="' . esc_url( $this->combined_css_urls[ $media_key ] ) . '" media="' . esc_attr( $media ) . '" />\n';
    }

    public function maybe_combine_js_tag( $tag, $handle, $src ) {
        if ( ! $this->is_js_combine_enabled() || is_admin() || empty( $src ) ) {
            return $tag;
        }

        if ( $this->is_excluded_js( $src ) || ! $this->is_internal_url( $src ) ) {
            return $tag;
        }

        if ( empty( $this->combined_js_handles ) ) {
            $this->combined_js_handles = $this->get_combined_js_handles();
        }

        if ( count( $this->combined_js_handles ) < 2 || ! in_array( $handle, $this->combined_js_handles, true ) ) {
            return $tag;
        }

        if ( '' === $this->combined_js_url ) {
            $this->combined_js_url = $this->generate_combined_js_url();
        }

        if ( $handle !== $this->combined_js_handles[0] ) {
            return '';
        }

        $defer = $this->is_defer_enabled() && ! $this->is_excluded_js_defer( $src ) ? ' defer' : '';
        return '<script src="' . esc_url( $this->combined_js_url ) . '"' . $defer . '></script>\n';
    }

    private function get_combined_css_groups() {
        global $wp_styles;
        $groups = array();

        if ( ! isset( $wp_styles->queue ) || ! is_array( $wp_styles->queue ) ) {
            return $groups;
        }

        foreach ( $wp_styles->queue as $handle ) {
            if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
                continue;
            }

            $style = $wp_styles->registered[ $handle ];
            $href = $style->src;
            if ( empty( $href ) || $this->is_excluded_css( $href ) || ! $this->is_internal_url( $href ) ) {
                continue;
            }

            $path = $this->url_to_path( $href );
            if ( ! $path || ! is_readable( $path ) ) {
                continue;
            }

            $content = file_get_contents( $path );
            if ( $content && $this->should_skip_css_minification( $content ) ) {
                continue;
            }

            if ( $this->is_icon_stylesheet( $href, is_string( $content ) ? $content : '' ) ) {
                $this->debug_log( 'icon_stylesheet_detected', array( 'handle' => $handle, 'url' => $href, 'source' => 'combined_css_groups' ) );
                $this->debug_log( 'stylesheet_excluded_from_unused_css', array( 'handle' => $handle, 'url' => $href, 'source' => 'combined_css_groups' ) );
                continue;
            }

            $media = $this->get_style_media( $style );
            $media_key = $this->get_css_group_key( $media );
            $groups[ $media_key ][] = $handle;
        }

        return $groups;
    }

    private function get_style_media( $style ) {
        if ( ! empty( $style->args['media'] ) ) {
            return trim( $style->args['media'] );
        }

        return ! empty( $style->media ) ? trim( $style->media ) : 'all';
    }

    private function get_css_group_key( $media ) {
        $media = trim( strtolower( $media ) );
        if ( '' === $media ) {
            return 'all';
        }

        return preg_replace( '/[^a-z0-9_-]+/', '-', $media );
    }

    // private function get_combined_js_handles() {
    //     global $wp_scripts;
    //     $handles = array();

    //     if ( ! isset( $wp_scripts->queue ) || ! is_array( $wp_scripts->queue ) ) {
    //         return $handles;
    //     }

    //     foreach ( $wp_scripts->queue as $handle ) {
    //         if ( ! isset( $wp_scripts->registered[ $handle ] ) ) {
    //             continue;
    //         }

    //         $script = $wp_scripts->registered[ $handle ];
    //         if ( empty( $script->src ) || $this->is_excluded_js( $script->src ) || ! $this->is_internal_url( $script->src ) ) {
    //             continue;
    //         }

    //         $path = $this->url_to_path( $script->src );
    //         if ( ! $path || ! is_readable( $path ) ) {
    //             continue;
    //         }

    //         $handles[] = $handle;
    //     }

    //     return $handles;
    // }

    private function is_excluded_by_patterns( $url, $patterns ) {
        foreach ( $patterns as $pattern ) {
            $pattern = trim( $pattern );
            if ( '' === $pattern ) {
                continue;
            }

            if ( $this->pattern_matches_url( $pattern, $url ) ) {
                return true;
            }
        }

        return false;
    }

    private function pattern_matches_url( $pattern, $url ) {
        $pattern = trim( $pattern );
        if ( '' === $pattern || '' === $url ) {
            return false;
        }

        if ( preg_match( '#^/.*/[a-z]*$#i', $pattern ) ) {
            return preg_match( $pattern, $url ) === 1;
        }

        $url = str_replace( '\\', '/', $url );
        $pattern = str_replace( '\\', '/', $pattern );

        $url_path = $this->strip_url_domain( $url );
        $pattern_path = $this->strip_url_domain( $pattern );

        if ( false !== strpos( $pattern, '*' ) || false !== strpos( $pattern, '(.*)' ) ) {
            $regex = '#^' . str_replace( array( '\\\*', '\\(\.\*\\)' ), array( '.*', '.*' ), preg_quote( $pattern, '#' ) ) . '$#i';
            return preg_match( $regex, $url ) === 1 || preg_match( $regex, $url_path ) === 1;
        }

        if ( filter_var( $pattern, FILTER_VALIDATE_URL ) ) {
            return stripos( $url, $pattern ) !== false || stripos( $url_path, $pattern_path ) !== false;
        }

        return stripos( $url, $pattern ) !== false || stripos( $url_path, $pattern_path ) !== false;
    }

    private function strip_url_domain( $url ) {
        $parsed = wp_parse_url( $url );
        if ( empty( $parsed['path'] ) ) {
            return $url;
        }

        $path = $parsed['path'];
        if ( isset( $parsed['query'] ) ) {
            $path .= '?' . $parsed['query'];
        }

        return $path;
    }

    private function is_internal_url( $url ) {
        $parsed = wp_parse_url( $url );
        if ( empty( $parsed['host'] ) ) {
            return true;
        }

        $site = wp_parse_url( site_url() );
        return isset( $site['host'] ) && strtolower( $parsed['host'] ) === strtolower( $site['host'] );
    }

    public function minify_css() {
        if ( ! $this->is_css_enabled() || is_admin() ) {
            return;
        }

        global $wp_styles;
        if ( ! isset( $wp_styles->registered ) || ! is_array( $wp_styles->registered ) ) {
            return;
        }

        foreach ( $wp_styles->registered as $handle => $style ) {
            if ( ! empty( $style->src ) && false !== strpos( $style->src, '.css' ) && ! $this->is_excluded_css( $style->src ) ) {
                if ( ! $this->is_internal_url( $style->src ) ) {
                    $this->debug_log(
                        'skipped_remote_stylesheet',
                        array(
                            'handle' => $handle,
                            'url'    => $style->src,
                        )
                    );
                    continue;
                }

                $style->src = $this->minify_css_content( $style->src );
            }
        }
    }

    public function minify_js() {
        if ( ! $this->is_js_enabled() || is_admin() ) {
            return;
        }

        global $wp_scripts;
        if ( ! isset( $wp_scripts->registered ) || ! is_array( $wp_scripts->registered ) ) {
            return;
        }

        foreach ( $wp_scripts->registered as $handle => $script ) {
            if ( ! empty( $script->src ) && false !== strpos( $script->src, '.js' ) && ! $this->is_excluded_js( $script->src ) ) {
                $script->src = $this->minify_js_content( $script->src );
            }
        }
    }

    private function minify_css_content( $url ) {
        if ( ! $this->is_internal_url( $url ) ) {
            $this->debug_log( 'skipped_remote_stylesheet', array( 'url' => $url ) );
            return $url;
        }

        $content = $this->get_file_content( $url );
        if ( ! $content ) {
            return $url;
        }

        if ( $this->should_skip_css_minification( $content ) ) {
            return $url;
        }

        $content = preg_replace( '!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $content );
        $content = str_replace( array( "\r\n", "\r", "\n", "\t" ), '', $content );
        $content = preg_replace( '/\s+/', ' ', $content );

        $content = $this->rewrite_css_urls( $content, $url );

        return $this->save_minified( $content, 'css', $url );
    }

    private function should_skip_css_minification( $content ) {
        if ( ! is_string( $content ) ) {
            return false;
        }

        return false;
    }

    public function enforce_google_fonts_display_swap( $src, $handle ) {
        if ( ! $this->is_google_fonts_display_enabled() || ! is_string( $src ) || '' === $src ) {
            return $src;
        }

        $parsed = wp_parse_url( $src );
        $host = isset( $parsed['host'] ) ? strtolower( (string) $parsed['host'] ) : '';
        if ( '' === $host || false === strpos( $host, 'fonts.googleapis.com' ) ) {
            return $src;
        }

        if ( $this->is_google_fonts_icon_family_url( $src, $icon_system ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                \wp_fastlayer_debug_log( '[WP FastLayer Fonts] skipped_icon_google_font=' . $src . ' reason=' . $icon_system );
            }
            return $src;
        }

        if ( false !== stripos( $src, 'display=' ) ) {
            return $src;
        }

        $updated = add_query_arg( 'display', 'swap', $src );
        if ( is_string( $updated ) && '' !== $updated ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                \wp_fastlayer_debug_log( '[WP FastLayer Fonts] optimized_google_fonts_url=' . $updated . ' reason=display_swap_added' );
            }
            return $updated;
        }

        return $src;
    }

    private function is_google_fonts_display_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_google_fonts_display'] ) && '1' === $options['enable_google_fonts_display'];
    }

    private function has_embedded_or_svg_font_source( $font_face_block ) {
        $block_lc = strtolower( (string) $font_face_block );

        if ( false !== strpos( $block_lc, 'data:font/' ) || false !== strpos( $block_lc, 'base64,' ) ) {
            return true;
        }

        if ( preg_match( '/url\(\s*(["\']?)[^\)\"\']*\.svg(?:[?#][^\)\"\']*)?\1\s*\)/i', $font_face_block ) ) {
            return true;
        }

        if ( false !== strpos( $block_lc, 'format("svg")' ) || false !== strpos( $block_lc, "format('svg')") || false !== strpos( $block_lc, 'format(svg)' ) ) {
            return true;
        }

        return false;
    }

    private function is_google_fonts_icon_family_url( $src, &$detected_system = '' ) {
        $detected_system = '';
        $cache_key = md5( (string) $src );
        if ( isset( $this->google_fonts_icon_family_cache[ $cache_key ] ) ) {
            $cached = $this->google_fonts_icon_family_cache[ $cache_key ];
            $detected_system = isset( $cached['system'] ) ? (string) $cached['system'] : '';
            return ! empty( $cached['is_icon'] );
        }

        $query = wp_parse_url( $src, PHP_URL_QUERY );
        if ( ! is_string( $query ) || '' === $query ) {
            $this->google_fonts_icon_family_cache[ $cache_key ] = array( 'is_icon' => false, 'system' => '' );
            return false;
        }

        parse_str( $query, $args );
        $family_arg = '';
        if ( isset( $args['family'] ) ) {
            $family_arg = is_array( $args['family'] ) ? implode( ',', $args['family'] ) : (string) $args['family'];
        }

        $family_lc = strtolower( str_replace( '+', ' ', $family_arg ) );
        $systems = array(
            'material icons' => 'Material Icons',
            'material symbols' => 'Material Symbols',
        );

        foreach ( $systems as $needle => $system ) {
            if ( '' !== $needle && false !== strpos( $family_lc, $needle ) ) {
                $detected_system = $system;
                $this->google_fonts_icon_family_cache[ $cache_key ] = array( 'is_icon' => true, 'system' => $system );
                return true;
            }
        }

        $this->google_fonts_icon_family_cache[ $cache_key ] = array( 'is_icon' => false, 'system' => '' );
        return false;
    }
    private function get_icon_css_exclusion_patterns() {
        return array(
            'material-icons',
            'material-symbols',
            'fontawesome',
            'font-awesome',
            'eicons',
            'dashicons',
            'elementor-icons',
            'icon-font',
            'iconpicker',
            'glyphicons',
            'mdi-',
            '/(?:^|[-_\/])fa(?:[-_]|$)/i',
            '/(?:^|[-_\/])eicon(?:[-_]|$)/i',
        );
    }

    private function is_icon_stylesheet( $url, $content = '' ) {
        $candidate = strtolower( (string) $url );

        foreach ( $this->get_icon_css_exclusion_patterns() as $pattern ) {
            if ( $this->pattern_matches_url( $pattern, $candidate ) ) {
                return true;
            }
        }

        if ( ! is_string( $content ) || '' === $content ) {
            return false;
        }

        $checks = array(
            '/@font-face\s*\{/i',
            '/::?(before|after)\b/i',
            '/content\s*:\s*["\']\\[0-9a-f]{2,6}["\']/i',
            '/font-family\s*:\s*["\']?(?:material\s*icons|material\s*symbols|font\s*awesome|fontawesome|eicons?|elementor-icons|dashicons|glyphicons|mdi|icon)["\']?/i',
            '/\.(?:fa-[\w-]+|eicon-[\w-]+|mdi-[\w-]+)\b/i',
            '/url\([^)]*\.(?:woff2?|ttf|otf)(?:[?#][^)]*)?\)/i',
        );

        foreach ( $checks as $regex ) {
            if ( preg_match( $regex, $content ) ) {
                return true;
            }
        }

        return false;
    }

    private function minify_js_content( $url ) {
        $content = $this->get_file_content( $url );
        if ( ! $content ) {
            return $url;
        }

        $content = preg_replace( '!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $content );
        $content = str_replace( array( "\r\n", "\r", "\n", "\t" ), ' ', $content );
        $content = preg_replace( '/\s+/', ' ', $content );

        return $this->save_minified( $content, 'js', $url );
    }

    public function maybe_add_css_preload( $tag, $handle, $href, $media ) {
        if ( ! isset( $GLOBALS['wp_styles'] ) || ( ! $this->is_css_delivery_enabled() && ! $this->is_css_remove_unused_enabled() ) ) {
            return $tag;
        }

        if ( is_admin() || empty( $href ) ) {
            return $tag;
        }

        if ( $this->is_excluded_css( $href ) || ! $this->is_internal_url( $href ) ) {
            return $tag;
        }

        $content = $this->get_file_content( $href );
        if ( $this->is_icon_stylesheet( $href, is_string( $content ) ? $content : '' ) ) {
            $this->debug_log( 'icon_stylesheet_detected', array( 'handle' => $handle, 'url' => $href, 'source' => 'css_preload' ) );
            $this->debug_log( 'stylesheet_excluded_from_unused_css', array( 'handle' => $handle, 'url' => $href, 'source' => 'css_preload' ) );
            $this->debug_log( 'critical_css_skipped_for_icon_stylesheet', array( 'handle' => $handle, 'url' => $href ) );
            return $tag;
        }

        if ( false !== strpos( $tag, 'rel="preload"' ) || false !== strpos( $tag, 'rel="stylesheet"' ) ) {
            return sprintf(
                '<link rel="preload" href="%s" as="style" onload="this.onload=null;this.rel=\'stylesheet\'" media="%s"> <noscript>%s</noscript>',
                esc_url( $href ),
                esc_attr( $media ),
                $tag
            );
        }

        return $tag;
    }

    private function is_css_delivery_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_css_delivery'] ) && '1' === $options['enable_css_delivery'];
    }

    private function is_css_remove_unused_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_css_remove_unused'] ) && '1' === $options['enable_css_remove_unused'];
    }

    private function is_smart_gutenberg_css_optimization_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return ! isset( $options['enable_smart_gutenberg_css_optimization'] ) || '1' === (string) $options['enable_smart_gutenberg_css_optimization'];
    }

    public function debug_trace_block_css_registration( $wp_styles ) {
        $this->trace_block_css_state_snapshot( 'wp_default_styles' );

        if ( ! ( $wp_styles instanceof \WP_Styles ) ) {
            return;
        }

        foreach ( $this->tracked_block_css_handles as $handle ) {
            $registered = isset( $wp_styles->registered[ $handle ] );
            if ( $registered ) {
                $this->debug_log_css_state( $handle, false, 'wp_default_styles_registered' );
            }
        }
    }

    public function maybe_optimize_gutenberg_block_css() {
        $this->maybe_optimize_gutenberg_block_css_by_timing( 'wp_enqueue_scripts_999' );
    }

    public function maybe_optimize_gutenberg_block_css_on_print_styles() {
        $this->maybe_optimize_gutenberg_block_css_by_timing( 'wp_print_styles' );
    }

    public function maybe_optimize_gutenberg_block_css_on_footer_fallback() {
        $this->maybe_optimize_gutenberg_block_css_by_timing( 'wp_footer_fallback' );
        $this->trace_block_css_state_snapshot( 'wp_footer_final' );
    }

    private function maybe_optimize_gutenberg_block_css_by_timing( $timing_label ) {
        $this->trace_block_css_state_snapshot( $timing_label . '_before' );

        $detected_blocks = array();

        if ( ! $this->is_css_remove_unused_enabled() || ! $this->is_smart_gutenberg_css_optimization_enabled() ) {
            $skip_reason = ! $this->is_css_remove_unused_enabled() ? 'remove_unused_css_disabled' : 'smart_gutenberg_optimization_disabled';
            foreach ( $this->tracked_block_css_handles as $handle ) {
                $this->debug_log_css_state( $handle, false, $skip_reason );
            }
            return;
        }

        $skip_reason = $this->get_gutenberg_css_skip_reason();
        if ( '' !== $skip_reason ) {
            foreach ( $this->tracked_block_css_handles as $handle ) {
                $this->debug_log_css_state( $handle, false, $skip_reason );
            }
            return;
        }

        if ( $this->is_elementor_context() ) {
            foreach ( $this->tracked_block_css_handles as $handle ) {
                $this->debug_log_css_state( $handle, false, 'elementor_page' );
            }
            return;
        }

        if ( $this->is_woocommerce_context() ) {
            foreach ( $this->tracked_block_css_handles as $handle ) {
                $this->debug_log_css_state( $handle, false, 'woocommerce_page' );
            }
            return;
        }

        $block_analysis = $this->detect_current_page_blocks();
        $detected_blocks = isset( $block_analysis['detected_blocks'] ) && is_array( $block_analysis['detected_blocks'] ) ? $block_analysis['detected_blocks'] : array();
        if ( ! empty( $block_analysis['has_blocks'] ) ) {
            foreach ( $this->tracked_block_css_handles as $handle ) {
                $this->debug_log_css_state( $handle, false, 'block_usage_detected', $detected_blocks );
            }
            return;
        }

        foreach ( $this->tracked_block_css_handles as $handle ) {
            wp_dequeue_style( $handle );
            $this->dequeued_gutenberg_styles[ $handle ] = true;
            $this->debug_log_css_state( $handle, true, 'dequeued_' . $timing_label, $detected_blocks );
        }

        $this->trace_block_css_state_snapshot( $timing_label . '_after' );
    }

    public function print_block_css_console_report() {
        if ( is_admin() ) {
            return;
        }

        $this->trace_block_css_state_snapshot( 'wp_footer_console' );

        $report = array(
            'registered' => array_values( array_unique( $this->block_css_console_report['registered'] ) ),
            'enqueued'   => array_values( array_unique( $this->block_css_console_report['enqueued'] ) ),
            'dequeued'   => array_values( array_unique( $this->block_css_console_report['dequeued'] ) ),
            'finalState' => $this->block_css_console_report['finalState'],
        );

        $json = wp_json_encode( $report );
        if ( ! is_string( $json ) || '' === $json ) {
            return;
        }

        echo '<script id="wp-fastlayer-block-css-debug">window.WPFastLayerBlockCSS=' . $json . ';</script>' . "\n";
    }

    private function trace_block_css_state_snapshot( $stage ) {
        foreach ( $this->tracked_block_css_handles as $handle ) {
            $registered = wp_style_is( $handle, 'registered' );
            $enqueued = wp_style_is( $handle, 'enqueued' );
            $done = wp_style_is( $handle, 'done' );
            $dequeued = ! empty( $this->dequeued_gutenberg_styles[ $handle ] );

            if ( $registered ) {
                $this->block_css_console_report['registered'][] = $handle;
            }

            if ( $enqueued ) {
                $this->block_css_console_report['enqueued'][] = $handle;
            }

            if ( $dequeued ) {
                $this->block_css_console_report['dequeued'][] = $handle;
            }

            if ( false !== strpos( (string) $stage, 'final' ) || false !== strpos( (string) $stage, 'console' ) ) {
                $this->block_css_console_report['finalState'][] = array(
                    'stage'      => $stage,
                    'handle'     => $handle,
                    'registered' => $registered,
                    'enqueued'   => $enqueued,
                    'done'       => $done,
                    'dequeued'   => $dequeued,
                );
            }

            $this->debug_log_css_state( $handle, $dequeued, $stage );
        }
    }

    private function debug_log_css_state( $handle, $dequeued, $skip_reason, $detected_blocks = array() ) {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }

        $registered = wp_style_is( $handle, 'registered' ) ? '1' : '0';
        $enqueued = wp_style_is( $handle, 'enqueued' ) ? '1' : '0';
        $done = wp_style_is( $handle, 'done' ) ? '1' : '0';
        $dequeued_flag = $dequeued ? '1' : '0';
        $current_hook = (string) current_action();
        $blocks = is_array( $detected_blocks ) ? implode( ',', $detected_blocks ) : '';
        $enqueue_hook_count = (string) did_action( 'wp_enqueue_scripts' );

        \wp_fastlayer_debug_log(
            '[WP FastLayer CSS DEBUG] handle=' . (string) $handle .
            ' registered=' . $registered .
            ' enqueued=' . $enqueued .
            ' done=' . $done .
            ' dequeued=' . $dequeued_flag .
            ' current_hook=' . $current_hook .
            ' skip_reason=' . (string) $skip_reason .
            ' did_action_wp_enqueue_scripts=' . $enqueue_hook_count .
            ' detected_blocks=' . $blocks
        );
    }

    private function get_gutenberg_css_skip_reason() {
        if ( is_admin() ) {
            return 'admin';
        }

        if ( is_customize_preview() ) {
            return 'customizer';
        }

        if ( $this->is_login_request() ) {
            return 'login';
        }

        if ( wp_doing_ajax() ) {
            return 'ajax';
        }

        if ( wp_doing_cron() ) {
            return 'cron';
        }

        if ( $this->is_rest_request() ) {
            return 'rest_api';
        }

        if ( is_preview() || ! empty( $_GET['preview'] ) ) {
            return 'preview';
        }

        return '';
    }

    private function is_login_request() {
        $pagenow = isset( $GLOBALS['pagenow'] ) ? (string) $GLOBALS['pagenow'] : '';
        if ( 'wp-login.php' === $pagenow ) {
            return true;
        }

        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        return false !== stripos( $request_uri, 'wp-login.php' );
    }

    private function is_rest_request() {
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return true;
        }

        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        if ( '' === $request_uri || ! function_exists( 'rest_get_url_prefix' ) ) {
            return false;
        }

        $prefix = trim( (string) rest_get_url_prefix(), '/' );
        if ( '' === $prefix ) {
            return false;
        }

        return false !== strpos( $request_uri, '/' . $prefix . '/' );
    }

    private function is_elementor_context() {
        if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\\Elementor\\Plugin' ) ) {
            return false;
        }

        if ( ! empty( $_GET['elementor-preview'] ) ) {
            return true;
        }

        $post_id = get_queried_object_id();
        if ( $post_id <= 0 ) {
            return false;
        }

        $plugin = \Elementor\Plugin::$instance;
        if ( ! $plugin || ! isset( $plugin->documents ) || ! method_exists( $plugin->documents, 'get' ) ) {
            return false;
        }

        $document = $plugin->documents->get( $post_id );
        return $document && method_exists( $document, 'is_built_with_elementor' ) && $document->is_built_with_elementor();
    }

    private function is_woocommerce_context() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return false;
        }

        $checks = array( 'is_woocommerce', 'is_shop', 'is_product', 'is_product_category', 'is_product_tag', 'is_cart', 'is_checkout', 'is_account_page' );
        foreach ( $checks as $check ) {
            if ( function_exists( $check ) && $check() ) {
                return true;
            }
        }

        return false;
    }

    private function detect_current_page_blocks() {
        $analysis = array(
            'has_blocks'      => false,
            'detected_blocks' => array(),
        );

        if ( ! is_singular() ) {
            return $analysis;
        }

        $post_id = get_queried_object_id();
        if ( $post_id <= 0 ) {
            return $analysis;
        }

        $post = get_post( $post_id );
        if ( ! ( $post instanceof \WP_Post ) ) {
            return $analysis;
        }

        $content = isset( $post->post_content ) ? (string) $post->post_content : '';
        if ( '' === $content ) {
            return $analysis;
        }

        if ( function_exists( 'has_blocks' ) && has_blocks( $content ) ) {
            $analysis['has_blocks'] = true;
        }

        if ( function_exists( 'parse_blocks' ) ) {
            $parsed_blocks = parse_blocks( $content );
            if ( is_array( $parsed_blocks ) ) {
                $analysis['detected_blocks'] = $this->collect_block_names( $parsed_blocks );
                if ( ! empty( $analysis['detected_blocks'] ) ) {
                    $analysis['has_blocks'] = true;
                }
            }
        }

        if ( ! $analysis['has_blocks'] && false !== strpos( $content, '<!-- wp:' ) ) {
            $analysis['has_blocks'] = true;
        }

        return $analysis;
    }

    private function collect_block_names( $blocks ) {
        $names = array();

        if ( ! is_array( $blocks ) ) {
            return $names;
        }

        foreach ( $blocks as $block ) {
            if ( ! is_array( $block ) ) {
                continue;
            }

            if ( ! empty( $block['blockName'] ) && is_string( $block['blockName'] ) ) {
                $names[] = $block['blockName'];
            }

            if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
                $names = array_merge( $names, $this->collect_block_names( $block['innerBlocks'] ) );
            }
        }

        $names = array_values( array_unique( array_filter( $names ) ) );
        return $names;
    }

    private function is_css_inline_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_css_inline'] ) && '1' === $options['enable_css_inline'];
    }

    private function is_critical_css_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_critical_css'] ) && '1' === $options['enable_critical_css'];
    }

    private function has_critical_css() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return ! empty( $options['critical_css_custom'] );
    }

    private function get_critical_css() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['critical_css_custom'] ) ? $options['critical_css_custom'] : '';
    }

    public function print_inline_critical_css() {
        if ( is_admin() || ! ( $this->is_css_inline_enabled() || $this->is_critical_css_enabled() ) || ! $this->has_critical_css() ) {
            return;
        }

        $css = trim( $this->get_critical_css() );
        if ( empty( $css ) ) {
            return;
        }

        echo "<style id=\"wp-fastlayer-critical-css\">" . $css . "</style>\n";
    }

    private function generate_combined_css_url( array $handles ) {
        global $wp_styles;

        if ( empty( $handles ) ) {
            return '';
        }

        $upload_dir = wp_upload_dir();
        $min_dir    = trailingslashit( $upload_dir['basedir'] ) . 'wp-fastlayer-min/';
        wp_mkdir_p( $min_dir );

        $mtime = array();
        foreach ( $handles as $handle ) {
            if ( isset( $wp_styles->registered[ $handle ] ) ) {
                $path = $this->url_to_path( $wp_styles->registered[ $handle ]->src );
                if ( $path && file_exists( $path ) ) {
                    $mtime[] = filemtime( $path );
                }
            }
        }
        $hash = md5( implode( ',', $handles ) . ':' . implode( ',', $mtime ) . ':' . (int) $this->is_css_enabled() );
        $filename = 'combined-' . $hash . '.css';
        $filepath = $min_dir . $filename;

        $content = '';
        foreach ( $handles as $handle ) {
            if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
                continue;
            }

            $src = $wp_styles->registered[ $handle ]->src;
            $file = $this->url_to_path( $src );
            if ( ! $file || ! is_readable( $file ) ) {
                continue;
            }

            $file_content = file_get_contents( $file );
            if ( ! $file_content ) {
                continue;
            }

            if ( $this->is_css_enabled() ) {
                $file_content = preg_replace( '!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $file_content );
                $file_content = str_replace( array( "\r\n", "\r", "\n", "\t" ), '', $file_content );
                $file_content = preg_replace( '/\s+/', ' ', $file_content );
                $file_content = $this->rewrite_css_urls( $file_content, $src );
            }

            $content .= "\n/* {$handle} */\n" . $file_content;
        }

        if ( $content ) {
            file_put_contents( $filepath, trim( $content ), LOCK_EX );
        }

        return $upload_dir['baseurl'] . '/wp-fastlayer-min/' . $filename;
    }

    private function generate_combined_js_url() {
        global $wp_scripts;

        if ( empty( $this->combined_js_handles ) ) {
            return '';
        }

        $upload_dir = wp_upload_dir();
        $min_dir    = trailingslashit( $upload_dir['basedir'] ) . 'wp-fastlayer-min/';
        wp_mkdir_p( $min_dir );

        $mtime = array();
        foreach ( $this->combined_js_handles as $handle ) {
            if ( isset( $wp_scripts->registered[ $handle ] ) ) {
                $path = $this->url_to_path( $wp_scripts->registered[ $handle ]->src );
                if ( $path && file_exists( $path ) ) {
                    $mtime[] = filemtime( $path );
                }
            }
        }
        $hash = md5( implode( ',', $this->combined_js_handles ) . ':' . implode( ',', $mtime ) . ':' . (int) $this->is_js_enabled() );
        $filename = 'combined-' . $hash . '.js';
        $filepath = $min_dir . $filename;

        $content = '';
        foreach ( $this->combined_js_handles as $handle ) {
            if ( ! isset( $wp_scripts->registered[ $handle ] ) ) {
                continue;
            }

            $src = $wp_scripts->registered[ $handle ]->src;
            $file = $this->url_to_path( $src );
            if ( ! $file || ! is_readable( $file ) ) {
                continue;
            }

            $file_content = file_get_contents( $file );
            if ( ! $file_content ) {
                continue;
            }

            if ( $this->is_js_enabled() ) {
                $file_content = preg_replace( '!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $file_content );
                $file_content = str_replace( array( "\r\n", "\r", "\n", "\t" ), ' ', $file_content );
                $file_content = preg_replace( '/\s+/', ' ', $file_content );
            }

            $content .= "\n/* {$handle} */\n" . $file_content;
        }

        if ( $content ) {
            file_put_contents( $filepath, trim( $content ), LOCK_EX );
        }

        return $upload_dir['baseurl'] . '/wp-fastlayer-min/' . $filename;
    }

    private function get_combined_js_handles() {
        global $wp_scripts;
        $handles = array();

        if ( ! isset( $wp_scripts->queue ) || ! is_array( $wp_scripts->queue ) ) {
            return $handles;
        }

        foreach ( $wp_scripts->queue as $handle ) {
            if ( ! isset( $wp_scripts->registered[ $handle ] ) ) {
                continue;
            }

            $script = $wp_scripts->registered[ $handle ];
            if ( empty( $script->src ) || $this->is_excluded_js( $script->src ) || ! $this->is_internal_url( $script->src ) ) {
                continue;
            }

            $path = $this->url_to_path( $script->src );
            if ( ! $path || ! is_readable( $path ) ) {
                continue;
            }

            $handles[] = $handle;
        }

        return $handles;
    }

    private function get_file_content( $url ) {
        $path = $this->url_to_path( $url );
        if ( ! $path || ! is_readable( $path ) ) {
            return false;
        }

        return file_get_contents( $path );
    }

    private function url_to_path( $url ) {
        $url        = esc_url_raw( $url );
        $base_url   = site_url();
        $parsed_url = wp_parse_url( $url );

        if ( empty( $parsed_url['host'] ) || empty( $parsed_url['path'] ) ) {
            return false;
        }

        if ( strpos( $url, $base_url ) !== 0 ) {
            return false;
        }

        return ABSPATH . ltrim( str_replace( $base_url, '', $url ), '/' );
    }

    private function rewrite_css_urls( $content, $original_url ) {
        $parsed_url = wp_parse_url( $original_url );
        if ( empty( $parsed_url['scheme'] ) || empty( $parsed_url['host'] ) || empty( $parsed_url['path'] ) ) {
            return $content;
        }

        $base_path = trailingslashit( dirname( $parsed_url['path'] ) );
        $base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'] . $base_path;

        $content = preg_replace_callback(
            '/url\(\s*("|\')?(.*?)\1\s*\)/i',
            function( $matches ) use ( $parsed_url ) {
                $quote = $matches[1] ?: '';
                $url = trim( $matches[2] );

                if ( preg_match( '#^(data:|https?:|//|/|\#)#i', $url ) ) {
                    return 'url(' . $quote . $url . $quote . ')';
                }

                $base_path = trailingslashit( dirname( $parsed_url['path'] ) );
                while ( strpos( $url, '../' ) === 0 ) {
                    $base_path = trailingslashit( dirname( untrailingslashit( $base_path ) ) );
                    $url = substr( $url, 3 );
                }
                if ( strpos( $url, './' ) === 0 ) {
                    $url = substr( $url, 2 );
                }

                $resolved = $parsed_url['scheme'] . '://' . $parsed_url['host'] . $base_path . ltrim( $url, '/' );
                return 'url(' . $quote . $resolved . $quote . ')';
            },
            $content
        );

        $content = preg_replace_callback(
            '/@import\s+(?:url\()?\s*("|\')?(.*?)\1\s*\)?/i',
            function( $matches ) use ( $parsed_url ) {
                $quote = $matches[1] ?: '';
                $url = trim( $matches[2] );

                if ( preg_match( '#^(https?:|//|/|\#)#i', $url ) ) {
                    return '@import url(' . $quote . $url . $quote . ')';
                }

                $base_path = trailingslashit( dirname( $parsed_url['path'] ) );
                while ( strpos( $url, '../' ) === 0 ) {
                    $base_path = trailingslashit( dirname( untrailingslashit( $base_path ) ) );
                    $url = substr( $url, 3 );
                }
                if ( strpos( $url, './' ) === 0 ) {
                    $url = substr( $url, 2 );
                }

                $resolved = $parsed_url['scheme'] . '://' . $parsed_url['host'] . $base_path . ltrim( $url, '/' );
                return '@import url(' . $quote . $resolved . $quote . ')';
            },
            $content
        );

        return $content;
    }

    private function save_minified( $content, $type, $original_url ) {
        $upload_dir = wp_upload_dir();
        $min_dir    = $upload_dir['basedir'] . '/wp-fastlayer-min/';
        wp_mkdir_p( $min_dir );

        $filename = sanitize_file_name( md5( $content ) . '.' . $type );
        $filepath = $min_dir . $filename;

        if ( file_exists( $filepath ) ) {
            return $upload_dir['baseurl'] . '/wp-fastlayer-min/' . $filename;
        }

        if ( $this->is_safe_path( $filepath ) ) {
            file_put_contents( $filepath, $content, LOCK_EX );
            return $upload_dir['baseurl'] . '/wp-fastlayer-min/' . $filename;
        }

        return $original_url;
    }

    private function is_safe_path( $path ) {
        $upload_dir = wp_upload_dir();
        $base_realpath = realpath( $upload_dir['basedir'] );
        if ( false === $base_realpath ) {
            return false;
        }

        $path = is_dir( $path ) ? $path : dirname( $path );
        $realpath = realpath( $path );
        if ( false === $realpath ) {
            return false;
        }

        return 0 === strpos( $realpath, $base_realpath );
    }

    private function debug_log( $message, $context = array() ) {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }

        $payload = is_array( $context ) ? $context : array();
        \wp_fastlayer_debug_log( '[WP FastLayer Font Display] ' . $message . ( ! empty( $payload ) ? ' | ' . wp_json_encode( $payload ) : '' ) );
    }

    private function debug_log_smart_delay( $message, $context = array() ) {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }

        $payload = is_array( $context ) ? $context : array();
        \wp_fastlayer_debug_log( '[WP FastLayer Delay] ' . $message . ( ! empty( $payload ) ? ' | ' . wp_json_encode( $payload ) : '' ) );
    }

    public function start_html_buffer() {
        if ( ! $this->should_start_buffer() || is_admin() || is_feed() || is_preview() ) {
            return;
        }

        ob_start( array( $this, 'minify_html' ) );
    }

    private function should_start_buffer() {
        return $this->is_html_minify_enabled() || $this->is_css_enabled();
    }

    private function is_html_minify_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_minify'] ) && $options['enable_minify'] === '1';
    }

    public function minify_html( $html ) {
        if ( empty( $html ) ) {
            return $html;
        }

        $options = get_option( 'wp_fastlayer_options', array() );
        $placeholders = array();
        $html = $this->preserve_blocks( $html, $placeholders );

        if ( $this->is_html_minify_enabled() ) {
            $html = $this->remove_comments( $html, isset( $options['enable_html_preserve_gutenberg_comments'] ) && '1' === $options['enable_html_preserve_gutenberg_comments'] );
            $html = $this->remove_whitespace( $html );
        }

        $html = $this->restore_blocks( $html, $placeholders );

        return $html;
    }

    private function remove_comments( $html, $preserve_gutenberg = true ) {
        if ( $preserve_gutenberg ) {
            return preg_replace( '/<!--(?!\[if|\s*wp:|\s*\/wp:)[\s\S]*?-->/', '', $html );
        }

        return preg_replace( '/<!--(?!\[if)[\s\S]*?-->/', '', $html );
    }

    private function remove_whitespace( $html ) {
        $html = preg_replace( '/\s+/u', ' ', $html );
        $html = str_replace( array( '> <', '>  <', '>   <', '>    <' ), '><', $html );
        return $html;
    }

    private function preserve_blocks( $html, array &$placeholders ) {
        $patterns = array(
            '#<script\b[^>]*>.*?<\/script>#is',
            '#<style\b[^>]*>.*?<\/style>#is',
            '#<pre\b[^>]*>.*?<\/pre>#is',
            '#<textarea\b[^>]*>.*?<\/textarea>#is',
        );

        foreach ( $patterns as $pattern ) {
            $html = preg_replace_callback( $pattern, function( $matches ) use ( &$placeholders ) {
                $block = $matches[0];
                if ( preg_match( '#^<style\b#i', $block ) && $this->is_css_enabled() ) {
                    $block = $this->minify_inline_style_block( $block );
                }

                $placeholder = '%%WPFL_PLACEHOLDER_' . count( $placeholders ) . '%%';
                $placeholders[ $placeholder ] = $block;
                return $placeholder;
            }, $html );
        }

        return $html;
    }

    private function restore_blocks( $html, array $placeholders ) {
        if ( empty( $placeholders ) ) {
            return $html;
        }

        return str_replace( array_keys( $placeholders ), array_values( $placeholders ), $html );
    }

    private function minify_inline_style_block( $style_block ) {
        return preg_replace_callback(
            '#^(<style\b[^>]*>)(.*?)(</style>)$#is',
            function( $matches ) {
                $css = $matches[2];

                if ( $this->should_skip_inline_style_minification( $matches[0] ) ) {
                    return $matches[1] . $css . $matches[3];
                }

                return $matches[1] . $this->minify_css_string( $css ) . $matches[3];
            },
            $style_block
        );
    }

    private function should_skip_inline_style_minification( $style_block ) {
        if ( preg_match( '/@font-face/i', $style_block ) ) {
            return true;
        }

        if ( preg_match( '/font-family\s*:\s*("|\')?(?:Material\s*Icons|eicons|elementor-icons|fontawesome|dashicons)/i', $style_block ) ) {
            return true;
        }

        return false;
    }

    private function minify_css_string( $css ) {
        $css = preg_replace( '!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css );

        $placeholders = array();
        $css = preg_replace_callback(
            '/("[^"]*"|\'[^\']*\'|url\(\s*[^)]*\s*\))/i',
            function( $matches ) use ( &$placeholders ) {
                $placeholder = '%%WPFL_CSS_PH_' . count( $placeholders ) . '%%';
                $placeholders[ $placeholder ] = $matches[0];
                return $placeholder;
            },
            $css
        );

        $css = preg_replace( '/\s+/', ' ', $css );
        $css = preg_replace( '/\s*([{};:>,])\s*/', '$1', $css );

        foreach ( $placeholders as $placeholder => $original ) {
            $css = str_replace( $placeholder, $original, $css );
        }

        return trim( $css );
    }

    public function enqueue_lazyload_scripts() {
        if ( ! $this->is_lazyload_enabled() || is_admin() ) return;
        wp_enqueue_script( 'wp-fastlayer-lazyload', WP_FASTLAYER_URL . 'assets/js/lazyload.js', array(), WP_FASTLAYER_VERSION, true );
    }

    private function is_lazyload_enabled() {
        $options = get_option( 'wp_fastlayer_options', array() );
        return isset( $options['enable_lazyload'] ) && $options['enable_lazyload'] === '1';
    }

    public function lazyload_images( $content ) {
        if ( ! $this->is_lazyload_enabled() || is_admin() || is_feed() ) return $content;

        $placeholders = array();
        $content = preg_replace_callback(
            '/<picture\b[^>]*>.*?<\/picture>/is',
            function ( $matches ) use ( &$placeholders ) {
                $key = '__WP_FASTLAYER_LAZYLOAD_PICTURE_' . count( $placeholders ) . '__';
                $placeholders[ $key ] = $matches[0];
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    \wp_fastlayer_debug_log( '[WP FastLayer Lazyload] picture_element_preserved | count=' . count( $placeholders ) );
                }
                return $key;
            },
            $content
        );

        $before_count = preg_match_all( '/<img([^>]+)src=/i', $content );
        $content = preg_replace( '/<img([^>]+)src=/i', '<img$1data-src=', $content );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            \wp_fastlayer_debug_log( '[WP FastLayer Lazyload] src_converted_to_data_src | count=' . absint( $before_count ) );
        }

        if ( ! empty( $placeholders ) ) {
            $content = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $content );
        }

        return $content;
    }
}

