<?php
/**
 * Meta Description Optimization — Export Engine
 *
 * Exports only the changes made in the current filter session.
 *
 * @package SEO_AutoFix_Pro
 * @subpackage MetaDescriptionOptimization
 */

namespace SEOAutoFix\MetaDescriptionOptimization;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Description_Export_Engine {

    const EXPORT_DIR = 'seoautofix-metadesc-exports';

    /**
     * Write a CSV from the provided change records and return a download URL.
     *
     * Each record: { post_url, old_description, new_description }
     *
     * @param array[] $changes
     * @return string URL to the CSV file.
     */
    public function export_csv( array $changes ): string {
        $upload     = wp_upload_dir();
        $export_dir = trailingslashit( $upload['basedir'] ) . self::EXPORT_DIR;

        if ( ! file_exists( $export_dir ) ) { wp_mkdir_p( $export_dir ); }

        $filename = 'metadesc-changes-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false ) . '.csv';
        $filepath = $export_dir . '/' . $filename;
        $url      = trailingslashit( $upload['baseurl'] ) . self::EXPORT_DIR . '/' . $filename;

        $fh = fopen( $filepath, 'w' ); // phpcs:ignore
        if ( ! $fh ) { throw new \Exception( 'Cannot create export file.' ); }

        fputcsv( $fh, array( 'Page URL', 'Old Description', 'New Description' ) );

        foreach ( $changes as $row ) {
            fputcsv( $fh, array(
                isset( $row['post_url'] )         ? $row['post_url']         : '',
                isset( $row['old_description'] )  ? $row['old_description']  : '',
                isset( $row['new_description'] )  ? $row['new_description']  : '',
            ) );
        }

        fclose( $fh ); // phpcs:ignore

        \SEOAutoFix_Debug_Logger::log( "[METADESC EXPORT] CSV created: {$url}", 'meta-desc' );
        return $url;
    }
}
