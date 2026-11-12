<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service\Federation;

use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Service\Federation\RemoteMediaException;
use AlohaSocial\Core\Service\Federation\RemoteMediaFetcher;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use AlohaSocial\Core\Tests\Support\TestHostResolver;
use PHPUnit\Framework\TestCase;

final class RemoteMediaFetcherTest extends TestCase
{
	private FakeHttpClient $http;

	protected function setUp(): void
	{
		$this->http = new FakeHttpClient();
	}

	public function testTheFetchRejectsNonHttpAndPrivateDestinations(): void
	{
		self::assertSame('remote_media_invalid_url', $this->reasonFor('ftp://media.remote.example/x'));
		self::assertSame('remote_media_ssrf_blocked', $this->reasonFor('http://127.0.0.1/x'));
		self::assertSame('remote_media_ssrf_blocked', $this->reasonFor('http://[::1]/x'));
		self::assertSame('remote_media_ssrf_blocked', $this->reasonFor('http://[::ffff:127.0.0.1]/x'));
		self::assertSame('remote_media_ssrf_blocked', $this->reasonFor('http://media.private.example/x'));
		self::assertSame([], $this->http->requests);
	}

	public function testTheFetchRevalidatesRedirects(): void
	{
		$this->http->queueResponse(new HttpResponse(302, ['location' => 'http://10.0.0.1/x']));

		self::assertSame(
			'remote_media_ssrf_blocked',
			$this->reasonFor('http://media.remote.example/start'),
		);
	}

	public function testTheFetchDoesNotFollowPastTheRedirectBudget(): void
	{
		$this->http->queueResponse(new HttpResponse(302, ['location' => '/next']));

		self::assertSame(
			'remote_media_too_many_redirects',
			$this->reasonFor('http://media.remote.example/start', ['media.remote_max_redirects' => '0']),
		);
	}

	public function testHttpErrorsAndTransportTimeoutsHaveStableReasons(): void
	{
		$this->http->queueResponse(new HttpResponse(404));
		self::assertSame('remote_media_http_error', $this->reasonFor('http://media.remote.example/missing'));

		$this->http->queueTransportFailure(new HttpClientException('Operation timed out'));
		self::assertSame('remote_media_timeout', $this->reasonFor('http://media.remote.example/slow'));
	}

	public function testTheBodyLimitAndDisabledSwitchAreReported(): void
	{
		$this->http->queueResponse(new HttpResponse(200, ['content-type' => 'image/png'], 'too many bytes'));

		self::assertSame(
			'remote_media_too_large',
			$this->reasonFor('http://media.remote.example/large', ['media.remote_max_bytes' => '4']),
		);

		self::assertSame(
			'remote_media_disabled',
			$this->reasonFor('http://media.remote.example/image', ['media.remote_fetch' => 'false']),
		);
	}

	public function testASuccessfulFetchReturnsBoundedBytesAndContentType(): void
	{
		$this->http->queueResponse(new HttpResponse(200, ['content-type' => 'image/png; charset=binary'], '1234'));

		$result = $this->fetcher()->fetch('http://media.remote.example/image');

		self::assertSame('1234', $result->body);
		self::assertSame('image/png', $result->contentType);
	}

	public function testAPublicIpv6DestinationIsNotMistakenForAMappedPrivateOne(): void
	{
		$this->http->queueResponse(new HttpResponse(200, ['content-type' => 'image/png'], '1234'));

		$result = $this->fetcher()->fetch('http://media.v6.example/image');

		self::assertSame('1234', $result->body);
	}

	/**
	 * @param array<string, string> $configuration
	 */
	private function reasonFor(string $url, array $configuration = []): string
	{
		try {
			$this->fetcher($configuration)->fetch($url);
		} catch (RemoteMediaException $exception) {
			return $exception->reason;
		}

		self::fail('The fetch should have failed.');
	}

	/**
	 * @param array<string, string> $configuration
	 */
	private function fetcher(array $configuration = []): RemoteMediaFetcher
	{
		return new RemoteMediaFetcher(
			$this->http,
			new TestHostResolver([
				'media.remote.example' => ['93.184.216.34'],
				'media.private.example' => ['10.0.0.5'],
				'media.v6.example' => ['2606:4700:4700::1111'],
			]),
			new ArrayConfig([
				'social' => [
					'instance.url' => 'https://social.example',
					...$configuration,
				],
			]),
		);
	}
}
