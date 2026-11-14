<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Protocol\Atproto\AtprotoFacetException;
use AlohaSocial\Core\Protocol\Atproto\AtprotoFacets;
use AlohaSocial\Core\Protocol\Atproto\AtprotoIdentityResolver;
use PHPUnit\Framework\TestCase;

final class AtprotoFacetsTest extends TestCase
{
	private const PDS = 'https://pds.example';

	public function testTagOffsetsAreUtf8ByteOffsets(): void
	{
		$text = 'Grüße #hallo';

		$facets = (new AtprotoFacets())->build($text, self::PDS);

		self::assertCount(1, $facets);
		self::assertSame(mb_strlen('Grüße '), 6);
		self::assertSame(strlen('Grüße '), 8);
		self::assertSame(8, $facets[0]['index']['byteStart']);
		self::assertSame(strlen($text), $facets[0]['index']['byteEnd']);
		self::assertSame(
			[['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'hallo']],
			$facets[0]['features'],
		);
	}

	public function testAnEmojiCountsAsFourBytes(): void
	{
		$facets = (new AtprotoFacets())->build('🎉 #party', self::PDS);

		self::assertCount(1, $facets);
		self::assertSame(5, $facets[0]['index']['byteStart']);
		self::assertSame(strlen('🎉 #party'), $facets[0]['index']['byteEnd']);
	}

	public function testLinksAreTrimmedAtSentenceAndBracketBoundaries(): void
	{
		$text = 'see https://example.com/a?b=1. and (https://example.com/b)';

		$facets = (new AtprotoFacets())->build($text, self::PDS);

		self::assertCount(2, $facets);
		self::assertSame('https://example.com/a?b=1', $facets[0]['features'][0]['uri']);
		self::assertSame(strlen('see '), $facets[0]['index']['byteStart']);
		self::assertSame('https://example.com/b', $facets[1]['features'][0]['uri']);
		self::assertSame(
			'https://example.com/b',
			substr($text, $facets[1]['index']['byteStart'], $facets[1]['index']['byteEnd'] - $facets[1]['index']['byteStart']),
		);
	}

	public function testATagInsideAUrlIsNotASecondFacet(): void
	{
		$facets = (new AtprotoFacets())->build('https://example.com/#anchor', self::PDS);

		self::assertCount(1, $facets);
		self::assertSame('app.bsky.richtext.facet#link', $facets[0]['features'][0]['$type']);
	}

	public function testTagsNeedAWordBoundaryAndDropTrailingPunctuation(): void
	{
		$facets = (new AtprotoFacets())->build('C# stays, a#b stays, #tag_2026. and #zwei', self::PDS);

		self::assertSame(
			['tag_2026', 'zwei'],
			array_map(static fn (array $facet): string => $facet['features'][0]['tag'], $facets),
		);
		self::assertSame(strlen('C# stays, a#b stays, #tag_2026'), $facets[0]['index']['byteEnd']);
	}

	public function testAMentionIsResolvedToItsDid(): void
	{
		$facets = (new AtprotoFacets($this->identities(['alice.test' => 'did:plc:alice'])))
			->build('hi @alice.test!', self::PDS);

		self::assertCount(1, $facets);
		self::assertSame(
			[['$type' => 'app.bsky.richtext.facet#mention', 'did' => 'did:plc:alice']],
			$facets[0]['features'],
		);
		self::assertSame(3, $facets[0]['index']['byteStart']);
		self::assertSame(strlen('hi @alice.test'), $facets[0]['index']['byteEnd']);
	}

	public function testAnEmailAddressIsNotAMention(): void
	{
		self::assertSame([], (new AtprotoFacets($this->identities([])))->build('mail alice@example.com', self::PDS));
	}

	public function testAMentionInsideAUrlIsNotAMention(): void
	{
		$facets = (new AtprotoFacets($this->identities(['alice.test' => 'did:plc:alice'])))
			->build('https://example.com/@alice.test', self::PDS);

		self::assertCount(1, $facets);
		self::assertSame('app.bsky.richtext.facet#link', $facets[0]['features'][0]['$type']);
	}

	public function testAnUnresolvableHandleRefusesTheRecord(): void
	{
		$this->expectException(AtprotoFacetException::class);

		try {
			(new AtprotoFacets($this->identities([])))->build('hi @unknown.test', self::PDS);
		} catch (AtprotoFacetException $exception) {
			self::assertSame(AtprotoFacetException::MENTION_UNRESOLVED, $exception->reason);

			throw $exception;
		}
	}

	public function testAMentionWithoutAResolverIsRefused(): void
	{
		$this->expectException(AtprotoFacetException::class);

		(new AtprotoFacets())->build('hi @alice.test', self::PDS);
	}

	public function testFacetsAreSortedByByteStart(): void
	{
		$facets = (new AtprotoFacets($this->identities(['alice.test' => 'did:plc:alice'])))
			->build('@alice.test #tag', self::PDS);

		self::assertCount(2, $facets);
		self::assertSame(0, $facets[0]['index']['byteStart']);
		self::assertSame(strlen('@alice.test '), $facets[1]['index']['byteStart']);
	}

	/**
	 * @param array<string, string> $dids
	 */
	private function identities(array $dids): AtprotoIdentityResolver
	{
		return new class ($dids) implements AtprotoIdentityResolver {
			/**
			 * @param array<string, string> $dids
			 */
			public function __construct(
				private readonly array $dids,
			) {
			}

			public function resolveHandle(string $pdsUrl, string $handle): ?string
			{
				return $this->dids[$handle] ?? null;
			}
		};
	}
}
