<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Protocol\Atproto\AtprotoExternalLink;
use PHPUnit\Framework\TestCase;

final class AtprotoExternalLinkTest extends TestCase
{
	public function testBuildsOneMetadataFreeCardAndDropsTrailingSentencePunctuation(): void
	{
		self::assertSame([
			'$type' => 'app.bsky.embed.external',
			'external' => [
				'uri' => 'https://example.com/article?id=5',
				'title' => 'example.com',
				'description' => '',
			],
		], AtprotoExternalLink::fromText('Read this: https://example.com/article?id=5.'));
	}

	public function testLeavesSeveralLinksAndInvalidOrCredentialedUrlsUnembedded(): void
	{
		foreach ([
			'https://one.example and https://two.example',
			'ftp://example.com/file',
			'https://user:secret@example.com/private',
			'https://example.com/' . str_repeat('a', AtprotoExternalLink::MAX_URI_LENGTH),
	] as $text) {
			self::assertNull(AtprotoExternalLink::fromText($text));
		}
	}
}
