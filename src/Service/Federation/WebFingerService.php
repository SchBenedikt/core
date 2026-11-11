<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Domain\Federation\InvalidWebFingerResourceException;
use AlohaSocial\Core\Domain\Federation\LocalActorDirectory;
use AlohaSocial\Core\Domain\Federation\UnknownLocalActorException;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;

/**
 * Resolves `acct:{handle}@{host}` to the local actor document.
 *
 * The href comes from the same `ActivityFactory::actorId()` the actor route
 * uses, so the WebFinger answer and the served document cannot drift apart.
 * A foreign host and an unknown handle are refused identically: telling a
 * prober which half failed would turn discovery into account enumeration.
 */
final class WebFingerService
{
	public const CONTENT_TYPE = 'application/jrd+json; charset=utf-8';

	public const REL_SELF = 'self';

	public const TYPE_ACTIVITY_JSON = 'application/activity+json';

	public function __construct(
		private readonly ActivityFactory $activities,
		private readonly LocalActorDirectory $actors,
	) {
	}

	/**
	 * @return array<string, mixed>
	 *
	 * @throws InvalidWebFingerResourceException when the resource is missing or not an acct: URI
	 * @throws UnknownLocalActorException         when the host or the handle is not local
	 */
	public function resolve(?string $resource): array
	{
		[$handle, $host] = $this->parse($resource);
		$base = $this->activities->baseUrl();

		if (!$this->isLocalHost($host, $base) || !$this->actors->has($handle)) {
			throw new UnknownLocalActorException($handle);
		}

		return [
			'subject' => 'acct:' . $handle . '@' . $this->authority($base),
			'links' => [
				[
					'rel' => self::REL_SELF,
					'type' => self::TYPE_ACTIVITY_JSON,
					'href' => $this->activities->actorId($handle),
				],
			],
		];
	}

	/**
	 * @return array{0: string, 1: string} handle and lower-case host
	 *
	 * @throws InvalidWebFingerResourceException
	 */
	private function parse(?string $resource): array
	{
		$resource = $resource === null ? '' : trim($resource);

		if ($resource === '' || stripos($resource, 'acct:') !== 0) {
			throw new InvalidWebFingerResourceException('not an acct: URI');
		}

		$address = substr($resource, 5);
		$at = strrpos($address, '@');
		if ($at === false) {
			throw new InvalidWebFingerResourceException('missing host');
		}

		$handle = substr($address, 0, $at);
		$host = substr($address, $at + 1);

		if ($handle === '' || $host === '' || str_contains($handle, '@') || str_contains($host, '@')) {
			throw new InvalidWebFingerResourceException('malformed acct: URI');
		}

		return [$handle, strtolower($host)];
	}

	private function isLocalHost(string $host, string $base): bool
	{
		$configured = $this->hostOf($base);
		if ($host === $configured) {
			return true;
		}

		// Handles usually omit the port. Accept the bare host when the
		// instance URL carries one, so a development instance at :8080 can
		// still be resolved as @alice@localhost.
		$port = parse_url($base, PHP_URL_PORT);

		return is_int($port) && $host === $configured . ':' . $port;
	}

	private function authority(string $base): string
	{
		$host = $this->hostOf($base);
		$port = parse_url($base, PHP_URL_PORT);

		return is_int($port) ? $host . ':' . $port : $host;
	}

	private function hostOf(string $base): string
	{
		$host = parse_url($base, PHP_URL_HOST);

		return is_string($host) ? strtolower($host) : '';
	}
}
