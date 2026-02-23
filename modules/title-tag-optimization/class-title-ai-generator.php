<?php
/**
 * Title Tag Optimization — AI Generator
 *
 * @package SEO_AutoFix_Pro
 * @subpackage TitleTagOptimization
 */

namespace SEOAutoFix\TitleTagOptimization;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Title_AI_Generator {

    const AI_MODEL        = 'gpt-4o-mini';
    const CACHE_PREFIX    = 'seoautofix_titletag_ai_';
    const CACHE_TTL       = 604800; // 7 days
    const MAX_CONTENT     = 300;    // words to send as context
    const MIN_CHARS       = 30;
    const MAX_CHARS       = 60;

    /**
     * Generate (or cache-retrieve) an AI title suggestion.
     *
     * @return array{ title: string, cached: bool }
     */
    public function generate( int $post_id, bool $force = false ): array {
        $cache_key = self::CACHE_PREFIX . $post_id;

        if ( ! $force ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached && ! empty( $cached ) ) {
                \SEOAutoFix_Debug_Logger::log( "[TITLETAG AI] Cache hit: post_id={$post_id}", 'title-tag' );
                return array( 'title' => $cached, 'cached' => true );
            }
        }

        $post = get_post( $post_id );
        if ( ! $post ) { throw new \Exception( "Post {$post_id} not found." ); }

        $api_key = \SEOAutoFix_Settings::get_api_key();
        if ( empty( $api_key ) ) { throw new \Exception( 'OpenAI API key is not configured.' ); }

        // Build context — first 300 words of content
        $content_raw = wp_strip_all_tags( $post->post_content );
        $words       = explode( ' ', $content_raw );
        $snippet     = implode( ' ', array_slice( $words, 0, self::MAX_CONTENT ) );

        $current_url   = get_permalink( $post_id );
        $current_title = $post->post_title;

        $system = "You are an expert SEO copywriter. Generate ONE SEO-optimized title tag. Rules:\n- Between " . self::MIN_CHARS . " and " . self::MAX_CHARS . " characters.\n- Descriptive and compelling for Google SERP.\n- No keyword stuffing.\n- No unnecessary site name branding.\n- Return ONLY the title text, no quotes, no explanation.";

        $user = "Page URL: {$current_url}\nCurrent Title: {$current_title}\nContent Preview (first 300 words):\n{$snippet}\n\nGenerate the SEO title:";

        \SEOAutoFix_Debug_Logger::log( "[TITLETAG AI] Calling OpenAI for post_id={$post_id}", 'title-tag' );

        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'model'       => self::AI_MODEL,
                'messages'    => array(
                    array( 'role' => 'system', 'content' => $system ),
                    array( 'role' => 'user',   'content' => $user ),
                ),
                'max_tokens'  => 80,
                'temperature' => 0.7,
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            \SEOAutoFix_Debug_Logger::log( '❌ [TITLETAG AI] WP_Error: ' . $response->get_error_message(), 'title-tag' );
            throw new \Exception( 'API request failed: ' . $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            \SEOAutoFix_Debug_Logger::log( "❌ [TITLETAG AI] HTTP {$code}", 'title-tag' );
            throw new \Exception( "OpenAI returned HTTP {$code}." );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['choices'][0]['message']['content'] ) ) {
            throw new \Exception( 'OpenAI returned empty content.' );
        }

        $title = trim( $body['choices'][0]['message']['content'] );
        $title = trim( $title, '"\'„"«»' );
        $title = trim( $title );

        \SEOAutoFix_Debug_Logger::log( "[TITLETAG AI] Generated: \"{$title}\"", 'title-tag' );

        set_transient( $cache_key, $title, self::CACHE_TTL );

        return array( 'title' => $title, 'cached' => false );
    }
}
