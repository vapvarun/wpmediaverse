<?php
/**
 * OpenAI Vision AI provider.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * OpenAI Vision implementation of the AI provider interface.
 */
class OpenAIProvider implements AIProviderInterface {

	/**
	 * OpenAI API base URL.
	 *
	 * @var string
	 */
	const API_URL = 'https://api.openai.com/v1/chat/completions';

	/**
	 * Get the provider identifier.
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'openai';
	}

	/**
	 * Check if the provider is configured.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return ! empty( $this->get_api_key() );
	}

	/**
	 * Analyze an image and return a description.
	 *
	 * @param string $image_url Public URL of the image.
	 * @return array{description: string, confidence: float}|null
	 */
	public function analyze_image( string $image_url ): ?array {
		$response = $this->call_vision_api(
			'Describe this image in detail for use as an alt text and description on a media sharing platform. Be concise but thorough.',
			$image_url
		);

		if ( ! $response ) {
			return null;
		}

		return array(
			'description' => $response,
			'confidence'  => 0.85,
		);
	}

	/**
	 * Generate tags for a media item.
	 *
	 * @param string $image_url   Public URL of the image.
	 * @param string $description Optional existing description.
	 * @return string[]
	 */
	public function generate_tags( string $image_url, string $description = '' ): array {
		$prompt = 'Generate 5-10 relevant tags for this image. Return only a comma-separated list of single-word or two-word tags, nothing else.';

		if ( $description ) {
			$prompt .= ' Context: ' . $description;
		}

		$response = $this->call_vision_api( $prompt, $image_url );

		if ( ! $response ) {
			return array();
		}

		$tags = array_map( 'trim', explode( ',', $response ) );
		$tags = array_filter( $tags );
		$tags = array_map( 'sanitize_text_field', $tags );

		return array_values( array_unique( $tags ) );
	}

	/**
	 * Moderate content for policy violations.
	 *
	 * @param string $image_url Public URL of the image.
	 * @return array{safe: bool, flags: string[], confidence: float}
	 */
	public function moderate_content( string $image_url ): array {
		$prompt  = 'Analyze this image for content policy violations. Respond with a JSON object: {"safe": true/false, "flags": ["category1", "category2"], "confidence": 0.0-1.0}. ';
		$prompt .= 'Flag categories: nudity, violence, hate, self-harm, drugs, spam. Only include applicable flags.';

		$response = $this->call_vision_api( $prompt, $image_url );

		if ( ! $response ) {
			return array(
				'safe'       => true,
				'flags'      => array(),
				'confidence' => 0,
			);
		}

		$decoded = json_decode( $response, true );

		if ( ! is_array( $decoded ) || ! isset( $decoded['safe'] ) ) {
			return array(
				'safe'       => true,
				'flags'      => array(),
				'confidence' => 0,
			);
		}

		return array(
			'safe'       => (bool) $decoded['safe'],
			'flags'      => isset( $decoded['flags'] ) && is_array( $decoded['flags'] ) ? $decoded['flags'] : array(),
			'confidence' => isset( $decoded['confidence'] ) ? (float) $decoded['confidence'] : 0.5,
		);
	}

	/**
	 * Call the OpenAI Vision API.
	 *
	 * @param string $prompt    Text prompt.
	 * @param string $image_url Image URL.
	 * @return string|null Response text or null on failure.
	 */
	private function call_vision_api( string $prompt, string $image_url ): ?string {
		$api_key = $this->get_api_key();
		if ( ! $api_key ) {
			return null;
		}

		$model = get_option( 'mvs_openai_model', 'gpt-4o-mini' );

		$body = array(
			'model'      => $model,
			'max_tokens' => 500,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'text',
							'text' => $prompt,
						),
						array(
							'type'      => 'image_url',
							'image_url' => array(
								'url' => $image_url,
							),
						),
					),
				),
			),
		);

		$response = wp_remote_post(
			self::API_URL,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
			return null;
		}

		return trim( $data['choices'][0]['message']['content'] );
	}

	/**
	 * Get the OpenAI API key from settings.
	 *
	 * @return string
	 */
	private function get_api_key(): string {
		$key = get_option( 'mvs_openai_api_key', '' );

		/**
		 * Filters the OpenAI API key.
		 *
		 * Allows defining the key via constant or environment variable.
		 *
		 * @param string $key API key from settings.
		 */
		$key = apply_filters( 'mvs_openai_api_key', $key );

		if ( ! $key && defined( 'MVS_OPENAI_API_KEY' ) ) {
			$key = MVS_OPENAI_API_KEY;
		}

		return $key;
	}
}
