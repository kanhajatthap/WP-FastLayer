<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$cache_dir = WP_CONTENT_DIR . '/cache/wp-fastlayer/';
$config_file = $cache_dir . 'config.php';
$options = array();
if ( file_exists( $config_file ) ) {
    $options = include $config_file;
}

$cache_expiration = 36000;
if ( isset( $options['cache_expiration'] ) ) {
    $cache_expiration = max( 1, (int) $options['cache_expiration'] ) * 3600;
}

function wp_fastlayer_cache_enabled() {
    global $options;
    return isset( $options['enable_cache'] ) && '1' === $options['enable_cache'];
}

function wp_fastlayer_should_skip_cache() {
    // Check logged in cookie first, since is_user_logged_in() is not defined early
    if ( isset( $_COOKIE ) && is_array( $_COOKIE ) ) {
        foreach ( $_COOKIE as $key => $value ) {
            if ( 0 === strpos( $key, 'wordpress_logged_in_' ) ) {
                return true;
            }
        }
    }

    if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
        return true;
    }

    if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
        return true;
    }

    if ( function_exists( 'is_admin' ) && is_admin() ) {
        return true;
    }

    $request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
    $skip_paths = array(
        '/wp-admin/',
        '/wp-login.php',
        '/wp-register.php',
        '/cart/',
        '/checkout/',
        '/my-account/',
        '/feed/',
    );

    foreach ( $skip_paths as $path ) {
        if ( false !== strpos( $request_uri, $path ) ) {
            return true;
        }
    }

    // Support option-based URL exclusions if set
    global $options;
    if ( ! empty( $options['exclude_urls'] ) ) {
        $patterns = preg_split( '/\r?\n/', trim( $options['exclude_urls'] ) );
        foreach ( $patterns as $pattern ) {
            $pattern = trim( $pattern );
            if ( '' !== $pattern ) {
                if ( false !== strpos( $pattern, '*' ) ) {
                    $regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';
                    if ( preg_match( $regex, $request_uri ) ) {
                        return true;
                    }
                } elseif ( stripos( $request_uri, $pattern ) !== false ) {
                    return true;
                }
            }
        }
    }

    return false;
}

function wp_fastlayer_get_accept_cache_variant() {
    $accept = isset( $_SERVER['HTTP_ACCEPT'] ) && is_string( $_SERVER['HTTP_ACCEPT'] ) ? strtolower( $_SERVER['HTTP_ACCEPT'] ) : '';

    if ( false !== strpos( $accept, 'image/avif' ) ) {
        return 'accept-avif';
    }

    if ( false !== strpos( $accept, 'image/webp' ) ) {
        return 'accept-webp';
    }

    return 'accept-legacy';
}

function wp_fastlayer_get_cache_file() {
    global $cache_dir;

    $protocol = function_exists( 'is_ssl' ) && is_ssl() ? 'https' : 'http';
    $host = isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
    $request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
    $url = $protocol . '://' . $host . $request_uri;
    $cache_key = md5( $url . '|' . wp_fastlayer_get_accept_cache_variant() );

    return $cache_dir . $cache_key . '.html';
}

function wp_fastlayer_is_cache_expired( $file ) {
    global $cache_expiration;
    return ( time() - filemtime( $file ) ) > $cache_expiration;
}

function wp_fastlayer_is_safe_path( $path ) {
    global $cache_dir;
    $realpath = realpath( $path );
    if ( false === $realpath ) {
        return false;
    }

    $cache_realpath = realpath( $cache_dir );
    if ( false === $cache_realpath ) {
        return false;
    }

    return 0 === strpos( $realpath, $cache_realpath );
}

if ( wp_fastlayer_cache_enabled() && ! wp_fastlayer_should_skip_cache() ) {
    $cache_file = wp_fastlayer_get_cache_file();

    if ( file_exists( $cache_file ) && wp_fastlayer_is_safe_path( $cache_file ) ) {
        if ( wp_fastlayer_is_cache_expired( $cache_file ) ) {
            @unlink( $cache_file );
        } else {
            if ( ! headers_sent() ) {
                $expiration = $cache_expiration;
                header( 'Vary: Accept', false );
                header( 'X-Cache-Status: HIT' );
                header( 'Cache-Control: public, max-age=' . $expiration . ', s-maxage=' . $expiration );
                header( 'X-Cache-Generated: yes' );
            }
            readfile( $cache_file );
            exit;
        }
    }
}
