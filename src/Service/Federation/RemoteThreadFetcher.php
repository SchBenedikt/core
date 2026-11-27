<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Domain\Federation\RemoteThreadPost;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Config\Config;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;

use function array_is_list;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function max;
use function min;
use function mb_substr;
use function parse_url;
use function preg_match;
use function strtolower;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Fetches and parses the ActivityPub objects used by thread/context display.
 *
 * Network access is delegated to RemoteFetcher, so this class cannot bypass
 * the http(s)-only, public-address, redirect, timeout or body-limit rules.
 * The parser is intentionally permissive about ActivityStreams object types
 * but strict about identity, author, content and bounded metadata.
 */
final class RemoteThreadFetcher
{
	public const CONFIG_APP = 'social';

	public const CONFIG_ENABLED = 'thread.fetch';

	public const CONFIG_MAX_DEPTH = 'thread.max_depth';

	public const CONFIG_MAX_REPLIES = 'thread.max_replies';

	public const CONFIG_MAX_BYTES = 'thread.max_bytes';

	public const CONFIG_TIMEOUT_SECONDS = 'thread.timeout_seconds';

	public const CONFIG_MAX_REDIRECTS = 'thread.max_redirects';

	public const DEFAULT_MAX_DEPTH = 5;

	public const MAX_DEPTH = 10;

	public const DEFAULT_MAX_REPLIES = 20;

	public const MAX_REPLIES = 40;

	public const DEFAULT_MAX_BYTES = 1_048_576;

	public const MAX_BYTES = 5_242_880;

	public const DEFAULT_TIMEOUT_SECONDS = 10;

	public const MAX_TIMEOUT_SECONDS = 30;

	public const DEFAULT_MAX_REDIRECTS = 3;

	public const MAX_REDIRECTS = 5;

	private const ACCEPT = 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams"';

	public function __construct(
		private readonly RemoteFetcher $fetcher,
		private readonly Config $config,
		private readonly Clock $clock,
		private readonly LoggerInterface $logger,
	) {
	}

	public function enabled(): bool
	{
		return $this->config->getBool(self::CONFIG_APP, self::CONFIG_ENABLED, true);
	}

	public function maxDepth(): int
	{
		return max(0, min(self::MAX_DEPTH, $this->config->getInt(
			self::CONFIG_APP,
			self::CONFIG_MAX_DEPTH,
			self::DEFAULT_MAX_DEPTH,
		)));
	}

	public function maxReplies(): int
	{
		return max(0, min(self::MAX_REPLIES, $this->config->getInt(
			self::CONFIG_APP,
			self::CONFIG_MAX_REPLIES,
			self::DEFAULT_MAX_REPLIES,
		)));
	}

	/**
	 * @throws RemoteThreadException with a stable reason
	 */
	public function fetch(string $url): RemoteThreadFetchResult
	{
		if (!$this->enabled()) {
			throw new RemoteThreadException('thread_disabled', 'Thread fetching is disabled.');
		}

		$document = $this->document($url);
		$post = $this->post($document);
		[$replies, $repliesUrl] = $this->replies($document['replies'] ?? null);

		return new RemoteThreadFetchResult($post, $replies, $repliesUrl);
	}

	/**
	 * @return list<RemoteThreadPost>
	 *
	 * @throws RemoteThreadException
	 */
	public function fetchReplies(string $url): array
	{
		if (!$this->enabled()) {
			throw new RemoteThreadException('thread_disabled', 'Thread fetching is disabled.');
		}

		$document = $this->document($url);

		// An OrderedCollection may only advertise its first page. One extra
		// hop is enough for the bounded context view; we never walk next.
		$first = $document['first'] ?? null;
		$items = $document['orderedItems'] ?? $document['items'] ?? null;
		if (!is_array($items) && is_string($first)) {
			$document = $this->document(trim($first));
		}

		return $this->items($document);
	}

