<?php
/**
 * Regression test for Finding J: per-site configuration on multisite.
 *
 * Before the fix FastLayer wrote one network-wide config.json while
 * fastlayer_options are stored per site, so the last site to save won for the
 * whole network. The fix writes sites/{blog_id}.json per site plus a sites.json
 * index (home URL -> blog id) that the advanced-cache.php drop-in resolves the
 * current request against.
 *
 * What this asserts:
 *   - On a network with two sites, each site's saved options land in its own
 *     file and the two files differ (no leak in either direction).
 *   - The shared config.json is removed on multisite, so the drop-in cannot
 *     fall back to a single site's settings for the whole network.
 *   - fastlayer_resolve_blog_id() maps each site's home URL to the correct id.
 *   - A site whose home URL changes is re-indexed immediately (update_option_home
 *     / update_option_siteurl), and its old home URL stops resolving to it.
 *   - A malformed sites.json is rebuilt from the sites table, so a single site's
 *     write never truncates the network index to a partial set of entries.
 *   - A missing/corrupt per-site file, and an index entry lost for the current
 *     site, are repaired on init without disturbing the other sites.
 *   - On a single-site install config.json is still authoritative and no
 *     multisite artifacts exist; a corrupt config.json is repaired on init.
 *   - Upgrade compatibility: a legacy executable config.php is removed.
 *
 * Run against a site with WP-CLI:
 *
 *     wp eval-file tests/test-multisite-config.php
 *
 * Exit status: 0 = pass, 1 = fail.
 *
 * @package FastLayer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit( 1 );
}

if ( ! class_exists( 'FastLayer\\Bootstrap' ) ) {
    echo "FAIL: FastLayer\\Bootstrap class is not loaded.\n";
    exit( 1 );
}

$fastlayer_failures = array();
$fastlayer_cache_dir = trailingslashit( wp_normalize_path( FASTLAYER_CACHE_DIR ) );

// The drop-in is normally included by wp-settings.php, which defines the
// resolver. When WP_CACHE is off (or the drop-in was removed) load it here, with
// the preload header set so fastlayer_should_skip_cache() short-circuits and the
// serve branch cannot readfile()+exit.
if ( ! function_exists( 'fastlayer_resolve_blog_id' ) && file_exists( FASTLAYER_PATH . 'advanced-cache.php' ) ) {
    $fastlayer_prev_preload = isset( $_SERVER['HTTP_X_FASTLAYER_PRELOAD'] ) ? $_SERVER['HTTP_X_FASTLAYER_PRELOAD'] : null;
    $_SERVER['HTTP_X_FASTLAYER_PRELOAD'] = '1';
    require_once FASTLAYER_PATH . 'advanced-cache.php';
    if ( null === $fastlayer_prev_preload ) {
        unset( $_SERVER['HTTP_X_FASTLAYER_PRELOAD'] );
    } else {
        $_SERVER['HTTP_X_FASTLAYER_PRELOAD'] = $fastlayer_prev_preload;
    }
}

/**
 * Resolves a home URL through the real drop-in resolver using the on-disk index.
 *
 * @param string $home_url Site home URL.
 * @return int
 */
$fastlayer_resolve = function ( $home_url ) use ( $fastlayer_cache_dir ) {
    $index_file = $fastlayer_cache_dir . 'sites.json';
    if ( ! file_exists( $index_file ) ) {
        return 0;
    }

    $index = json_decode( (string) file_get_contents( $index_file ), true );
    if ( ! is_array( $index ) ) {
        $index = array();
    }

    $host = wp_parse_url( $home_url, PHP_URL_HOST );
    $path = wp_parse_url( $home_url, PHP_URL_PATH );
    $path = is_string( $path ) && '' !== $path ? $path : '/';
    $path = '/' . trim( $path, '/' ) . '/';

    $prev_host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : null;
    $prev_uri  = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;

    $_SERVER['HTTP_HOST']   = $host;
    $_SERVER['REQUEST_URI'] = $path;

    $resolved = function_exists( 'fastlayer_resolve_blog_id' ) ? (int) fastlayer_resolve_blog_id( $index ) : 0;

    if ( null === $prev_host ) {
        unset( $_SERVER['HTTP_HOST'] );
    } else {
        $_SERVER['HTTP_HOST'] = $prev_host;
    }
    if ( null === $prev_uri ) {
        unset( $_SERVER['REQUEST_URI'] );
    } else {
        $_SERVER['REQUEST_URI'] = $prev_uri;
    }

    return $resolved;
};

/**
 * Reads a per-site/per-network JSON config file.
 *
 * @param string $path Absolute path.
 * @return array|null
 */
