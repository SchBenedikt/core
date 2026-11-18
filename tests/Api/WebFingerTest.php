<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Contract\Routes;
use AlohaSocial\Core\Domain\Federation\InMemoryActorDirectory;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Protocol\ActivityPub\RsaKeyPair;
use AlohaSocial\Core\Service\Federation\WebFingerService;
use PHPUnit\Framework\TestCase;

/**
 * WebFinger turns `acct:{handle}@{host}` into the actor document URL. The
 * href must be the URL the actor route really serves, not a second guess.
 */
final class WebFingerTest extends TestCase
{
	private const RESOURCE = 'acct:alice@localhost:8080';

	private static RsaKeyPair $localKey;

	public static function setUpBeforeClass(): void
	{
		self::$localKey = RsaKeyPair::generate();
	}

	public function testAHandleResolvesToTheServedActorDocument(): void
	{
		$app = $this->app();

		$response = $this->webFinger($app, self::RESOURCE);

		self::assertSame(200, $response->status);
		self::assertSame(WebFingerService::CONTENT_TYPE, $response->header('Content-Type'));

		$jrd = $response->decodedBody();

		self::assertSame(self::RESOURCE, $jrd['subject']);
		self::assertCount(1, $jrd['links']);

		$link = $jrd['links'][0];
		self::assertSame('self', $link['rel']);
		self::assertSame('application/activity+json', $link['type']);

		// Compare with the document the actor route really returns, and with
		// the route template, instead of a separately written URL.
		$document = $app->handle(new Request('GET', '/@alice'))->decodedBody();
		self::assertSame($document['id'], $link['href']);

		$route = Routes::manifest()->find('actors.get');
		self::assertNotNull($route);
		self::assertSame(
			parse_url($document['id'], PHP_URL_PATH),
			str_replace('{handle}', 'alice', $route->path),
		);
	}

	public function testTheHostMatchIsCaseInsensitive(): void
	{
		$response = $this->webFinger($this->app(), 'ACCT:alice@LOCALHOST:8080');

		self::assertSame(200, $response->status);
	}

	public function testAnIdnHostIsMatchedInItsAsciiForm(): void
	{
		$app = Application::inMemory(
			config: new ArrayConfig(['social' => ['instance.url' => 'https://social.schöchner.test']]),
			signingKey: self::$localKey,
			localActors: new InMemoryActorDirectory(['alice']),
		);

		$response = $this->webFinger($app, 'acct:alice@social.xn--schchner-p4a.test');

		self::assertSame(200, $response->status, $response->body);
		self::assertSame(
			'acct:alice@social.xn--schchner-p4a.test',
			$response->decodedBody()['subject'],
		);
	}

	public function testAForeignHostIsNotFoundWithoutEchoingIt(): void
	{
		$response = $this->webFinger($this->app(), 'acct:alice@evil.example');

		self::assertSame(404, $response->status);
		self::assertSame('actor_not_found', $response->decodedBody()['error']['code']);

		$body = (string) json_encode($response->decodedBody());
		self::assertStringNotContainsString('alice', $body);
		self::assertStringNotContainsString('evil.example', $body);
	}

	public function testAnUnknownHandleIsNotFoundWithoutEchoingIt(): void
	{
		$response = $this->webFinger($this->app(), 'acct:mallory@localhost:8080');

		self::assertSame(404, $response->status);
		self::assertSame('actor_not_found', $response->decodedBody()['error']['code']);

		$body = (string) json_encode($response->decodedBody());
		self::assertStringNotContainsString('mallory', $body);
		self::assertStringNotContainsString('localhost', $body);
	}

	public function testTheEndpointIsDisabledWithoutAKey(): void
	{
		$app = Application::inMemory(
			localActors: new InMemoryActorDirectory(['alice']),
		);

		$response = $this->webFinger($app, self::RESOURCE);

		self::assertSame(503, $response->status);
		self::assertSame('actor_not_configured', $response->decodedBody()['error']['code']);
	}

	public function testTheEndpointIsDisabledWithoutADirectory(): void
	{
		$app = Application::inMemory(
			signingKey: self::$localKey,
		);

		$response = $this->webFinger($app, self::RESOURCE);

		self::assertSame(503, $response->status);
		self::assertSame('actor_not_configured', $response->decodedBody()['error']['code']);
	}

	public function testAMissingResourceIsABadRequest(): void
	{
		$response = $this->app()->handle(new Request('GET', '/.well-known/webfinger'));

		self::assertSame(400, $response->status);
		self::assertSame('invalid_resource', $response->decodedBody()['error']['code']);
	}

	public function testAMalformedResourceIsABadRequest(): void
	{
		$app = $this->app();

		foreach (['alice', 'acct:alice', 'acct:@localhost:8080', 'acct:alice@', 'https://localhost:8080/@alice'] as $resource) {
			$response = $this->webFinger($app, $resource);

			self::assertSame(400, $response->status, 'Resource: ' . $resource);
			self::assertSame('invalid_resource', $response->decodedBody()['error']['code']);
		}
	}

	private function app(): Application
	{
		return Application::inMemory(
			signingKey: self::$localKey,
			localActors: new InMemoryActorDirectory(['alice']),
		);
	}

	private function webFinger(Application $app, string $resource): Response
	{
		return $app->handle(new Request(
			method: 'GET',
			path: '/.well-known/webfinger',
			query: ['resource' => $resource],
		));
	}
}
