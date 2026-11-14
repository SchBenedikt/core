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
use AlohaSocial\Core\Protocol\Atproto\AtprotoReplyRef;
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

	public function testAPlainRecordHasNoReplyOrFacets(): void
	{
		$record = AtprotoPostRecord::fromPost($this->post('hello'));

		self::assertArrayNotHasKey('reply', $record);
		self::assertArrayNotHasKey('facets', $record);
	}

	public function testReplyAndFacetsArePartOfTheRecord(): void
	{
		$record = AtprotoPostRecord::fromPost(
			$this->post('hello #tag'),
			new AtprotoReplyRef(
				'at://did:plc:alice/app.bsky.feed.post/root',
				'bafy-root',
				'at://did:plc:alice/app.bsky.feed.post/parent',
				'bafy-parent',
			),
			[[
				'index' => ['byteStart' => 6, 'byteEnd' => 10],
				'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'tag']],
			]],
		);

		self::assertSame([
			'root' => ['uri' => 'at://did:plc:alice/app.bsky.feed.post/root', 'cid' => 'bafy-root'],
			'parent' => ['uri' => 'at://did:plc:alice/app.bsky.feed.post/parent', 'cid' => 'bafy-parent'],
		], $record['reply']);
		self::assertSame('tag', $record['facets'][0]['features'][0]['tag']);
		self::assertSame('hello #tag', $record['text']);
	}

	public function testAnEmbedIsPartOfTheRecord(): void
	{
		$embed = [
			'$type' => 'app.bsky.embed.images',
			'images' => [[
				'image' => ['$type' => 'blob', 'ref' => ['$link' => 'bafy-img'], 'mimeType' => 'image/png', 'size' => 3],
				'alt' => '',
			]],
		];

		$record = AtprotoPostRecord::fromText(
			'hello',
			Time::parse('2026-11-11T10:00:00Z'),
			null,
			null,
			[],
			$embed,
		);

		self::assertSame($embed, $record['embed']);
		self::assertArrayNotHasKey(
			'embed',
			AtprotoPostRecord::fromText('hello', Time::parse('2026-11-11T10:00:00Z')),
		);
	}

	public function testFromTextBuildsTheSameRecordAsFromPost(): void
	{
		self::assertSame(
			AtprotoPostRecord::fromPost($this->post('hello', 'spoilers')),
			AtprotoPostRecord::fromText('hello', Time::parse('2026-11-11T10:00:00Z'), 'spoilers'),
		);
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