$fastlayer_read_json = function ( $path ) {
    if ( ! file_exists( $path ) ) {
        return null;
    }
    $decoded = json_decode( (string) file_get_contents( $path ), true );
    return is_array( $decoded ) ? $decoded : null;
};

// Resolver unit checks against a synthetic index. These do not need a real
// network with those domains: they exercise the host match, the mapped-domain
// match, and the longest-path match that ms-settings.php performs, including
// the segment boundary (home "/blog" must not match a request for "/blogger/").
if ( function_exists( 'fastlayer_resolve_blog_id' ) ) {
    $fastlayer_synthetic = array(
        'http://example.com'      => 11,
        'http://shop.example.com' => 12,
        'http://example.com/blog' => 13,
        'http://mapped.test'      => 14,
        'http://[::1]'            => 15,
    );

    $fastlayer_probe_path = function ( $host, $uri ) use ( $fastlayer_synthetic ) {
        $prev_host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : null;
        $prev_uri  = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;

        $_SERVER['HTTP_HOST']   = $host;
        $_SERVER['REQUEST_URI'] = $uri;

        $fastlayer_id = (int) fastlayer_resolve_blog_id( $fastlayer_synthetic );

        if ( null === $prev_host ) {
            unset( $_SERVER['HTTP_HOST'] );
        } else {
            $_SERVER['HTTP_HOST'] = $prev_host;
        }
        if ( null === $prev_uri ) {
            unset( $_SERVER['REQUEST_URI'] );
        } else {
            $_SERVER['REQUEST_URI'] = $prev_uri;
        }

        return $fastlayer_id;
    };

    $fastlayer_cases = array(
        array( 'example.com', '/', 11 ),
        array( 'example.com', '/blog/', 13 ),
        array( 'example.com', '/blog/post/', 13 ),
        array( 'example.com', '/blogger/', 11 ),
        array( 'shop.example.com', '/', 12 ),
        array( 'mapped.test', '/anything/', 14 ),
        array( 'example.com:8080', '/blog/', 13 ),
        array( 'unknown.test', '/', 0 ),
        array( '[::1]', '/', 15 ),
        array( '[::1]:8080', '/', 15 ),
        array( '[gggg]', '/', 0 ),
    );

    foreach ( $fastlayer_cases as $fastlayer_case ) {
        $fastlayer_got = $fastlayer_probe_path( $fastlayer_case[0], $fastlayer_case[1] );
        if ( $fastlayer_got !== $fastlayer_case[2] ) {
            $fastlayer_failures[] = sprintf(
                'resolver(%s%s) returned %d, expected %d.',
                $fastlayer_case[0],
                $fastlayer_case[1],
                $fastlayer_got,
                $fastlayer_case[2]
            );
        }
    }
} else {
    $fastlayer_failures[] = 'fastlayer_resolve_blog_id() is not defined after loading the drop-in.';
}

