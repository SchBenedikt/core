<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Post\Hashtags;
use PHPUnit\Framework\TestCase;

final class HashtagsTest extends TestCase
{
	public function testExtractionNormalizesDeduplicatesAndRequiresAWordBoundary(): void
	{
		self::assertSame(
			['devops', 'php', 'café'],
			Hashtags::extract('Hello #DevOps, #php and #café. Again #DEVOPS.'),
		);
		self::assertSame([], Hashtags::extract('no tags and an email a#b'));
		self::assertSame(['tag', 'tag_extra'], Hashtags::extract('#tag, #tag_extra'));
	}

	public function testExtractionCapsTheNumberOfStoredTags(): void
	{
		$tags = [];
		for ($i = 0; $i < Hashtags::MAX_PER_POST + 10; $i++) {
			$tags[] = '#tag' . $i;
		}

		self::assertCount(Hashtags::MAX_PER_POST, Hashtags::extract(implode(' ', $tags)));
	}

	public function testNormalizeAcceptsOneLeadingHashAndRejectsMalformedValues(): void
	{
		self::assertSame('devops', Hashtags::normalize('#DevOps'));
		self::assertSame('devops', Hashtags::normalize('devops'));
		self::assertSame('café', Hashtags::normalize('#Café'));
		self::assertNull(Hashtags::normalize(''));
		self::assertNull(Hashtags::normalize('#'));
		self::assertNull(Hashtags::normalize('two words'));
		self::assertNull(Hashtags::normalize('bad!'));
		self::assertNull(Hashtags::normalize('#' . str_repeat('a', Hashtags::MAX_LENGTH + 1)));
	}
}
