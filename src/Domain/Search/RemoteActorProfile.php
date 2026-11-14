<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Search;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

use function array_slice;
use function array_unique;
use function array_values;
use function mb_strlen;
use function mb_substr;
use function trim;

/**
 * The public projection of a remote actor returned by an exact handle lookup.
 *
 * It intentionally carries no keys, inbox or host-private metadata. Search
 * needs enough to identify and present an account, nothing that would make
 * the lookup a remote-account importer.
 */
final readonly class RemoteActorProfile
{
	public const MAX_URL_LENGTH = 512;

	/**
	 * @param list<string> $aliases normalized handles or canonical actor URLs
	 */
	public function __construct(
		public string $actorId,
		public string $handle,
		public string $displayName,
		public string $summary,
		public ?string $avatarUrl,
		public ?string $headerUrl,
		public array $aliases,
		public DateTimeImmutable $fetchedAt,
	) {
		if ($actorId === '' || mb_strlen($actorId) > self::MAX_URL_LENGTH) {
			throw new \InvalidArgumentException('A remote actor id is empty or too long.');
		}

		if ($handle === '') {
			throw new \InvalidArgumentException('A remote actor needs a handle.');
		}
	}

	public function searchRecord(SearchSource $source, DateTimeImmutable $cachedAt): SearchRecord
	{
		// The actor document is remote and may carry a long bio or alias list;
		// the index has a bounded shape, so clamp before constructing it.
		$content = mb_substr(
			trim('@' . $this->handle . ' ' . $this->displayName . ' ' . $this->summary),
			0,
			SearchRecord::MAX_CONTENT_LENGTH,
		);
		$aliases = array_slice(array_values(array_unique([
			$this->handle,
			'@' . $this->handle,
			$this->actorId,
			...$this->aliases,
		])), 0, SearchRecord::MAX_ALIASES);

		return SearchRecord::create(
			id: 'actor:' . $this->actorId,
			kind: SearchKind::Account,
			source: $source,
			content: $content === '' ? '@' . $this->handle : $content,
			publishedAt: $this->fetchedAt,
			cachedAt: $cachedAt,
			authorId: $this->actorId,
			aliases: $aliases,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => 'actor:' . $this->actorId,
			'actor_id' => $this->actorId,
			'acct' => $this->handle,
			'display_name' => $this->displayName,
			'note' => $this->summary,
			'avatar' => $this->avatarUrl,
			'header' => $this->headerUrl,
			'aliases' => $this->aliases,
			'fetched_at' => Time::format($this->fetchedAt),
		];
	}
}
