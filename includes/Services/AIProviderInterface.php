<?php
/**
 * AI provider interface.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Interface for AI provider implementations.
 */
interface AIProviderInterface {

	/**
	 * Analyze an image and return a description.
	 *
	 * @param string $image_url Public URL of the image.
	 * @return array{description: string, confidence: float}|null
	 */
	public function analyze_image( string $image_url ): ?array;

	/**
	 * Generate tags for a media item.
	 *
	 * @param string $image_url  Public URL of the image.
	 * @param string $description Optional existing description for context.
	 * @return string[] Array of tag strings.
	 */
	public function generate_tags( string $image_url, string $description = '' ): array;

	/**
	 * Moderate content for policy violations.
	 *
	 * @param string $image_url Public URL of the image.
	 * @return array{safe: bool, flags: string[], confidence: float}
	 */
	public function moderate_content( string $image_url ): array;

	/**
	 * Check if the provider is configured and ready.
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Get the provider identifier.
	 *
	 * @return string
	 */
	public function get_id(): string;
}
