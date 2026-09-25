<?php
/**
 * Uninstall: business data is intentionally preserved.
 *
 * Kept: workflow & document settings, order meta (invoice numbers, history),
 * and the Invoice Register table — deleting the plugin must never erase
 * accounting records. Only disposable temp files / font cache are removed.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$rar_wow_upload = wp_upload_dir( null, false );

if ( ! empty( $rar_wow_upload['basedir'] ) ) {
    foreach ( array( 'attachments', 'mpdf-cache' ) as $rar_wow_sub ) {
        $rar_wow_dir = trailingslashit( $rar_wow_upload['basedir'] ) . 'rar-wow/' . $rar_wow_sub;

        if ( ! is_dir( $rar_wow_dir ) ) {
            continue;
        }

        $rar_wow_it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $rar_wow_dir, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $rar_wow_it as $rar_wow_file ) {
            if ( $rar_wow_file->isDir() ) {
                @rmdir( $rar_wow_file->getPathname() ); // phpcs:ignore
            } else {
                @unlink( $rar_wow_file->getPathname() ); // phpcs:ignore
            }
        }

        @rmdir( $rar_wow_dir ); // phpcs:ignore
    }
}

