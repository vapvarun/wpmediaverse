<?php
/**
 * Unit tests for AIService.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPMediaVerse\Services\AIService;
use WPMediaVerse\Services\AIProviderInterface;

class AIServiceTest extends TestCase {

	private AIService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new AIService();
	}

	public function test_register_provider(): void {
		$provider = $this->create_mock_provider( 'test', true );
		$this->service->register_provider( $provider );
		$this->assertSame( $provider, $this->service->get_active_provider() );
	}

	public function test_get_active_provider_returns_null_when_none_registered(): void {
		$this->assertNull( $this->service->get_active_provider() );
	}

	public function test_get_active_provider_returns_null_when_unavailable(): void {
		$provider = $this->create_mock_provider( 'test', false );
		$this->service->register_provider( $provider );
		$this->assertNull( $this->service->get_active_provider() );
	}

	public function test_get_active_provider_fallback(): void {
		$provider1 = $this->create_mock_provider( 'first', false );
		$provider2 = $this->create_mock_provider( 'second', true );

		$this->service->register_provider( $provider1 );
		$this->service->register_provider( $provider2 );

		// Default option is 'openai', which doesn't match either. Should fallback.
		$active = $this->service->get_active_provider();
		$this->assertSame( $provider2, $active );
	}

	public function test_multiple_providers_same_id_overwrites(): void {
		$provider1 = $this->create_mock_provider( 'test', true );
		$provider2 = $this->create_mock_provider( 'test', true );

		$this->service->register_provider( $provider1 );
		$this->service->register_provider( $provider2 );

		$this->assertSame( $provider2, $this->service->get_active_provider() );
	}

	public function test_get_usage_stats_returns_default_structure(): void {
		$stats = $this->service->get_usage_stats();

		$this->assertArrayHasKey( 'calls', $stats );
		$this->assertArrayHasKey( 'success', $stats );
		$this->assertArrayHasKey( 'failed', $stats );
		$this->assertArrayHasKey( 'cost', $stats );
		$this->assertArrayHasKey( 'budget', $stats );
		$this->assertSame( 0, $stats['calls'] );
	}

	public function test_analyze_returns_error_without_provider(): void {
		$result = $this->service->analyze( 999 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mvs_no_ai_provider', $result->get_error_code() );
	}

	public function test_auto_tag_returns_error_without_provider(): void {
		$result = $this->service->auto_tag( 999 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mvs_no_ai_provider', $result->get_error_code() );
	}

	public function test_moderate_returns_error_without_provider(): void {
		$result = $this->service->moderate( 999 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mvs_no_ai_provider', $result->get_error_code() );
	}

	/**
	 * Create a mock AIProviderInterface.
	 *
	 * @param string $id        Provider ID.
	 * @param bool   $available Whether available.
	 * @return AIProviderInterface
	 */
	private function create_mock_provider( string $id, bool $available ): AIProviderInterface {
		$mock = $this->createMock( AIProviderInterface::class );
		$mock->method( 'get_id' )->willReturn( $id );
		$mock->method( 'is_available' )->willReturn( $available );
		return $mock;
	}
}
