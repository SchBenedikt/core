<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Search;

use AlohaSocial\Core\Domain\Search\RemoteActorProfile;
use AlohaSocial\Core\Domain\Search\RemoteActorSearch;
use AlohaSocial\Core\Domain\Search\RemoteHandle;
use AlohaSocial\Core\Domain\Search\RemoteSearchException;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Clock\SystemClock;
use AlohaSocial\Core\Service\Federation\RemoteFetcher;
use AlohaSocial\Core\Service\Federation\RemoteFetchException;
use AlohaSocial\Core\Service\Federation\RemoteFetchResult;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use JsonException;

use function array_filter;
use function array_is_list;
use function array_unique;
use function array_values;
use function filter_var;
use function is_array;
use function is_string;
use function json_decode;
use function mb_strlen;
use function parse_url;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function trim;

use const FILTER_VALIDATE_URL;
use const JSON_THROW_ON_ERROR;

/**
 * Exact remote actor lookup through WebFinger and ActivityPub.
 *
 * A handle is the only input. Both requests use {@see RemoteFetcher}, so the
 * host is resolved and pinned, redirects are bounded, private addresses are
 * refused and the body is capped while reading. The actor document is only
 * used for public profile fields; signing keys and inboxes are not returned.
 */
final class WebFingerRemoteActorSearch implements RemoteActorSearch
{
	public const TIMEOUT_SECONDS = 5;

	public const MAX_JRD_BYTES = 65_536;

	public const MAX_ACTOR_BYTES = 262_144;

	public const MAX_REDIRECTS = 2;

	private const JRD_ACCEPT = 'application/jrd+json, application/json';

	private const ACTOR_ACCEPT = 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"';

	public function __construct(
		private readonly RemoteFetcher $fetcher,
		?Clock $clock = null,
	) {
		$this->clock = $clock ?? new SystemClock();
	}

	private readonly Clock $clock;

	public function lookup(RemoteHandle $handle): ?RemoteActorProfile
	{
		$jrd = $this->fetchJson(
			$handle->webfingerUrl(),
			self::JRD_ACCEPT,
			self::MAX_JRD_BYTES,
			'webfinger',
		);

		$actorUrl = $this->selfLink($jrd);
		if ($actorUrl === null) {
			throw new RemoteSearchException('webfinger_invalid', 'The WebFinger answer has no ActivityPub self link.');
		}

		$actor = $this->fetchJson($actorUrl, self::ACTOR_ACCEPT, self::MAX_ACTOR_BYTES, 'actor');
		$actorId = $actor['id'] ?? null;
		if (!is_string($actorId) || mb_strlen($actorId) > RemoteActorProfile::MAX_URL_LENGTH || !$this->isHttpsUrl($actorId)) {
			throw new RemoteSearchException('actor_invalid', 'The remote actor document has no usable id.');
		}

		$requestedUser = $handle->user;
		$preferred = $actor['preferredUsername'] ?? null;
		$user = is_string($preferred) && trim($preferred) !== '' ? strtolower(trim($preferred)) : $requestedUser;

		$actorHost = parse_url($actorId, PHP_URL_HOST);
		$host = is_string($actorHost) && $actorHost !== '' ? strtolower($actorHost) : $handle->host;
		$handleValue = $user . '@' . $host;

		$aliases = [$handle->normalized(), '@' . $handle->normalized(), $actorId, $handleValue, '@' . $handleValue];
		$alsoKnownAs = $actor['alsoKnownAs'] ?? null;
		if (is_array($alsoKnownAs) && array_is_list($alsoKnownAs)) {
			foreach ($alsoKnownAs as $alias) {
				if (is_string($alias) && $this->isHttpsUrl($alias)) {
					$aliases[] = $alias;
				}
			}
		}

		return new RemoteActorProfile(
			actorId: $actorId,
			handle: $handleValue,
			displayName: $this->stringField($actor, 'name') ?? $user,
			summary: $this->stringField($actor, 'summary') ?? '',
			avatarUrl: $this->imageUrl($actor['icon'] ?? null),
			headerUrl: $this->imageUrl($actor['image'] ?? null),
			aliases: array_values(array_unique(array_filter($aliases, static fn (string $alias): bool => $alias !== ''))),
			fetchedAt: Time::toSecondPrecision($this->clock->now()),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fetchJson(string $url, string $accept, int $maxBytes, string $domain): array
	{
		try {
			$result = $this->fetcher->fetch(
				$url,
				$accept,
				self::TIMEOUT_SECONDS,
				$maxBytes,
				self::MAX_REDIRECTS,
			);
		} catch (RemoteFetchException $exception) {
			throw new RemoteSearchException(
				$domain . '_' . $exception->reason,
				'The remote lookup failed.',
			);
		}

		return $this->decode($result, $domain);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function decode(RemoteFetchResult $result, string $domain): array
	{
		try {
			$data = json_decode($result->body, true, 32, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw new RemoteSearchException($domain . '_invalid', 'The remote answer is not valid JSON.');
		}

		if (!is_array($data)) {
			throw new RemoteSearchException($domain . '_invalid', 'The remote answer is not a JSON object.');
		}

		return $data;
	}

	/**
	 * @param array<string, mixed> $jrd
	 */
	private function selfLink(array $jrd): ?string
	{
		$links = $jrd['links'] ?? null;
		if (!is_array($links) || !array_is_list($links)) {
			return null;
		}

		foreach ($links as $link) {
			if (!is_array($link) || ($link['rel'] ?? null) !== 'self') {
				continue;
			}

			$type = strtolower((string) ($link['type'] ?? ''));
			$href = $link['href'] ?? null;
			if (!is_string($href) || (!$this->isActivityType($type)) || !$this->isHttpsUrl($href)) {
				continue;
			}

			return $href;
		}

		return null;
	}

	private function isActivityType(string $type): bool
	{
		return str_contains($type, 'activity+json')
			|| str_contains($type, 'ld+json');
	}

	private function isHttpsUrl(string $url): bool
	{
		$url = trim($url);
		if (mb_strlen($url) > RemoteActorProfile::MAX_URL_LENGTH || filter_var($url, FILTER_VALIDATE_URL) === false) {
			return false;
		}

		return str_starts_with(strtolower($url), 'https://');
	}

	/**
	 * @param array<string, mixed> $actor
	 */
	private function stringField(array $actor, string $name): ?string
	{
		$value = $actor[$name] ?? null;

		return is_string($value) && trim($value) !== '' ? trim($value) : null;
	}

	private function imageUrl(mixed $value): ?string
	{
		if (!is_array($value)) {
			return null;
		}

		$url = $value['url'] ?? null;

		return is_string($url) && $this->isHttpsUrl($url) ? trim($url) : null;
	}
}
