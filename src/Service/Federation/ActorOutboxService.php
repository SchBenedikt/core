<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Federation;

use AlohaSocial\Core\Domain\Federation\LocalActorDirectory;
use AlohaSocial\Core\Domain\Federation\UnknownLocalActorException;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Protocol\ActivityPub\OrderedCollection;

/**
 * The public outbox of a local account.
 *
 * The collection id comes from the same `ActivityFactory::outboxId()` the
 * actor document advertises, and every item is the very `Create` document
 * outbound delivery sends. A peer therefore gets the same activity id whether
 * it reads the outbox or receives the post in its inbox.
 */
final class ActorOutboxService
{
	public function __construct(
		private readonly ActivityFactory $activities,
		private readonly LocalActorDirectory $actors,
		private readonly PostRepository $posts,
	) {
	}

	/**
	 * The root document when `$page` is null, otherwise one page of it.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws UnknownLocalActorException when the handle is not an account here
	 */
	public function collection(
		string $handle,
		?int $page = null,
		int $limit = OrderedCollection::DEFAULT_LIMIT,
	): array {
		$handle = trim($handle);

		if ($handle === '' || !$this->actors->has($handle)) {
			throw new UnknownLocalActorException($handle);
		}

		$limit = OrderedCollection::clampLimit($limit);
		$id = $this->activities->outboxId($handle);
		$total = $this->posts->authorPostCount($handle);

		if ($page === null) {
			return OrderedCollection::root($id, $total, $limit);
		}

		$posts = $this->posts->authorPostsPage(
			$handle,
			$limit,
			OrderedCollection::offset($page, $limit),
		);

		return OrderedCollection::page(
			$id,
			$page,
			$limit,
			$total,
			array_map(
				fn (Post $post): array => $this->activities->createNote($post),
				$posts,
			),
		);
	}
}
