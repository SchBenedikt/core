<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Domain\Post\TimelineCursor;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Service\TimelineService;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

/**
 * `GET /api/v1/accounts/{id}/statuses?limit=&before=`
 *
 * The profile timeline of one local account. Unlike a scan of the public
 * timeline this reaches every post the viewer may see, so an older post does
 * not disappear just because newer posts pushed it off the public page.
 * Followers-only posts are included only for the author and accepted local
 * followers; anonymous readers get the public and unlisted rows.
 */
final class AccountStatusesHandler implements Handler
{
	public function __construct(
		private readonly PostRepository $posts,
		private readonly PostService $postService,
		private readonly ?MediaService $media = null,
	) {
	}

	public function handle(Request $request): Response
	{
		$authorId = trim((string) $request->attribute('id', ''));
		if ($authorId === '') {
			throw HttpException::notFound('Account not found.');
		}

		$limit = $request->queryInt('limit', TimelineService::DEFAULT_LIMIT, 1, TimelineService::MAX_LIMIT);
		$before = $this->cursor($request->queryParam('before'));

		$fetch = min(TimelineService::MAX_LIMIT, $limit * 2);
		$rows = $this->posts->timelineForAuthors([$authorId], $fetch, $before);

		$visible = [];
		$lastReturned = null;
		foreach ($rows as $post) {
			try {
				$visible[] = $this->postService->getVisible($post->id, $request->userId);
				if (count($visible) <= $limit) {
					$lastReturned = $post;
				}
			} catch (PostNotFoundException) {
				// The repository already limited the audience; this keeps the
				// handler's answer identical to the single-post route.
			}
		}

		$hasExtraVisible = count($visible) > $limit;
		$visible = array_slice($visible, 0, $limit);
		$presented = $this->media === null
			? array_map(static fn ($post): array => $post->toArray(), $visible)
			: $this->media->presentPosts($visible);

		// If the fetched block has an extra visible post, continue from the last
		// visible post returned so that extra row appears on the next page.
		// Otherwise continue from the last scanned row, including hidden rows,
		// so anonymous readers can advance through followers-only pages.
		$lastScanned = $rows === [] ? null : $rows[array_key_last($rows)];
		$last = $hasExtraVisible ? $lastReturned : $lastScanned;

		return Response::json([
			'posts' => $presented,
			'next_before' => $last === null || (!$hasExtraVisible && count($rows) < $fetch)
				? null
				: $this->encodeCursor($last->publishedAt, $last->id),
		]);
	}

	private function cursor(?string $before): DateTimeImmutable|TimelineCursor|null
	{
		if ($before === null || trim($before) === '') {
			return null;
		}

		$before = trim($before);
		if (!str_starts_with($before, 'v1.')) {
			try {
				return Time::parse($before);
			} catch (InvalidArgumentException) {
				throw HttpException::badRequest('invalid_cursor', 'The before cursor is malformed.');
			}
		}

		$encoded = substr($before, 3);
		$decoded = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
		if ($decoded === false || strlen($decoded) > 1024) {
			throw HttpException::badRequest('invalid_cursor', 'The before cursor is malformed.');
		}

		try {
			$value = json_decode($decoded, true, 8, JSON_THROW_ON_ERROR);
			if (!is_array($value) || !is_string($value['published_at'] ?? null)
				|| !is_string($value['id'] ?? null) || $value['id'] === '' || strlen($value['id']) > 255) {
				throw new InvalidArgumentException('Malformed cursor fields.');
			}

			return new TimelineCursor(Time::parse($value['published_at']), $value['id']);
		} catch (JsonException|InvalidArgumentException) {
			throw HttpException::badRequest('invalid_cursor', 'The before cursor is malformed.');
		}
	}

	private function encodeCursor(DateTimeImmutable $publishedAt, string $id): string
	{
		$json = json_encode(['published_at' => Time::format($publishedAt), 'id' => $id], JSON_THROW_ON_ERROR);
		return 'v1.' . rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
	}
}