if ( is_multisite() ) {
    $fastlayer_sites = get_sites(
        array(
            'number'  => 2,
            'orderby' => 'blog_id',
            'order'   => 'ASC',
            'fields'  => 'ids',
        )
    );
    $fastlayer_sites = array_map( 'intval', $fastlayer_sites );

    if ( count( $fastlayer_sites ) < 2 ) {
        echo "FAIL: the multisite test requires at least two sites (found " . count( $fastlayer_sites ) . ").\n";
        exit( 1 );
    }

    $fastlayer_main_id = $fastlayer_sites[0];
    $fastlayer_sub_id  = $fastlayer_sites[1];

    $fastlayer_originals = array();
    foreach ( $fastlayer_sites as $fastlayer_site_id ) {
        switch_to_blog( $fastlayer_site_id );
        $fastlayer_originals[ $fastlayer_site_id ] = get_option( 'fastlayer_options', array() );
        restore_current_blog();
    }

    // Save deliberately different settings on each site.
    $fastlayer_main_opts = $fastlayer_originals[ $fastlayer_main_id ];
    $fastlayer_main_opts['exclude_urls'] = 'MAIN_ONLY';
    $fastlayer_main_opts['enable_cache'] = '1';

    $fastlayer_sub_opts = $fastlayer_originals[ $fastlayer_sub_id ];
    $fastlayer_sub_opts['exclude_urls'] = 'SUB_ONLY';
    $fastlayer_sub_opts['enable_cache'] = '1';

    switch_to_blog( $fastlayer_main_id );
    update_option( 'fastlayer_options', $fastlayer_main_opts );
    $fastlayer_main_home = untrailingslashit( home_url( '/' ) );
    restore_current_blog();

    switch_to_blog( $fastlayer_sub_id );
    update_option( 'fastlayer_options', $fastlayer_sub_opts );
    $fastlayer_sub_home = untrailingslashit( home_url( '/' ) );
    restore_current_blog();

    // Each site must have its own file with its own options.
    $fastlayer_main_file = $fastlayer_cache_dir . 'sites/' . $fastlayer_main_id . '.json';
    $fastlayer_sub_file  = $fastlayer_cache_dir . 'sites/' . $fastlayer_sub_id . '.json';

    $fastlayer_main_cfg = $fastlayer_read_json( $fastlayer_main_file );
    $fastlayer_sub_cfg  = $fastlayer_read_json( $fastlayer_sub_file );

    if ( ! is_array( $fastlayer_main_cfg ) ) {
        $fastlayer_failures[] = 'main site has no per-site config file (' . $fastlayer_main_file . ').';
    } elseif ( 'MAIN_ONLY' !== ( $fastlayer_main_cfg['exclude_urls'] ?? null ) ) {
        $fastlayer_failures[] = 'main site config leaked a different site\'s exclude_urls: ' . (string) ( $fastlayer_main_cfg['exclude_urls'] ?? 'null' );
    } elseif ( (int) ( $fastlayer_main_cfg['_blog_id'] ?? 0 ) !== $fastlayer_main_id ) {
        $fastlayer_failures[] = 'main site config _blog_id does not match.';
    }

    if ( ! is_array( $fastlayer_sub_cfg ) ) {
        $fastlayer_failures[] = 'subsite has no per-site config file (' . $fastlayer_sub_file . ').';
    } elseif ( 'SUB_ONLY' !== ( $fastlayer_sub_cfg['exclude_urls'] ?? null ) ) {
        $fastlayer_failures[] = 'subsite config leaked a different site\'s exclude_urls: ' . (string) ( $fastlayer_sub_cfg['exclude_urls'] ?? 'null' );
    } elseif ( (int) ( $fastlayer_sub_cfg['_blog_id'] ?? 0 ) !== $fastlayer_sub_id ) {
        $fastlayer_failures[] = 'subsite config _blog_id does not match.';
    }

    if ( is_array( $fastlayer_main_cfg ) && is_array( $fastlayer_sub_cfg )
        && ( $fastlayer_main_cfg['exclude_urls'] ?? null ) === ( $fastlayer_sub_cfg['exclude_urls'] ?? null ) ) {
        $fastlayer_failures[] = 'both sites share the same config value; per-site config is not in effect.';
    }

    // The drop-in must not be able to fall back to a shared config on a network.
    if ( file_exists( $fastlayer_cache_dir . 'config.json' ) ) {
        $fastlayer_failures[] = 'a shared config.json still exists on multisite and would leak across sites.';
    }

    // The index must map each home URL to its own id.
    $fastlayer_index = $fastlayer_read_json( $fastlayer_cache_dir . 'sites.json' );
    if ( ! is_array( $fastlayer_index ) ) {
        $fastlayer_failures[] = 'sites.json is missing or invalid.';
    } else {
        if ( (int) ( $fastlayer_index[ $fastlayer_main_home ] ?? 0 ) !== $fastlayer_main_id ) {
            $fastlayer_failures[] = 'index does not map the main home URL to the main id.';
        }
        if ( (int) ( $fastlayer_index[ $fastlayer_sub_home ] ?? 0 ) !== $fastlayer_sub_id ) {
            $fastlayer_failures[] = 'index does not map the subsite home URL to the subsite id.';
        }
    }

    // The real resolver must pick the right site for each home URL.
    if ( function_exists( 'fastlayer_resolve_blog_id' ) ) {
        if ( $fastlayer_resolve( $fastlayer_main_home ) !== $fastlayer_main_id ) {
            $fastlayer_failures[] = 'resolver returned the wrong id for the main home URL.';
        }
        if ( $fastlayer_resolve( $fastlayer_sub_home ) !== $fastlayer_sub_id ) {
            $fastlayer_failures[] = 'resolver returned the wrong id for the subsite home URL.';
        }
        // A deeper path on the subsite must still resolve to the subsite.
        if ( $fastlayer_resolve( $fastlayer_sub_home . '/sample-page/' ) !== $fastlayer_sub_id ) {
            $fastlayer_failures[] = 'resolver did not match a subsite child path.';
        }
    } else {
        $fastlayer_failures[] = 'fastlayer_resolve_blog_id() is not defined after loading the drop-in.';
    }

    // Finding J recovery: the drop-in resolves a site by its home URL in
    // sites.json, so a changed home URL must refresh the index. Before the fix
    // nothing did, and the site stayed unresolvable (its old URL answering for
    // it) until its settings were saved again.
    switch_to_blog( $fastlayer_sub_id );
    $fastlayer_sub_home_opt = get_option( 'home' );
    $fastlayer_sub_siteurl  = get_option( 'siteurl' );
    restore_current_blog();

    $fastlayer_moved_home = untrailingslashit( (string) $fastlayer_sub_home_opt ) . 'b';

    switch_to_blog( $fastlayer_sub_id );
    update_option( 'home', $fastlayer_moved_home );
    update_option( 'siteurl', $fastlayer_moved_home );
    restore_current_blog();

    $fastlayer_moved_index = $fastlayer_read_json( $fastlayer_cache_dir . 'sites.json' );
    if ( ! is_array( $fastlayer_moved_index ) || (int) ( $fastlayer_moved_index[ $fastlayer_moved_home ] ?? 0 ) !== $fastlayer_sub_id ) {
        $fastlayer_failures[] = 'a changed home URL was not added to the network index.';
    }
    if ( is_array( $fastlayer_moved_index ) && (int) ( $fastlayer_moved_index[ $fastlayer_sub_home ] ?? 0 ) === $fastlayer_sub_id ) {
        $fastlayer_failures[] = 'the previous home URL still maps to the subsite after it moved.';
    }

    // Put the home URL back so the rest of the run (and the site) is unchanged.
    switch_to_blog( $fastlayer_sub_id );
    update_option( 'home', $fastlayer_sub_home_opt );
    update_option( 'siteurl', $fastlayer_sub_siteurl );
    restore_current_blog();

    // Finding J recovery: a malformed index is rebuilt from the sites table, so
    // one site's write can never truncate the network to a single entry.
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture writing a deliberately broken file.
    @file_put_contents( $fastlayer_cache_dir . 'sites.json', '{ this is not json' );
    \FastLayer\Bootstrap::write_static_config( get_option( 'fastlayer_options', array() ) );
    $fastlayer_rebuilt = $fastlayer_read_json( $fastlayer_cache_dir . 'sites.json' );
    if ( ! is_array( $fastlayer_rebuilt ) ) {
        $fastlayer_failures[] = 'a malformed sites.json was not rebuilt.';
    } else {
        if ( (int) ( $fastlayer_rebuilt[ $fastlayer_main_home ] ?? 0 ) !== $fastlayer_main_id ) {
            $fastlayer_failures[] = 'rebuilding a malformed index dropped the main site entry.';
        }
        if ( (int) ( $fastlayer_rebuilt[ $fastlayer_sub_home ] ?? 0 ) !== $fastlayer_sub_id ) {
            $fastlayer_failures[] = 'rebuilding a malformed index dropped the subsite entry.';
        }
    }

    // Finding J recovery: an index entry lost for the current site (for example
    // to a concurrent read-modify-write by another site) is restored on the next
    // init without disturbing the other site's entry.
    $fastlayer_lost = $fastlayer_read_json( $fastlayer_cache_dir . 'sites.json' );
    if ( is_array( $fastlayer_lost ) ) {
        unset( $fastlayer_lost[ $fastlayer_main_home ] );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture editing the index.
        @file_put_contents( $fastlayer_cache_dir . 'sites.json', wp_json_encode( $fastlayer_lost ) );
    }
    \FastLayer\Bootstrap::get_instance()->init();
    $fastlayer_repaired = $fastlayer_read_json( $fastlayer_cache_dir . 'sites.json' );
    if ( ! is_array( $fastlayer_repaired ) || (int) ( $fastlayer_repaired[ $fastlayer_main_home ] ?? 0 ) !== $fastlayer_main_id ) {
        $fastlayer_failures[] = 'an index entry lost for the current site was not restored on init.';
    }
    if ( is_array( $fastlayer_repaired ) && (int) ( $fastlayer_repaired[ $fastlayer_sub_home ] ?? 0 ) !== $fastlayer_sub_id ) {
        $fastlayer_failures[] = 'restoring a lost index entry disturbed the subsite entry.';
    }

    // Finding J recovery: a corrupt per-site file is rewritten on init without
    // losing the other sites' index entries.
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture writing a deliberately broken file.
    @file_put_contents( $fastlayer_cache_dir . 'sites/' . $fastlayer_main_id . '.json', '{ broken' );
    \FastLayer\Bootstrap::get_instance()->init();
    if ( ! is_array( $fastlayer_read_json( $fastlayer_cache_dir . 'sites/' . $fastlayer_main_id . '.json' ) ) ) {
        $fastlayer_failures[] = 'a corrupt per-site config file was not repaired on init.';
    }
    $fastlayer_after_heal = $fastlayer_read_json( $fastlayer_cache_dir . 'sites.json' );
    if ( ! is_array( $fastlayer_after_heal ) || (int) ( $fastlayer_after_heal[ $fastlayer_sub_home ] ?? 0 ) !== $fastlayer_sub_id ) {
        $fastlayer_failures[] = 'repairing a corrupt per-site file lost the subsite index entry.';
    }

    // Restore the original options on both sites. A site that had never saved
    // (an empty row) is returned to that state by deleting the option.
    foreach ( $fastlayer_originals as $fastlayer_site_id => $fastlayer_original ) {
        switch_to_blog( $fastlayer_site_id );
        if ( empty( $fastlayer_original ) ) {
            delete_option( 'fastlayer_options' );
        } else {
            update_option( 'fastlayer_options', $fastlayer_original );
        }
        restore_current_blog();
    }
} else {
    // Single-site: config.json stays authoritative and the multisite artifacts
    // must not be present.
    $fastlayer_original = get_option( 'fastlayer_options', array() );

    $fastlayer_probe = $fastlayer_original;
    $fastlayer_probe['exclude_urls'] = 'SINGLE_PROBE';
    update_option( 'fastlayer_options', $fastlayer_probe );

    $fastlayer_config = $fastlayer_read_json( $fastlayer_cache_dir . 'config.json' );
    if ( ! is_array( $fastlayer_config ) ) {
        $fastlayer_failures[] = 'single-site config.json is missing or invalid.';
    } else {
        if ( 'SINGLE_PROBE' !== ( $fastlayer_config['exclude_urls'] ?? null ) ) {
            $fastlayer_failures[] = 'single-site config.json did not round-trip the options.';
        }
        if ( (int) ( $fastlayer_config['_blog_id'] ?? 0 ) !== 1 ) {
            $fastlayer_failures[] = 'single-site config.json is missing the expected _blog_id.';
        }
        if ( empty( $fastlayer_config['_home_host'] ) || ! is_array( $fastlayer_config['_home_host'] ) ) {
            $fastlayer_failures[] = 'single-site config.json is missing the trusted-host list.';
        }
    }

    if ( file_exists( $fastlayer_cache_dir . 'sites.json' ) ) {
        $fastlayer_failures[] = 'sites.json exists on a single-site install.';
    }
    if ( is_dir( $fastlayer_cache_dir . 'sites' ) ) {
        $fastlayer_failures[] = 'the sites/ directory exists on a single-site install.';
    }

    // A corrupt config.json must be repaired on the next request, not left to
    // disable caching until the settings are saved again.
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test fixture writing a deliberately broken file.
    @file_put_contents( $fastlayer_cache_dir . 'config.json', '{ broken' );
    \FastLayer\Bootstrap::get_instance()->init();
    $fastlayer_healed = $fastlayer_read_json( $fastlayer_cache_dir . 'config.json' );
    if ( ! is_array( $fastlayer_healed ) || 'SINGLE_PROBE' !== ( $fastlayer_healed['exclude_urls'] ?? null ) ) {
        $fastlayer_failures[] = 'a corrupt config.json was not repaired on init.';
    }

    update_option( 'fastlayer_options', $fastlayer_original );
}

// Upgrade compatibility: a legacy executable config.php must be removed by the
// next write, on single-site and multisite alike.
@file_put_contents( $fastlayer_cache_dir . 'config.php', "<?php\nreturn array( 'legacy' => true );\n" );
\FastLayer\Bootstrap::write_static_config( get_option( 'fastlayer_options', array() ) );
if ( file_exists( $fastlayer_cache_dir . 'config.php' ) ) {
    $fastlayer_failures[] = 'a planted legacy config.php survived write_static_config().';
}

if ( empty( $fastlayer_failures ) ) {
    if ( is_multisite() ) {
        echo "PASS: multisite per-site config is isolated (two sites verified) and the resolver matches each home URL.\n";
    } else {
        echo "PASS: single-site config.json is authoritative and no multisite artifacts exist.\n";
    }
    exit( 0 );
}

foreach ( $fastlayer_failures as $fastlayer_failure ) {
    // esc_html(): CLI output is written verbatim; keep the report escape-safe.
    echo 'FAIL: ' . esc_html( $fastlayer_failure ) . "\n";
}

echo 'FAILED: ' . count( $fastlayer_failures ) . " check(s).\n";
exit( 1 );
