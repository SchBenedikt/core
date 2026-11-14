<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Api;

use AlohaSocial\Core\Domain\Filter\FilterAction;
use AlohaSocial\Core\Domain\Filter\FilterContext;
use AlohaSocial\Core\Domain\Filter\FilterMatch;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Service\FilterService;
use AlohaSocial\Core\Service\MediaService;

/**
 * Applies the reader's rules and then presents a page of posts.
 *
 * The three list handlers share this so a `hide` cannot be forgotten in one
 * surface or a `warn` redacted differently in another. Media is optional, the
 * same way the routes work without a blob store.
 */
final class PostFilterPresenter
{
	public function __construct(
		private readonly FilterService $filters,
		private readonly ?MediaService $media,
	) {
	}

	/**
	 * @param list<Post> $posts
	 *
	 * @return list<array<string, mixed>>
	 */
	public function present(
		string $userId,
		FilterContext $context,
		array $posts,
		bool $includeFiltered,
	): array {
		$altTexts = $this->media?->altTextsForPosts($posts) ?? [];

		$kept = [];
		$matches = [];
		foreach ($posts as $post) {
			$match = $this->filters->matchPost($userId, $context, $post, $altTexts[$post->id] ?? []);
			if ($match?->action === FilterAction::Hide) {
				continue;
			}

			if ($match !== null) {
				$matches[$post->id] = $match;
			}

			$kept[] = $post;
		}

		$presented = $this->media === null
			? array_map(static fn (Post $post): array => $post->toArray(), $kept)
			: $this->media->presentPosts($kept);

		if ($matches === []) {
			return $presented;
		}

		foreach ($presented as $index => $row) {
			$match = $matches[$row['id']] ?? null;
			if (!$match instanceof FilterMatch) {
				continue;
			}

			$presented[$index]['filtered'] = [$this->filters->filterBlock($match)];
			if (!$includeFiltered) {
				$presented[$index] = $this->filters->redact($presented[$index]);
			}
		}

		return $presented;
	}
}
