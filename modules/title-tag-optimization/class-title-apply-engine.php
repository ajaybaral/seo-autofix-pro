<?php
/**
 * Title Tag Optimization — Apply Engine
 *
 * @package SEO_AutoFix_Pro
 * @subpackage TitleTagOptimization
 */

namespace SEOAutoFix\TitleTagOptimization;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Title_Apply_Engine {

    /**
     * Detect the active SEO plugin (isolated — no cross-module import).
     */
    private function detect_seo_plugin(): string {
        if ( defined( 'WPSEO_VERSION' ) )      { return 'yoast'; }
        if ( defined( 'RANK_MATH_VERSION' ) )  { return 'rankmath'; }
        if ( defined( 'AIOSEO_VERSION' ) )     { return 'aioseo'; }
        return 'native';
    }

    /**
     * Apply a new title to the correct SEO meta field.
     *
     * Rules:
     *  - Yoast     → _yoast_wpseo_title
     *  - Rank Math → rank_math_title
     *  - AIOSEO    → _aioseo_title
     *  - Native    → wp_update_post( post_title )
     *  - Do NOT touch post_title when an SEO plugin is active.
     *  - Do NOT modify the slug.
     */
    public function apply( int $post_id, string $new_title ): array {
        $post = get_post( $post_id );
        if ( ! $post ) { throw new \Exception( "Post {$post_id} not found." ); }

        $plugin   = $this->detect_seo_plugin();
        $meta_key = '';
        $old_title = '';

        switch ( $plugin ) {
            case 'yoast':
                $meta_key  = '_yoast_wpseo_title';
                $old_title = (string) get_post_meta( $post_id, $meta_key, true );
                update_post_meta( $post_id, $meta_key, $new_title );
                break;

            case 'rankmath':
                $meta_key  = 'rank_math_title';
                $old_title = (string) get_post_meta( $post_id, $meta_key, true );
                update_post_meta( $post_id, $meta_key, $new_title );
                break;

            case 'aioseo':
                $meta_key  = '_aioseo_title';
                $old_title = (string) get_post_meta( $post_id, $meta_key, true );
                update_post_meta( $post_id, $meta_key, $new_title );
                break;

            default: // native — update post_title only
                $old_title = $post->post_title;
                wp_update_post( array( 'ID' => $post_id, 'post_title' => $new_title ) );
                break;
        }

        \SEOAutoFix_Debug_Logger::log(
            "[TITLETAG APPLY] post_id={$post_id} plugin={$plugin} old=\"{$old_title}\" new=\"{$new_title}\"",
            'title-tag'
        );

        // Append to audit log option (last 500 entries).
        $log   = get_option( 'seoautofix_titletag_audit_log', array() );
        $log[] = array(
            'post_id'   => $post_id,
            'post_url'  => get_permalink( $post_id ),
            'old_title' => $old_title,
            'new_title' => $new_title,
            'plugin'    => $plugin,
            'time'      => current_time( 'mysql' ),
        );
        if ( count( $log ) > 500 ) { $log = array_slice( $log, -500 ); }
        update_option( 'seoautofix_titletag_audit_log', $log );

        return array(
            'post_id'   => $post_id,
            'old_title' => $old_title,
            'new_title' => $new_title,
            'plugin'    => $plugin,
        );
    }
}
