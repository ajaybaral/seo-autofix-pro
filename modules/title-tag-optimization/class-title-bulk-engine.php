<?php
/**
 * Title Tag Optimization — Bulk Engine
 *
 * @package SEO_AutoFix_Pro
 * @subpackage TitleTagOptimization
 */

namespace SEOAutoFix\TitleTagOptimization;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Title_Bulk_Engine {

    /** @var Title_Apply_Engine */
    private $apply_engine;

    public function __construct( Title_Apply_Engine $apply_engine ) {
        $this->apply_engine = $apply_engine;
    }

    /**
     * @param array[] $changes  [{ post_id: int, new_title: string }, …]
     * @return array{ total, applied, failed, errors }
     */
    public function apply_bulk( array $changes ): array {
        \SEOAutoFix_Debug_Logger::log( '[TITLETAG BULK] apply_bulk() items=' . count( $changes ), 'title-tag' );

        $summary = array( 'total' => count( $changes ), 'applied' => 0, 'failed' => 0, 'errors' => array() );

        foreach ( $changes as $change ) {
            $post_id   = isset( $change['post_id'] )   ? absint( $change['post_id'] )                  : 0;
            $new_title = isset( $change['new_title'] ) ? sanitize_text_field( $change['new_title'] ) : '';

            if ( ! $post_id || '' === $new_title ) {
                ++$summary['failed'];
                $summary['errors'][ $post_id ] = 'Invalid data.';
                continue;
            }

            try {
                $this->apply_engine->apply( $post_id, $new_title );
                ++$summary['applied'];
                \SEOAutoFix_Debug_Logger::log( "[TITLETAG BULK] ✅ post_id={$post_id}", 'title-tag' );
            } catch ( \Exception $e ) {
                ++$summary['failed'];
                $summary['errors'][ $post_id ] = $e->getMessage();
                \SEOAutoFix_Debug_Logger::log( "[TITLETAG BULK] ❌ post_id={$post_id}: " . $e->getMessage(), 'title-tag' );
            }
        }

        \SEOAutoFix_Debug_Logger::log( '[TITLETAG BULK] done applied=' . $summary['applied'] . ' failed=' . $summary['failed'], 'title-tag' );
        return $summary;
    }
}
