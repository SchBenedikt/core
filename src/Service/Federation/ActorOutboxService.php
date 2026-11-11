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
	 * @return array<string, mixed>
	 *
	 * @throws UnknownLocalActorException when the handle is not an account here
	 */
	public function collection(string $handle): array
	{
		$handle = trim($handle);

		if ($handle === '' || !$this->actors->has($handle)) {
			throw new UnknownLocalActorException($handle);
		}

		$posts = $this->posts->authorPosts($handle);

		return [
			'@context' => ActivityFactory::CONTEXT,
			'id' => $this->activities->outboxId($handle),
			'type' => 'OrderedCollection',
			// No paging yet, so totalItems is the length of the same list.
			'totalItems' => count($posts),
			'orderedItems' => array_map(
				fn (Post $post): array => $this->activities->createNote($post),
				$posts,
			),
		];
	}
}
