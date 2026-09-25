<?php
/**
 * PDF engine wrapper.
 *
 * Uses a bundled, namespace-isolated copy of mPDF (RarWowVendor\Mpdf) so it can
 * never clash with another plugin's mPDF / PSR libraries. mPDF is chosen over
 * dompdf because it performs proper OpenType shaping for Bangla (conjuncts such
 * as ক্ষ, ন্ড, স্ত্র and vowel signs render correctly).
 *
 * The library is loaded lazily — only when a document is actually generated —
 * so normal page views and the checkout request carry no extra cost.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class RAR_WOW_PDF {

    /** @var bool */
    private static $autoloader = false;

    /**
     * Register the scoped vendor autoloader (idempotent).
     */
    public static function load_library() {
        if ( self::$autoloader ) {
            return;
        }

        self::$autoloader = true;
        $lib              = RAR_WOW_DIR . 'lib/';

        $map = array(
            'RarWowVendor\\Mpdf\\QrCode\\'             => $lib . 'mpdf-qrcode/src/',
            'RarWowVendor\\Mpdf\\PsrLogAwareTrait\\'   => $lib . 'mpdf-psr-log-aware-trait/src/',
            'RarWowVendor\\Mpdf\\PsrHttpMessageShim\\' => $lib . 'mpdf-psr-http-message-shim/src/',
            'RarWowVendor\\Mpdf\\'                     => $lib . 'mpdf/src/',
            'RarWowVendor\\Psr\\Log\\'                 => $lib . 'psr-log/Psr/Log/',
            'RarWowVendor\\Psr\\Http\\Message\\'       => $lib . 'psr-http-message/src/',
            'RarWowVendor\\DeepCopy\\'                 => $lib . 'deep-copy/src/DeepCopy/',
            'RarWowVendor\\setasign\\Fpdi\\'           => $lib . 'fpdi/src/',
        );

        spl_autoload_register(
            static function ( $class ) use ( $map ) {
                if ( 0 !== strpos( $class, 'RarWowVendor\\' ) ) {
                    return;
                }

                foreach ( $map as $prefix => $dir ) {
                    $len = strlen( $prefix );

                    if ( 0 === strncmp( $class, $prefix, $len ) ) {
                        $file = $dir . str_replace( '\\', '/', substr( $class, $len ) ) . '.php';

                        if ( is_readable( $file ) ) {
                            require_once $file;
                        }

                        return;
                    }
                }
            }
        );

        require_once $lib . 'mpdf/src/functions.php';
        require_once $lib . 'deep-copy/src/DeepCopy/deep_copy.php';
    }

    /**
     * Server requirement check.
     *
     * @return array<string,array{ok:bool,label:string,detail:string}>
     */
    public static function requirements() {
        $temp = self::temp_dir( false );

        return array(
            'php'      => array(
                'ok'     => version_compare( PHP_VERSION, '7.4', '>=' ),
                'label'  => 'PHP 7.4+',
                'detail' => PHP_VERSION,
            ),
            'mbstring' => array(
                'ok'     => extension_loaded( 'mbstring' ),
                'label'  => 'mbstring extension',
                'detail' => extension_loaded( 'mbstring' ) ? 'Loaded' : 'Missing',
            ),
            'gd'       => array(
                'ok'     => extension_loaded( 'gd' ),
                'label'  => 'GD extension (images, logo)',
                'detail' => extension_loaded( 'gd' ) ? 'Loaded' : 'Missing',
            ),
            'zlib'     => array(
                'ok'     => extension_loaded( 'zlib' ),
                'label'  => 'zlib extension (compression)',
                'detail' => extension_loaded( 'zlib' ) ? 'Loaded' : 'Missing',
            ),
            'temp'     => array(
                'ok'     => $temp && wp_is_writable( $temp ),
                'label'  => 'Writable temp/font-cache folder',
                'detail' => $temp ? $temp : 'Not available',
            ),
            'memory'   => array(
                'ok'     => wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) ) >= 128 * MB_IN_BYTES || '-1' === (string) ini_get( 'memory_limit' ),
                'label'  => 'PHP memory ≥ 128M',
                'detail' => (string) ini_get( 'memory_limit' ),
            ),
        );
    }

    public static function is_available() {
        return extension_loaded( 'mbstring' ) && extension_loaded( 'gd' );
    }

    /**
     * Protected working directory inside uploads.
     *
     * @param bool   $create Create when missing.
     * @param string $sub    Sub folder.
     * @return string Absolute path without trailing slash, or '' on failure.
     */
    public static function temp_dir( $create = true, $sub = 'mpdf-cache' ) {
        $upload = wp_upload_dir( null, false );

        if ( empty( $upload['basedir'] ) ) {
            return '';
        }

        $base = trailingslashit( $upload['basedir'] ) . 'rar-wow';
        $dir  = $base . '/' . $sub;

        if ( ! $create ) {
            return $dir;
        }

        if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
            return '';
        }

        // Deny direct web access to generated files and the font cache.
        foreach ( array( $base, $dir ) as $folder ) {
            if ( ! file_exists( $folder . '/index.php' ) ) {
                @file_put_contents( $folder . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore
            }

            if ( ! file_exists( $folder . '/.htaccess' ) ) {
                @file_put_contents( $folder . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
            }
        }

        return $dir;
    }

    /**
     * Render HTML to a PDF binary string.
     *
     * @param string $html  Full document body HTML (may contain multiple documents separated by <pagebreak />).
     * @param array  $args  paper (A4|Letter|A5|label size string), orientation, title, author, css.
     * @return string PDF bytes.
     * @throws Exception When the engine fails.
     */
    public static function render( $html, $args = array() ) {
        if ( ! self::is_available() ) {
            throw new Exception( 'PDF engine requirements are missing (mbstring/gd).' );
        }

        $args = wp_parse_args(
            $args,
            array(
                'format'      => 'A4',
                'orientation' => 'P',
                'margins'     => array( 12, 12, 12, 14 ), // left, right, top, bottom (mm).
                'title'       => '',
                'author'      => '',
                'css'         => '',
            )
        );

        self::load_library();

        if ( function_exists( 'wc_set_time_limit' ) ) {
            wc_set_time_limit( 120 );
        }

        $temp = self::temp_dir();

        if ( ! $temp ) {
            throw new Exception( 'Cannot create the PDF temp folder in uploads/rar-wow.' );
        }

        $config_vars = ( new \RarWowVendor\Mpdf\Config\ConfigVariables() )->getDefaults();
        $font_vars   = ( new \RarWowVendor\Mpdf\Config\FontVariables() )->getDefaults();

        $font_dirs = array_merge(
            (array) $config_vars['fontDir'],
            array( RAR_WOW_DIR . 'lib/fonts', RAR_WOW_DIR . 'lib/mpdf/ttfonts' )
        );

        $fontdata = $font_vars['fontdata'];

        $fontdata['hindsiliguri'] = array(
            'R'      => 'HindSiliguri-Regular.ttf',
            'B'      => 'HindSiliguri-SemiBold.ttf',
            'useOTL' => 0xFF,
        );

        $fontdata['hindsiligurimedium'] = array(
            'R'      => 'HindSiliguri-Medium.ttf',
            'B'      => 'HindSiliguri-Bold.ttf',
            'useOTL' => 0xFF,
        );

        $format = $args['format'];

        if ( is_string( $format ) && preg_match( '/^(\d+)x(\d+)$/', $format, $m ) ) {
            $format = array( (int) $m[1], (int) $m[2] ); // Custom size in mm.
        }

        $config = apply_filters(
            'rar_wow_pdf_config',
            array(
                'mode'              => 'utf-8',
                'format'            => $format,
                'orientation'       => $args['orientation'],
                'tempDir'           => $temp,
                'fontDir'           => $font_dirs,
                'fontdata'          => $fontdata,
                'default_font'      => 'hindsiliguri',
                'default_font_size' => 9.5,
                'margin_left'       => $args['margins'][0],
                'margin_right'      => $args['margins'][1],
                'margin_top'        => $args['margins'][2],
                'margin_bottom'     => $args['margins'][3],
                'margin_header'     => 4,
                'margin_footer'     => 4,
                'useSubstitutions'  => true,
                'backupSubsFont'    => array( 'dejavusanscondensed' ),
                'autoScriptToLang'  => false,
                'autoLangToFont'    => false,
                'showImageErrors'   => false,
                'curlAllowUnsafeSslRequests' => false,
                'img_dpi'           => 110,
                'dpi'               => 96,
            ),
            $args
        );

        $mpdf = new \RarWowVendor\Mpdf\Mpdf( $config );

        // Only allow local files and http(s) images (block phar:// and other wrappers).
        $mpdf->SetBasePath( trailingslashit( ABSPATH ) );
        $mpdf->SetTitle( wp_strip_all_tags( (string) $args['title'] ) );
        $mpdf->SetAuthor( wp_strip_all_tags( (string) $args['author'] ) );
        $mpdf->SetCreator( 'RAR Woo Order Workflow & Notify ' . RAR_WOW_VERSION );
        $mpdf->SetDisplayMode( 'fullpage' );

        if ( ! empty( $args['css'] ) ) {
            $mpdf->WriteHTML( (string) $args['css'], \RarWowVendor\Mpdf\HTMLParserMode::HEADER_CSS );
        }

        $mpdf->WriteHTML( (string) $html, \RarWowVendor\Mpdf\HTMLParserMode::HTML_BODY );

        return (string) $mpdf->Output( '', \RarWowVendor\Mpdf\Output\Destination::STRING_RETURN );
    }

    /**
     * Delete cached/temporary data.
     *
     * @param string $sub Which folder: mpdf-cache or attachments.
     * @param int    $older_than Seconds; 0 = everything.
     * @return int Files removed.
     */
    public static function purge( $sub = 'attachments', $older_than = 0 ) {
        $dir = self::temp_dir( false, $sub );

        if ( ! $dir || ! is_dir( $dir ) ) {
            return 0;
        }

        $removed = 0;
        $now     = time();

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $iterator as $file ) {
            $path = $file->getPathname();
            $name = $file->getFilename();

            if ( in_array( $name, array( 'index.php', '.htaccess' ), true ) && dirname( $path ) === $dir ) {
                continue;
            }

            // Never touch anything younger than the threshold — including folders a
            // parallel request has just created for its attachment.
            if ( $older_than > 0 && ( $now - (int) $file->getMTime() ) < $older_than ) {
                continue;
            }

            if ( $file->isDir() ) {
                @rmdir( $path ); // phpcs:ignore -- only succeeds when empty.
                continue;
            }

            if ( @unlink( $path ) ) { // phpcs:ignore
                $removed++;
            }
        }

        return $removed;
    }
}
