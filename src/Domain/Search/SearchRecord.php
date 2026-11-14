<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Search;

use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

use function array_filter;
use function array_keys;
use function array_unique;
use function array_values;
use function count;
use function is_array;
use function is_string;
use function json_decode;
use function mb_strlen;
use function mb_stripos;
use function mb_strtolower;
use function str_starts_with;
use function trim;

/**
 * One searchable account, post or hashtag.
 *
 * The index is a discovery surface, so it accepts only `public` objects.
 * Followers-only, direct and unlisted objects are refused at construction:
 * an indexer bug must not be able to turn them into a search result.
 *
 * `aliases` are normalized handles and canonical actor URLs. Keeping them on
 * the record is what lets repeated WebFinger lookups for different aliases
 * resolve to one canonical actor row.
 */
final readonly class SearchRecord
{
	public const MAX_ID_LENGTH = 640;

	public const MAX_CONTENT_LENGTH = 20_000;

	public const MAX_ALIAS_LENGTH = 512;

	public const MAX_ALIASES = 32;

	public const MAX_HASHTAGS = 50;

	public const MAX_HASHTAG_LENGTH = 100;

	public const MAX_AUTHOR_ID_LENGTH = 512;

	/**
	 * @param list<string> $hashtags
	 * @param list<string> $aliases
	 */
	public function __construct(
		public string $id,
		public SearchKind $kind,
		public SearchSource $source,
		public string $content,
		public DateTimeImmutable $publishedAt,
		public DateTimeImmutable $cachedAt,
		public Visibility $visibility = Visibility::Public,
		public ?string $authorId = null,
		public array $hashtags = [],
		public array $aliases = [],
	) {
		$this->assert();
	}

	/**
	 * @param list<string> $hashtags
	 * @param list<string> $aliases
	 */
	public static function create(
		string $id,
		SearchKind $kind,
		SearchSource $source,
		string $content,
		DateTimeImmutable $publishedAt,
		DateTimeImmutable $cachedAt,
		?string $authorId = null,
		array $hashtags = [],
		array $aliases = [],
	): self {
		return new self(
			id: $id,
			kind: $kind,
			source: $source,
			content: $content,
			publishedAt: Time::toSecondPrecision($publishedAt),
			cachedAt: Time::toSecondPrecision($cachedAt),
			authorId: $authorId,
			hashtags: self::normalizeHashtags($hashtags),
			aliases: self::normalizeAliases($aliases),
		);
	}

	public function matches(string $query): bool
	{
		$query = self::normalizeQuery($query);
		if ($query === '') {
			return false;
		}

		if (mb_stripos($this->content, $query) !== false) {
			return true;
		}

		foreach ($this->aliases as $alias) {
			if (mb_stripos($alias, $query) !== false) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A deterministic baseline rank: exact content first, then a prefix, then
	 * any match. Recency and the id break ties in the repository.
	 */
	public function rank(string $query): int
	{
		$query = self::normalizeQuery($query);
		$content = self::normalizeQuery($this->content);
		if ($query === '') {
			return 0;
		}

		if ($content === $query) {
			return 3;
		}

		if (str_starts_with($content, $query)) {
			return 2;
		}

		return 1;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'type' => $this->kind->value,
			'source' => $this->source->value,
			'author_id' => $this->authorId,
			'content' => $this->content,
			'hashtags' => $this->hashtags,
			'aliases' => $this->aliases,
			'visibility' => $this->visibility->value,
			'published_at' => Time::format($this->publishedAt),
			'cached_at' => Time::format($this->cachedAt),
		];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function fromRow(array $row): self
	{
		return new self(
			id: (string) $row['id'],
			kind: SearchKind::from((string) $row['kind']),
			source: SearchSource::from((string) $row['source']),
			content: (string) $row['content'],
			publishedAt: Time::parse((string) $row['published_at']),
			cachedAt: Time::parse((string) $row['cached_at']),
			visibility: Visibility::fromString((string) $row['visibility']),
			authorId: isset($row['author_id']) ? (string) $row['author_id'] : null,
			hashtags: self::decodeList($row['hashtags'] ?? '[]'),
			aliases: self::decodeList($row['aliases'] ?? '[]'),
		);
	}

	private function assert(): void
	{
		if ($this->id === '' || mb_strlen($this->id) > self::MAX_ID_LENGTH) {
			throw new InvalidSearchRecordException('A search record needs a bounded id.');
		}

		if (trim($this->content) === '' || mb_strlen($this->content) > self::MAX_CONTENT_LENGTH) {
			throw new InvalidSearchRecordException('A search record needs bounded content.');
		}

		if ($this->visibility !== Visibility::Public) {
			throw new InvalidSearchRecordException('Only public objects may enter the discovery index.');
		}

		if ($this->authorId !== null
			&& ($this->authorId === '' || mb_strlen($this->authorId) > self::MAX_AUTHOR_ID_LENGTH)
		) {
			throw new InvalidSearchRecordException('An author id must be null or bounded and non-empty.');
		}

		if (count($this->hashtags) > self::MAX_HASHTAGS) {
			throw new InvalidSearchRecordException('A record carries too many hashtags.');
		}

		if (count($this->aliases) > self::MAX_ALIASES) {
			throw new InvalidSearchRecordException('A record carries too many aliases.');
		}

		foreach ($this->aliases as $alias) {
			if ($alias === '' || mb_strlen($alias) > self::MAX_ALIAS_LENGTH) {
				throw new InvalidSearchRecordException('A search alias is empty or too long.');
			}
		}
	}

	/**
	 * @param list<string> $hashtags
	 *
	 * @return list<string>
	 */
	private static function normalizeHashtags(array $hashtags): array
	{
		$normalized = [];
		foreach ($hashtags as $hashtag) {
			if (!is_string($hashtag)) {
				continue;
			}

			$hashtag = mb_strtolower(trim($hashtag));
			if ($hashtag === '' || mb_strlen($hashtag) > self::MAX_HASHTAG_LENGTH) {
				continue;
			}

			$normalized[$hashtag] = true;
		}

		return array_keys($normalized);
	}

	/**
	 * @param list<string> $aliases
	 *
	 * @return list<string>
	 */
	private static function normalizeAliases(array $aliases): array
	{
		$normalized = [];
		foreach ($aliases as $alias) {
			if (!is_string($alias)) {
				continue;
			}

			$alias = mb_strtolower(trim($alias));
			if ($alias === '') {
				continue;
			}

			$normalized[$alias] = true;
		}

		return array_values(array_unique(array_keys($normalized)));
	}

	private static function normalizeQuery(string $value): string
	{
		return mb_strtolower(trim($value));
	}

	/**
	 * @return list<string>
	 */
	private static function decodeList(mixed $value): array
	{
		if (!is_string($value) || $value === '') {
			return [];
		}

		$decoded = json_decode($value, true);

		return is_array($decoded)
			? array_values(array_filter($decoded, static fn (mixed $item): bool => is_string($item)))
			: [];
	}
}
