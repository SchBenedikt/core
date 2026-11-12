<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Domain\Federation\BlockedActorPolicy;
use AlohaSocial\Core\Domain\Federation\FollowingRepository;
use AlohaSocial\Core\Domain\Federation\InboxRejectedException;
use AlohaSocial\Core\Domain\Federation\RemotePostRepository;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Platform\Http\HttpClient;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use InvalidArgumentException;
use JsonException;

use function is_array;
use function is_int;
use function is_string;

/**
 * Bounded backfill of one remote actor's public outbox.
 *
 * Backfill is an explicit host operation, not part of the inbox. It is allowed
 * only for an actor that at least one local account follows, follows only the
 * outbox's own origin (so a remote document cannot redirect the fetch to an
 * arbitrary host) and never claims activity ids: a post already delivered is
 * found through the remote-post primary key and counted as a duplicate.
 *
 * It imports Create and Announce only. Deletes, threads, replies and media are
 * outside this slice; the docs say so rather than implying a complete mirror.
 */
final class RemoteOutboxBackfill
{
	private const ACCEPT = 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"';

	public function __construct(
		private readonly HttpClient $http,
		private readonly RemotePostRepository $remotePosts,
		private readonly RemotePostFactory $posts,
		private readonly FollowingRepository $following,
		private readonly ?BlockedActorPolicy $blockedActors = null,
	) {
	}

	/**
	 * @throws RemoteBackfillException when the actor is not followed or a page cannot be used
	 */
	public function backfill(
		string $actorId,
		string $outboxUrl,
		int $maxPages = 10,
		int $maxItems = 200,
	): BackfillReport {
		if ($maxPages < 1 || $maxItems < 1) {
			throw new InvalidArgumentException('Backfill limits must be at least one.');
		}

		if ($this->blockedActors?->isBlocked($actorId) === true) {
			throw new RemoteBackfillException(
				'blocked_actor',
				'The actor is blocked by the host policy.',
			);
		}

		if ($this->following->localUserIdsForActor($actorId) === []) {
			throw new RemoteBackfillException(
				'not_followed',
				'Only an actor followed by a local account can be backfilled.',
			);
		}

		$outboxUrl = $this->absoluteUrl($outboxUrl);
		$page = $this->fetchPage($outboxUrl);
		$pages = 1;

		// An OrderedCollection often has no items itself; its first page does.
		$first = $page['first'] ?? null;
		if (!isset($page['orderedItems']) && $first !== null) {
			if (is_string($first)) {
				$pageUrl = $this->sameOrigin($outboxUrl, $first);
				$page = $this->fetchPage($pageUrl);
				$pages++;
			} elseif (is_array($first)) {
				$page = $first;
			} else {
				throw new RemoteBackfillException(
					'invalid_document',
					'The outbox first page must be a URL or an embedded page.',
				);
			}
		}

		$items = 0;
		$stored = 0;
		$duplicates = 0;
		$skipped = 0;

		while (true) {
			$orderedItems = $page['orderedItems'] ?? [];
			if (!is_array($orderedItems)) {
				throw new RemoteBackfillException(
					'invalid_document',
					'An outbox page needs an orderedItems array.',
				);
			}

			foreach ($orderedItems as $item) {
				if ($items >= $maxItems) {
					break 2;
				}

				$items++;
				if (!is_array($item)) {
					$skipped++;
					continue;
				}

				$activityActor = $this->activityActor($item);
				if ($activityActor !== null && $activityActor !== $actorId) {
					$skipped++;
					continue;
				}

				$outcome = $this->importActivity($actorId, $item);
				if ($outcome === 'stored') {
					$stored++;
				} elseif ($outcome === 'duplicate') {
					$duplicates++;
				} else {
					$skipped++;
				}
			}

			$next = $page['next'] ?? null;
			if (!is_string($next) || $pages >= $maxPages) {
				break;
			}

			$pageUrl = $this->sameOrigin($outboxUrl, $next);
			$page = $this->fetchPage($pageUrl);
			$pages++;
		}

		return new BackfillReport($pages, $items, $stored, $duplicates, $skipped);
	}