	/**
	 * @return array<string, mixed>
	 *
	 * @throws RemoteThreadException
	 */
	private function document(string $url): array
	{
		try {
			$result = $this->fetcher->fetch(
				$url,
				self::ACCEPT,
				$this->timeoutSeconds(),
				$this->maxBytes(),
				$this->maxRedirects(),
			);
		} catch (RemoteFetchException $exception) {
			throw new RemoteThreadException(
				'thread_' . $exception->reason,
				$exception->getMessage(),
			);
		}

		try {
			$document = json_decode($result->body, true, 64, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw new RemoteThreadException('thread_invalid_document', 'The remote thread object is not valid JSON.');
		}

		if (!is_array($document)) {
			throw new RemoteThreadException('thread_invalid_document', 'The remote thread object must be a JSON object.');
		}

		return $document;
	}

	/**
	 * @param array<string, mixed> $document
	 *
	 * @throws RemoteThreadException
	 */
	private function post(array $document): RemoteThreadPost
	{
		$id = $document['id'] ?? null;
		if (!is_string($id) || trim($id) === '') {
			throw new RemoteThreadException('thread_invalid_document', 'A thread object needs an id.');
		}

		$id = trim($id);
		$type = $document['type'] ?? null;
		if (is_string($type) && strtolower(trim($type)) === 'tombstone') {
			throw new RemoteThreadException('thread_invalid_document', 'A deleted object has no thread content.');
		}

		$content = $document['content'] ?? null;
		if (!is_string($content) || trim($content) === '') {
			throw new RemoteThreadException('thread_invalid_document', 'A thread object needs string content.');
		}

		$author = $this->firstId($document['attributedTo'] ?? null);
		if ($author === null) {
			throw new RemoteThreadException(
				'thread_invalid_document',
				'A thread object needs an attributedTo author.',
			);
		}

		$inReplyTo = $this->reference($document['inReplyTo'] ?? null);
		$publishedAt = $this->publishedAt($document['published'] ?? null);
		$contentWarning = $this->contentWarning($document['summary'] ?? null);

		try {
			return new RemoteThreadPost(
				objectId: $id,
				authorId: $author,
				content: mb_substr(trim($content), 0, RemoteThreadPost::MAX_CONTENT_LENGTH),
				publishedAt: $publishedAt,
				fetchedAt: Time::toSecondPrecision($this->clock->now()),
				contentWarning: $contentWarning,
				inReplyToId: $inReplyTo,
				audience: $this->audience($document, $author),
			);
		} catch (InvalidArgumentException $exception) {
			throw new RemoteThreadException('thread_invalid_document', $exception->getMessage());
		}
	}

	/**
	 * @return array{0: list<RemoteThreadPost>, 1: ?string}
	 *
	 * @throws RemoteThreadException
	 */
	private function replies(mixed $value): array
	{
		if ($value === null) {
			return [[], null];
		}

		if (is_string($value)) {
			$url = trim($value);

			return $this->isAbsoluteUrl($url) ? [[], $url] : [[], null];
		}

		if (!is_array($value)) {
			return [[], null];
		}

		$items = $value['orderedItems'] ?? $value['items'] ?? null;
		if (is_array($items) && $items !== []) {
			return [$this->nodes($items), null];
		}

		$first = $value['first'] ?? null;
		if (is_string($first) && $this->isAbsoluteUrl(trim($first))) {
			return [[], trim($first)];
		}

		if (is_array($first)) {
			$firstItems = $first['orderedItems'] ?? $first['items'] ?? null;

			return [is_array($firstItems) ? $this->nodes($firstItems) : [], null];
		}

		if (array_is_list($value)) {
			return [$this->nodes($value), null];
		}

		return [[], null];
	}

	/**
	 * @param array<string, mixed> $document
	 *
	 * @return list<RemoteThreadPost>
	 *
	 * @throws RemoteThreadException
	 */
	private function items(array $document): array
	{
		$items = $document['orderedItems'] ?? $document['items'] ?? null;
		if (!is_array($items)) {
			throw new RemoteThreadException(
				'thread_invalid_document',
				'A replies collection needs orderedItems or items.',
			);
		}

		return $this->nodes($items);
	}

	/**
	 * @param array<mixed> $items
	 *
	 * @return list<RemoteThreadPost>
	 */
	private function nodes(array $items): array
	{
		if ($this->maxReplies() === 0) {
			return [];
		}

		$nodes = [];
		foreach ($items as $item) {
			if (!is_array($item)) {
				continue;
			}

			if (($item['type'] ?? null) === 'Create' || ($item['type'] ?? null) === 'Announce') {
				$embedded = $item['object'] ?? null;
				if (!is_array($embedded)) {
					continue;
				}

				$item = $embedded;
			}

			try {
				$nodes[] = $this->post($item);
			} catch (RemoteThreadException $exception) {
				$this->logger->warning('Skipping an invalid remote thread reply.', [
					'reason' => $exception->reason,
					'error' => $exception->getMessage(),
				]);
			}

			if (count($nodes) >= $this->maxReplies()) {
				break;
			}
		}

		return $nodes;
	}

	/**
	 * The audience a thread document declares, without trusting it: public
	 * wins, then the author's followers collection, otherwise unknown. The
	 * service treats unknown as hidden from viewers without a follow edge.
	 *
	 * @param array<string, mixed> $document
	 */
	private function audience(array $document, string $authorId): ?Visibility
	{
		$public = 'https://www.w3.org/ns/activitystreams#Public';
		$followers = rtrim($authorId, '/') . '/followers';
		$recipients = [];

		foreach (['to', 'cc', 'audience'] as $field) {
			$value = $document[$field] ?? null;
			foreach (is_array($value) ? $value : [$value] as $entry) {
				if (is_string($entry)) {
					$recipients[] = $entry;
				}
			}
		}

		if (in_array($public, $recipients, true)) {
			return Visibility::Public;
		}
		if (in_array($followers, $recipients, true)) {
			return Visibility::Followers;
		}

		return null;
	}

	private function firstId(mixed $value): ?string
	{
		if (is_string($value) && trim($value) !== '') {
			return trim($value);
		}

		if (!is_array($value)) {
			return null;
		}

		$id = $value['id'] ?? null;
		if (is_string($id) && trim($id) !== '') {
			return trim($id);
		}

		if (!array_is_list($value)) {
			return null;
		}

		foreach ($value as $entry) {
			$id = $this->firstId($entry);
			if ($id !== null) {
				return $id;
			}
		}

		return null;
	}

	private function reference(mixed $value): ?string
	{
		if ($value === null) {
			return null;
		}

		$id = $this->firstId($value);
		if ($id === null) {
			throw new RemoteThreadException(
				'thread_invalid_document',
				'A thread inReplyTo value has no usable id.',
			);
		}

		return $id;
	}

	private function publishedAt(mixed $value): DateTimeImmutable
	{
		if ($value === null) {
			return Time::toSecondPrecision($this->clock->now());
		}

		if (!is_string($value) || trim($value) === '') {
			throw new RemoteThreadException('thread_invalid_document', 'A thread published timestamp is invalid.');
		}

		$value = trim($value);
		if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
			throw new RemoteThreadException('thread_invalid_document', 'A thread published timestamp is not RFC 3339.');
		}

		try {
			return Time::toSecondPrecision(new DateTimeImmutable($value));
		} catch (Exception) {
			throw new RemoteThreadException('thread_invalid_document', 'A thread published timestamp is invalid.');
		}
	}

