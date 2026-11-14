<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Filter;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

use function array_values;
use function count;

/**
 * A private keyword filter owned by one reader.
 *
 * A rule is reader state, not moderation: it never changes what is stored,
 * federated or visible to anybody else. `expiresAt` is evaluated on read, so an
 * expired rule needs no cleanup job and cannot start matching again after a
 * clock change.
 */
final readonly class FilterRule
{
	public const MAX_NAME_LENGTH = 100;

	public const MAX_KEYWORDS = 20;

	public const MAX_RULES_PER_USER = 100;

	/**
	 * @param list<FilterContext> $contexts
	 * @param list<FilterKeyword> $keywords
	 */
	public function __construct(
		public string $id,
		public string $userId,
		public string $name,
		public FilterAction $action,
		public array $contexts,
		public array $keywords,
		public DateTimeImmutable $createdAt,
		public ?DateTimeImmutable $expiresAt = null,
	) {
		$name = trim($name);
		if ($id === '' || $userId === '' || $name === '') {
			throw new InvalidArgumentException('A filter needs an id, an owner and a name.');
		}

		if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
			throw new InvalidArgumentException('A filter name is too long.');
		}

		if ($contexts === []) {
			throw new InvalidArgumentException('A filter needs at least one context.');
		}

		$seenContexts = [];
		foreach ($contexts as $context) {
			if (!$context instanceof FilterContext) {
				throw new InvalidArgumentException('Filter contexts must be FilterContext values.');
			}

			if (isset($seenContexts[$context->value])) {
				throw new InvalidArgumentException('A filter cannot repeat a context.');
			}

			$seenContexts[$context->value] = true;
		}

		if ($keywords === []) {
			throw new InvalidArgumentException('A filter needs at least one keyword.');
		}

		if (count($keywords) > self::MAX_KEYWORDS) {
			throw new InvalidArgumentException('A filter has too many keywords.');
		}

		$seenKeywords = [];
		foreach ($keywords as $keyword) {
			if (!$keyword instanceof FilterKeyword) {
				throw new InvalidArgumentException('Filter keywords must be FilterKeyword values.');
			}

			$normalized = $keyword->normalized();
			if (isset($seenKeywords[$normalized])) {
				throw new InvalidArgumentException('A filter cannot repeat a keyword.');
			}

			$seenKeywords[$normalized] = true;
		}

		if (array_values($contexts) !== $contexts || array_values($keywords) !== $keywords) {
			throw new InvalidArgumentException('Filter contexts and keywords must be lists.');
		}

		if ($expiresAt !== null && $expiresAt <= $createdAt) {
			throw new InvalidArgumentException('A filter expiry must be after its creation.');
		}
	}

	public function appliesTo(FilterContext $context): bool
	{
		foreach ($this->contexts as $candidate) {
			if ($candidate === $context) {
				return true;
			}
		}

		return false;
	}

	public function isExpired(DateTimeImmutable $now): bool
	{
		return $this->expiresAt !== null && $this->expiresAt <= $now;
	}

	/**
	 * The neutral API shape. `contexts` are strings, not enum cases, so the
	 * response is plain JSON without a presenter translating each value.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'user_id' => $this->userId,
			'name' => $this->name,
			'action' => $this->action->value,
			'contexts' => array_map(static fn (FilterContext $context): string => $context->value, $this->contexts),
			'keywords' => array_map(static fn (FilterKeyword $keyword): array => $keyword->toArray(), $this->keywords),
			'created_at' => Time::format($this->createdAt),
			'expires_at' => $this->expiresAt === null ? null : Time::format($this->expiresAt),
		];
	}
}
