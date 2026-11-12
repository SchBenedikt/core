<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Protocol\Atproto\AtprotoPostRecord;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

final class AtprotoPostRecordTest extends TestCase
{
	public function testItBuildsAPlainTextRecord(): void
	{
		$post = $this->post('hello AT Protocol');

		self::assertSame([
			'$type' => 'app.bsky.feed.post',
			'text' => 'hello AT Protocol',
			'createdAt' => '2026-11-11T10:00:00Z',
		], AtprotoPostRecord::fromPost($post));
	}

	public function testAContentWarningBecomesASelfLabel(): void
	{
		$post = $this->post('chapter one', 'spoilers');

		self::assertSame([
			'$type' => 'com.atproto.label.defs#selfLabels',
			'values' => [['val' => 'content-warning']],
		], AtprotoPostRecord::fromPost($post)['labels']);
	}

	public function testTextLimitsUseBothGraphemesAndBytes(): void
	{
		self::assertTrue(AtprotoPostRecord::acceptsText(str_repeat('a', 300)));
		self::assertFalse(AtprotoPostRecord::acceptsText(str_repeat('a', 301)));
		self::assertFalse(AtprotoPostRecord::acceptsText(str_repeat('ä', 1501)));
	}

	private function post(string $content, ?string $warning = null): Post
	{
		return new Post(
			'post-1',
			'alice',
			$content,
			Visibility::Public,
			Time::parse('2026-11-11T10:00:00Z'),
			$warning,
		);
	}
}
