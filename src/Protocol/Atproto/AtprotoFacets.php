<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\Atproto;

use function array_slice;
use function count;
use function in_array;
use function mb_strlen;
use function preg_match_all;
use function rtrim;
use function strlen;
use function usort;

/**
 * Builds `app.bsky.richtext.facet` objects from post text.
 *
 * Offsets are **byte** offsets in the UTF-8 text, which is what the lexicons
 * require: an umlaut counts as two bytes and an emoji as four, so a
 * character-based index would point into the middle of a glyph. Links are
 * detected first, then mentions, then tags; a candidate that overlaps an
 * accepted span is dropped, which keeps a `#tag` inside a URL path from
 * becoming a second facet. Links and tags need no network; a mention is
 * resolved to its DID through `AtprotoIdentityResolver` and an unresolvable
 * handle refuses the whole record (`AtprotoFacetException`).
 */
final class AtprotoFacets
{
	public const TYPE_TAG = 'app.bsky.richtext.facet#tag';

	public const TYPE_MENTION = 'app.bsky.richtext.facet#mention';

	public const TYPE_LINK = 'app.bsky.richtext.facet#link';

	public const MAX_TAG_GRAPHEMES = 64;

	/**
	 * Characters that end a URL in prose. A closing bracket is trimmed even
	 * when it belongs to the URL; the record stays clickable and the trailing
	 * sentence keeps reading correctly.
	 */
	private const URL_TRAILING = ".,;:!?)]}'\">";

	public function __construct(
		private readonly ?AtprotoIdentityResolver $identities = null,
	) {
	}

	/**
	 * @return list<array<string, mixed>>
	 *
	 * @throws AtprotoFacetException when a mentioned handle cannot be resolved
	 */
	public function build(string $text, string $pdsUrl): array
	{
		$facets = [];
		$taken = [];

		foreach ($this->linkSpans($text) as $span) {
			$taken[] = $span;
			$facets[] = [
				'index' => ['byteStart' => $span['start'], 'byteEnd' => $span['end']],
				'features' => [['$type' => self::TYPE_LINK, 'uri' => $span['value']]],
			];
		}

		foreach ($this->mentionSpans($text) as $span) {
			if ($this->overlaps($span, $taken)) {
				continue;
			}

			$taken[] = $span;
			$facets[] = [
				'index' => ['byteStart' => $span['start'], 'byteEnd' => $span['end']],
				'features' => [[
					'$type' => self::TYPE_MENTION,
					'did' => $this->resolveMention($span['value'], $pdsUrl),
				]],
			];
		}

		foreach ($this->tagSpans($text) as $span) {
			if ($this->overlaps($span, $taken)) {
				continue;
			}

			$taken[] = $span;
			$facets[] = [
				'index' => ['byteStart' => $span['start'], 'byteEnd' => $span['end']],
				'features' => [['$type' => self::TYPE_TAG, 'tag' => $span['value']]],
			];
		}

		usort(
			$facets,
			static fn (array $left, array $right): int => $left['index']['byteStart'] <=> $right['index']['byteStart'],
		);

		return $facets;
	}

	/**
	 * @return list<array{start: int, end: int, value: string}>
	 */
	private function linkSpans(string $text): array
	{
		preg_match_all('~https?://[^\s<>"\'`]+~iu', $text, $matches, PREG_OFFSET_CAPTURE);

		$spans = [];
		foreach ($matches[0] as [$url, $offset]) {
			$trimmed = rtrim($url, self::URL_TRAILING);
			if (strlen($trimmed) <= strlen('https://')) {
				continue;
			}

			$spans[] = [
				'start' => $offset,
				'end' => $offset + strlen($trimmed),
				'value' => $trimmed,
			];
		}

		return $spans;
	}

	/**
	 * @return list<array{start: int, end: int, value: string}>
	 */
	private function mentionSpans(string $text): array
	{
		preg_match_all(
			'~(?<![\p{L}\p{N}_.@/-])@([A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)+)~u',
			$text,
			$matches,
			PREG_OFFSET_CAPTURE,
		);

		$spans = [];
		foreach ($matches[0] as $index => [$match, $offset]) {
			$spans[] = [
				'start' => $offset,
				'end' => $offset + strlen($match),
				'value' => $matches[1][$index][0],
			];
		}

		return $spans;
	}

	/**
	 * @return list<array{start: int, end: int, value: string}>
	 */
	private function tagSpans(string $text): array
	{
		preg_match_all(
			'~(?<![\p{L}\p{N}_#/&])#([\p{L}\p{N}_]+)~u',
			$text,
			$matches,
			PREG_OFFSET_CAPTURE,
		);

		$spans = [];
		foreach ($matches[0] as $index => [$match, $offset]) {
			$tag = $matches[1][$index][0];
			if (mb_strlen($tag) > self::MAX_TAG_GRAPHEMES) {
				continue;
			}

			$spans[] = [
				'start' => $offset,
				'end' => $offset + strlen($match),
				'value' => $tag,
			];
		}

		return $spans;
	}

	/**
	 * @param list<array{start: int, end: int, value: string}> $taken
	 */
	private function overlaps(array $span, array $taken): bool
	{
		foreach ($taken as $other) {
			if ($span['start'] < $other['end'] && $span['end'] > $other['start']) {
				return true;
			}
		}

		return false;
	}

	private function resolveMention(string $handle, string $pdsUrl): string
	{
		$did = $this->identities?->resolveHandle($pdsUrl, $handle);
		if ($did === null) {
			throw new AtprotoFacetException(
				AtprotoFacetException::MENTION_UNRESOLVED,
				'The mention @' . $handle . ' does not resolve to a DID.',
			);
		}

		return $did;
	}
}
