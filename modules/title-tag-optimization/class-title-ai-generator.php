<?php
/**
 * Title Tag Optimization — AI Generator
 *
 * @package SEO_AutoFix_Pro
 * @subpackage TitleTagOptimization
 */

namespace SEOAutoFix\TitleTagOptimization;

if (!defined('ABSPATH')) {
    exit;
}

class Title_AI_Generator
{

    const AI_MODEL = 'gpt-4o-mini';
    const MAX_CONTENT = 300;    // words to send as context
    const MIN_CHARS = 30;
    const MAX_CHARS = 60;

    /**
     * Generate an AI title suggestion.
     *
     * @return array{ title: string }
     */
    public function generate(int $post_id): array
    {

        $post = get_post($post_id);
        if (!$post) {
            throw new \Exception("Post {$post_id} not found.");
        }

        $api_key = \SEOAutoFix_Settings::get_api_key();
        if (empty($api_key)) {
            throw new \Exception('OpenAI API key is not configured.');
        }

        // Build context — first 300 words of content
        $content_raw = wp_strip_all_tags($post->post_content);
        $words = explode(' ', $content_raw);
        $snippet = implode(' ', array_slice($words, 0, self::MAX_CONTENT));

        $current_url = get_permalink($post_id);
        $current_title = $post->post_title;


        $system = "You are a senior SEO strategist and professional copywriter.

            Generate ONE high-quality, Google-compliant title tag for a webpage.

            Strict Requirements:
            - Length must be between " . self::MIN_CHARS . " and " . self::MAX_CHARS . " characters.
            - Clearly reflect the actual page content.
            - Follow Google Helpful Content guidelines.
            - Reflect E-E-A-T principles (Experience, Expertise, Authoritativeness, Trustworthiness).
            - Use natural, human-written language.
            - Be specific and descriptive, not vague.
            - Do NOT use clickbait, hype, exaggerated claims, or misleading wording.
            - Do NOT use keyword stuffing.
            - Do NOT create repetitive or templated title structures across different pages.
            - Do NOT add unnecessary site name branding.
            - Avoid generic phrases like 'Best', 'Top', or 'Ultimate' unless clearly supported by content.

            If the content is unclear, generate a neutral, accurate descriptive title.

            Return ONLY the final title text.
            No quotes.
            No explanations.
            No extra text.";

        $user = "Page URL: {$current_url}\nCurrent Title: {$current_title}\nContent Preview (first 300 words):\n{$snippet}\n\nGenerate the SEO title:";

        \SEOAutoFix_Debug_Logger::log("[TITLETG AI] Calling OpenAI for post_id={$post_id}", 'title-tag');

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => wp_json_encode(array(
                'model' => self::AI_MODEL,
                'messages' => array(
                    array('role' => 'system', 'content' => $system),
                    array('role' => 'user', 'content' => $user),
                ),
                'max_tokens' => 40,
                'temperature' => 0.6,
            )),
        ));

        if (is_wp_error($response)) {
            \SEOAutoFix_Debug_Logger::log('❌ [TITLETAG AI] WP_Error: ' . $response->get_error_message(), 'title-tag');
            throw new \Exception('API request failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if (200 !== $code) {
            \SEOAutoFix_Debug_Logger::log("❌ [TITLETAG AI] HTTP {$code}", 'title-tag');
            throw new \Exception("OpenAI returned HTTP {$code}.");
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['choices'][0]['message']['content'])) {
            throw new \Exception('OpenAI returned empty content.');
        }

        $title = trim($body['choices'][0]['message']['content']);
        $title = trim($title, '"\'„"«»');
        $title = trim($title);

        \SEOAutoFix_Debug_Logger::log("[TITLETAG AI] Generated: \"{$title}\"", 'title-tag');

        return array('title' => $title);
    }
}
