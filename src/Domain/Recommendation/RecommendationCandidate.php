<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Recommendation;

use AlohaSocial\Core\Domain\Post\Hashtags;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_string;
use function mb_strlen;
use function preg_match;
use function trim;

/**
 * One already-loaded, public post that may enter a ranked feed.
 *
 * A candidate is not an authorization decision. The source must only return
 * posts whose visibility is public; the service re-checks that and every
 * per-reader rule before ranking and again before presenting a cached page.
 * `$languages` is optional because the local post model has no language field:
 * a host source that knows the language can supply it, and a reader who asks
 * for specific languages will not receive unknown-language rows.
 */
final readonly class RecommendationCandidate
{
	public const MAX_ID_LENGTH = 700;

	public const MAX_TARGET_LENGTH = 512;

	public const MAX_AUTHOR_LENGTH = 512;

	public const MAX_CONTENT_LENGTH = 20000;

	public const MAX_WARNING_LENGTH = 255;

	public const MAX_LANGUAGES = 10;

	/**
	 * @param list<string> $languages
	 */
	public function __construct(
		public string $id,
		public string $targetId,
		public RecommendationCandidateKind $kind,
		public string $authorId,
		public ?string $objectAuthorId,
		public string $content,
		public ?string $contentWarning,
		public DateTimeImmutable $publishedAt,
		public Visibility $visibility,
		public array $languages = [],
		public int $engagement = 0,
	) {
		if ($id === '' || mb_strlen($id) > self::MAX_ID_LENGTH) {
			throw new InvalidArgumentException('A recommendation candidate needs a bounded id.');
		}

		if ($targetId === '' || mb_strlen($targetId) > self::MAX_TARGET_LENGTH) {
			throw new InvalidArgumentException('A recommendation candidate needs a bounded target id.');
		}

		if ($authorId === '' || mb_strlen($authorId) > self::MAX_AUTHOR_LENGTH) {
			throw new InvalidArgumentException('A recommendation candidate needs a bounded author.');
		}

		if ($objectAuthorId !== null
			&& ($objectAuthorId === '' || mb_strlen($objectAuthorId) > self::MAX_AUTHOR_LENGTH)
		) {
			throw new InvalidArgumentException('A recommendation object author is invalid.');
		}

		if (mb_strlen($content) > self::MAX_CONTENT_LENGTH) {
			throw new InvalidArgumentException('A recommendation candidate is too long.');
		}

		if ($contentWarning !== null
			&& ($contentWarning === '' || mb_strlen($contentWarning) > self::MAX_WARNING_LENGTH)
		) {
			throw new InvalidArgumentException('A recommendation content warning is invalid.');
		}

		if (!is_array($languages)
			|| array_values($languages) !== $languages
			|| count($languages) > self::MAX_LANGUAGES
		) {
			throw new InvalidArgumentException('A recommendation language list is invalid.');
		}

		$seen = [];
		foreach ($languages as $language) {
			if (!is_string($language)
				|| preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/D', $language) !== 1
				|| isset($seen[$language])
			) {
				throw new InvalidArgumentException('A recommendation language tag is invalid.');
			}

			$seen[$language] = true;
		}

		if ($engagement < 0) {
			throw new InvalidArgumentException('Recommendation engagement cannot be negative.');
		}
	}

	/**
	 * @return list<string>
	 */
	public function hashtags(): array
	{
		return Hashtags::extract($this->content);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'candidate_id' => $this->id,
			'target_id' => $this->targetId,
			'kind' => $this->kind->value,
			'author_id' => $this->authorId,
			'object_author_id' => $this->objectAuthorId,
			'content' => $this->content,
			'content_warning' => $this->contentWarning,
			'published_at' => Time::format($this->publishedAt),
			'visibility' => $this->visibility->value,
			'languages' => $this->languages,
		];
	}

	public static function localId(string $postId): string
	{
		return RecommendationCandidateKind::Local->value . ':' . $postId;
	}

	public static function remoteId(string $activityId): string
	{
		return RecommendationCandidateKind::Remote->value . ':' . $activityId;
	}

	/**
	 * @param list<string> $languages
	 */
	public function sharesLanguage(array $languages): bool
	{
		foreach ($languages as $language) {
			if (in_array($language, $this->languages, true)) {
				return true;
			}
		}

		return false;
	}

	public function isSensitive(): bool
	{
		return $this->contentWarning !== null && trim($this->contentWarning) !== '';
	}
}