	/**
	 * @param array<string, mixed> $activity
	 *
	 * @return 'stored'|'duplicate'|'skipped'
	 */
	private function importActivity(string $actorId, array $activity): string
	{
		$type = $activity['type'] ?? null;
		if (!is_string($type)) {
			return 'skipped';
		}

		try {
			if ($type === 'Create') {
				$post = $this->posts->fromCreate($actorId, $activity);
			} elseif ($type === 'Announce') {
				$objectId = $this->posts->targetId($activity['object'] ?? null, 'Announce');
				$target = $this->remotePosts->findPostByObjectId($objectId);
				$post = $this->posts->fromAnnounce($actorId, $activity, $target);
			} else {
				return 'skipped';
			}
		} catch (InboxRejectedException | InvalidArgumentException) {
			return 'skipped';
		}

		try {
			$this->remotePosts->insert($post);
		} catch (DatabaseException $exception) {
			if ($this->remotePosts->findByActivityId($post->activityId) !== null) {
				return 'duplicate';
			}

			throw $exception;
		}

		return 'stored';
	}

	/**
	 * @param array<string, mixed> $activity
	 */
	private function activityActor(array $activity): ?string
	{
		$actor = $activity['actor'] ?? null;
		if (is_string($actor) && trim($actor) !== '') {
			return trim($actor);
		}

		if (is_array($actor)) {
			$id = $actor['id'] ?? null;
			if (is_string($id) && trim($id) !== '') {
				return trim($id);
			}
		}

		return null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function fetchPage(string $url): array
	{
		try {
			$response = $this->http->request('GET', $url, ['Accept' => self::ACCEPT]);
		} catch (HttpClientException) {
			throw new RemoteBackfillException('fetch_failed', 'The outbox could not be fetched.');
		}

		if (!$response->isSuccessful()) {
			throw new RemoteBackfillException(
				'fetch_failed',
				'The outbox answered with HTTP ' . $response->status . '.',
			);
		}

		try {
			$document = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw new RemoteBackfillException('invalid_document', 'The outbox is not valid JSON.');
		}

		if (!is_array($document)) {
			throw new RemoteBackfillException('invalid_document', 'The outbox must be a JSON object.');
		}

		return $document;
	}

	private function absoluteUrl(string $url): string
	{
		$url = trim($url);
		$parts = parse_url($url);
		if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
			throw new RemoteBackfillException('invalid_outbox', 'The outbox must be an absolute URL.');
		}

		$scheme = strtolower($parts['scheme']);
		if (!in_array($scheme, ['http', 'https'], true)) {
			throw new RemoteBackfillException('invalid_outbox', 'The outbox must use HTTP or HTTPS.');
		}

		return $url;
	}

	private function sameOrigin(string $base, string $url): string
	{
		$url = $this->absoluteUrl($url);
		$baseParts = parse_url($base);
		$urlParts = parse_url($url);
		if ($baseParts === false || $urlParts === false) {
			throw new RemoteBackfillException('invalid_outbox', 'A pagination URL is invalid.');
		}

		$baseScheme = strtolower((string) ($baseParts['scheme'] ?? ''));
		$urlScheme = strtolower((string) ($urlParts['scheme'] ?? ''));
		$baseHost = strtolower((string) ($baseParts['host'] ?? ''));
		$urlHost = strtolower((string) ($urlParts['host'] ?? ''));
		$basePort = $this->port($baseParts);
		$urlPort = $this->port($urlParts);

		if ($baseScheme !== $urlScheme || $baseHost !== $urlHost || $basePort !== $urlPort) {
			throw new RemoteBackfillException(
				'pagination_scope',
				'Outbox pagination must stay on the outbox origin.',
			);
		}

		return $url;
	}

	/**
	 * @param array<string, mixed> $parts
	 */
	private function port(array $parts): int
	{
		$port = $parts['port'] ?? null;
		if (is_int($port)) {
			return $port;
		}

		return strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? 443 : 80;
	}
}
