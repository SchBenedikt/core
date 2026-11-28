<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Support;

use AlohaSocial\Core\Support\TextLength;
use PHPUnit\Framework\TestCase;

final class TextLengthTest extends TestCase
{
	public function testPlainTextCountsRawCharacters(): void
	{
		self::assertSame(10, TextLength::mastodonWeighted('1234567890'));
		self::assertSame(0, TextLength::mastodonWeighted(''));
	}

	public function testUrlCountsAsTheAdvertisedLength(): void
	{
		// A 42-character URL plus 9 characters of text weighs 23 + 9 = 32, not 51.
		$text = 'see https://example.com/' . str_repeat('a', 22) . ' done';
		self::assertSame(32, TextLength::mastodonWeighted($text));
	}

	public function testTrailingPunctuationIsNotPartOfTheUrl(): void
	{
		$text = 'Read https://example.com/a.';
		// The 22-character link weighs 23; "Read " and the period stay text.
		self::assertSame(23 + 6, TextLength::mastodonWeighted($text));
	}

	public function testMultipleUrlsEachCountOnce(): void
	{
		$text = 'https://a.example https://b.example/x';
		self::assertSame(23 + 1 + 23, TextLength::mastodonWeighted($text));
	}

	public function testNonHttpSchemesCountRaw(): void
	{
		$text = 'mailto:someone@example.com';
		self::assertSame(mb_strlen($text), TextLength::mastodonWeighted($text));
	}
}
