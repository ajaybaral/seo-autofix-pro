<?php
/**
 * Meta Description Optimization — Bulk Engine
 *
 * @package SEO_AutoFix_Pro
 * @subpackage MetaDescriptionOptimization
 */

namespace SEOAutoFix\MetaDescriptionOptimization;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Description_Bulk_Engine {

    /** @var Description_Apply_Engine */
    private $apply_engine;

    public function __construct( Description_Apply_Engine $apply_engine ) {
        $this->apply_engine = $apply_engine;
    }

    /**
     * @param array[] $changes  [{ post_id: int, new_description: string }, …]
     * @return array{ total, applied, failed, errors }
     */
    public function apply_bulk( array $changes ): array {
        \SEOAutoFix_Debug_Logger::log( '[METADESC BULK] apply_bulk() items=' . count( $changes ), 'meta-desc' );

        $summary = array( 'total' => count( $changes ), 'applied' => 0, 'failed' => 0, 'errors' => array() );

        foreach ( $changes as $change ) {
            $post_id         = isset( $change['post_id'] )         ? absint( $change['post_id'] )                          : 0;
            $new_description = isset( $change['new_description'] ) ? sanitize_text_field( $change['new_description'] ) : '';

            if ( ! $post_id || '' === $new_description ) {
                ++$summary['failed'];
                $summary['errors'][ $post_id ] = 'Invalid data.';
                continue;
            }

            try {
                $this->apply_engine->apply( $post_id, $new_description );
                ++$summary['applied'];
                \SEOAutoFix_Debug_Logger::log( "[METADESC BULK] ✅ post_id={$post_id}", 'meta-desc' );
            } catch ( \Exception $e ) {
                ++$summary['failed'];
                $summary['errors'][ $post_id ] = $e->getMessage();
                \SEOAutoFix_Debug_Logger::log( "[METADESC BULK] ❌ post_id={$post_id}: " . $e->getMessage(), 'meta-desc' );
            }
        }

        \SEOAutoFix_Debug_Logger::log( '[METADESC BULK] done applied=' . $summary['applied'] . ' failed=' . $summary['failed'], 'meta-desc' );
        return $summary;
    }
}
