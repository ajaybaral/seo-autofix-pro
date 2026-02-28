<?php
/**
 * Meta Description Optimization — AI Generator
 *
 * @package SEO_AutoFix_Pro
 * @subpackage MetaDescriptionOptimization
 */

namespace SEOAutoFix\MetaDescriptionOptimization;

if (!defined('ABSPATH')) {
    exit;
}

class Description_AI_Generator
{

    const AI_MODEL = 'gpt-4o-mini';
    const MAX_CONTENT = 300;    // words to send as context
    const MIN_CHARS = 60;
    const MAX_CHARS = 120;

    /**
     * Generate an AI meta description suggestion with primary keyword.
     *
     * @return array{ description: string, keyword: string }
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

            Your task:
            1. Identify the single most important PRIMARY KEYWORD for this webpage based on its content.
            2. Generate ONE high-quality, Google-compliant meta description that incorporates the primary keyword naturally.

            Strict Requirements for the meta description:
            - Length must be between " . self::MIN_CHARS . " and " . self::MAX_CHARS . " characters.
            - Clearly reflect the actual page content.
            - Follow Google Helpful Content guidelines.
            - Reflect E-E-A-T principles (Experience, Expertise, Authoritativeness, Trustworthiness).
            - Use natural, human-written language.
            - Be persuasive and click-focused to encourage users to click from search results.
            - The primary keyword must appear naturally in the description.
            - Do NOT use clickbait, hype, exaggerated claims, or misleading wording.
            - Do NOT use keyword stuffing.
            - Do NOT create repetitive or templated description structures across different pages.
            - Avoid generic phrases unless clearly supported by content.

            If the content is unclear, generate a neutral, accurate descriptive meta description.

            IMPORTANT: Return your response as a JSON object in this exact format:
            {\"description\": \"Your Generated Meta Description Here\", \"keyword\": \"primary keyword\"}

            No extra text outside the JSON. No markdown. No code blocks.";

        $user = "Page URL: {$current_url}\nPage Title: {$current_title}\nContent Preview (first 300 words):\n{$snippet}\n\nGenerate the SEO meta description with primary keyword:";

        \SEOAutoFix_Debug_Logger::log("[METADESC AI] Calling OpenAI for post_id={$post_id}", 'meta-desc');

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
                'max_tokens' => 200,
                'temperature' => 0.6,
            )),
        ));

        if (is_wp_error($response)) {
            \SEOAutoFix_Debug_Logger::log('❌ [METADESC AI] WP_Error: ' . $response->get_error_message(), 'meta-desc');
            throw new \Exception('API request failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        if (200 !== $code) {
            \SEOAutoFix_Debug_Logger::log("❌ [METADESC AI] HTTP {$code}", 'meta-desc');
            throw new \Exception("OpenAI returned HTTP {$code}.");
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['choices'][0]['message']['content'])) {
            throw new \Exception('OpenAI returned empty content.');
        }

        $raw = trim($body['choices'][0]['message']['content']);

        // Parse JSON response from AI
        $parsed = json_decode($raw, true);

        if (is_array($parsed) && !empty($parsed['description'])) {
            $description = trim($parsed['description']);
            $description = trim($description, '"\'„"«»');
            $keyword = isset($parsed['keyword']) ? trim($parsed['keyword']) : '';
        } else {
            // Fallback: treat as plain text description if JSON parsing fails
            $description = trim($raw, '"\'„"«»');
            $description = trim($description);
            $keyword = '';
            \SEOAutoFix_Debug_Logger::log("[METADESC AI] JSON parse failed, using raw text fallback", 'meta-desc');
        }

        \SEOAutoFix_Debug_Logger::log("[METADESC AI] Generated: \"{$description}\" | Keyword: \"{$keyword}\"", 'meta-desc');

        return array('description' => $description, 'keyword' => $keyword);
    }
}
