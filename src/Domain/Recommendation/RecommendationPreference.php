<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

use function array_values;
use function count;
use function is_array;
use function is_bool;
use function is_string;
use function mb_strlen;
use function preg_match;

/**
 * One reader's explicit recommendation settings.
 *
 * Behavioral recording is off unless `$behavioralConsent` is true. The
 * feature-level switch lives in configuration; this value only decides
 * whether this reader wants a ranked feed and whether their signals may be
 * stored.
 */
final readonly class RecommendationPreference
{
	public const MAX_INTERESTS = 20;

	public const MAX_LANGUAGES = 10;

	public const MAX_TAG_LENGTH = 100;

	/**
	 * @param list<string> $interests
	 * @param list<string> $languages
	 */
	public function __construct(
		public string $userId,
		public bool $enabled,
		public bool $behavioralConsent,
		public array $interests,
		public array $languages,
		public DateTimeImmutable $updatedAt,
	) {
		if ($userId === '') {
			throw new InvalidArgumentException('A recommendation preference needs a user.');
		}

		if (!is_array($interests)
			|| array_values($interests) !== $interests
			|| count($interests) > self::MAX_INTERESTS
		) {
			throw new InvalidArgumentException('Recommendation interests are invalid.');
		}

		$seen = [];
		foreach ($interests as $interest) {
			if (!is_string($interest)
				|| $interest === ''
				|| mb_strlen($interest) > self::MAX_TAG_LENGTH
				|| preg_match('/^[\p{L}\p{N}_]+$/u', $interest) !== 1
				|| isset($seen[$interest])
			) {
				throw new InvalidArgumentException('A recommendation interest is invalid.');
			}

			$seen[$interest] = true;
		}

		if (!is_array($languages)
			|| array_values($languages) !== $languages
			|| count($languages) > self::MAX_LANGUAGES
		) {
			throw new InvalidArgumentException('Recommendation languages are invalid.');
		}

		$seenLanguages = [];
		foreach ($languages as $language) {
			if (!is_string($language)
				|| preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/D', $language) !== 1
				|| isset($seenLanguages[$language])
			) {
				throw new InvalidArgumentException('A recommendation language tag is invalid.');
			}

			$seenLanguages[$language] = true;
		}
	}

	public static function defaults(string $userId, DateTimeImmutable $at): self
	{
		return new self($userId, true, false, [], [], $at);
	}

	/**
	 * @param list<string> $interests
	 * @param list<string> $languages
	 */
	public function with(
		bool $enabled,
		bool $behavioralConsent,
		array $interests,
		array $languages,
		DateTimeImmutable $at,
	): self {
		return new self($this->userId, $enabled, $behavioralConsent, $interests, $languages, $at);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'enabled' => $this->enabled,
			'behavioral_consent' => $this->behavioralConsent,
			'interests' => $this->interests,
			'languages' => $this->languages,
			'updated_at' => Time::format($this->updatedAt),
		];
	}
}