	private function contentWarning(mixed $value): ?string
	{
		if ($value === null) {
			return null;
		}

		if (!is_string($value)) {
			throw new RemoteThreadException('thread_invalid_document', 'A thread summary must be a string.');
		}

		$value = trim($value);
		if ($value === '') {
			return null;
		}

		return mb_substr($value, 0, RemoteThreadPost::MAX_WARNING_LENGTH);
	}

	private function isAbsoluteUrl(string $url): bool
	{
		$parts = parse_url($url);

		return is_array($parts)
			&& isset($parts['scheme'], $parts['host'])
			&& in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true);
	}

	private function maxBytes(): int
	{
		return max(1, min(self::MAX_BYTES, $this->config->getInt(
			self::CONFIG_APP,
			self::CONFIG_MAX_BYTES,
			self::DEFAULT_MAX_BYTES,
		)));
	}

	private function timeoutSeconds(): int
	{
		return max(1, min(self::MAX_TIMEOUT_SECONDS, $this->config->getInt(
			self::CONFIG_APP,
			self::CONFIG_TIMEOUT_SECONDS,
			self::DEFAULT_TIMEOUT_SECONDS,
		)));
	}

	private function maxRedirects(): int
	{
		return max(0, min(self::MAX_REDIRECTS, $this->config->getInt(
			self::CONFIG_APP,
			self::CONFIG_MAX_REDIRECTS,
			self::DEFAULT_MAX_REDIRECTS,
		)));
	}
}
