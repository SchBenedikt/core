<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Filter;

use InvalidArgumentException;

use function preg_match;

/**
 * One keyword of a filter rule.
 *
 * The text is stored as the reader typed it. Matching normalizes both sides,
 * so the stored form never has to be rewritten when Unicode rules change.
 * Whole-word matching uses letter/number boundaries, not `\b`: a keyword in
 * Japanese or next to an emoji must still match the way a reader expects.
 */
final readonly class FilterKeyword
{
	public const MAX_LENGTH = 100;

	public function __construct(
		public string $text,
		public bool $wholeWord = false,
	) {
		$text = trim($text);
		if ($text === '') {
			throw new InvalidArgumentException('A filter keyword cannot be empty.');
		}

		if (mb_strlen($text) > self::MAX_LENGTH) {
			throw new InvalidArgumentException('A filter keyword is too long.');
		}

		if (preg_match('/[\x00-\x1F\x7F]/u', $text) === 1) {
			throw new InvalidArgumentException('A filter keyword cannot contain control characters.');
		}
	}

	/**
	 * The comparison form: case-folded, and compatibility-normalized when the
	 * intl extension is available.
	 */
	public function normalized(): string
	{
		$text = $this->text;
		if (class_exists(\Normalizer::class)) {
			$normalized = \Normalizer::normalize($text, \Normalizer::FORM_KC);
			if (is_string($normalized)) {
				$text = $normalized;
			}
		}

		return mb_strtolower($text, 'UTF-8');
	}

	/**
	 * @return array{text: string, whole_word: bool}
	 */
	public function toArray(): array
	{
		return ['text' => $this->text, 'whole_word' => $this->wholeWord];
	}
}
