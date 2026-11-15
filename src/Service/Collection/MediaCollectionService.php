<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Collection;

use AlohaSocial\Core\Domain\Collection\MediaCollection;
use AlohaSocial\Core\Domain\Collection\MediaCollectionItem;
use AlohaSocial\Core\Domain\Collection\MediaCollectionRepository;
use AlohaSocial\Core\Domain\Media\AttachmentRepository;
use AlohaSocial\Core\Domain\Media\MediaKind;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Platform\Clock\Clock;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Service\LocalRelationshipService;
use AlohaSocial\Core\Service\MediaService;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Service\ValidationException;
use AlohaSocial\Core\Support\IdGenerator;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

use function array_slice;
use function count;
use function mb_strlen;
use function trim;

/**
 * Owner-scoped media collections with per-item visibility.
 *
 * A collection never publishes a copy of a post. Every read resolves each
 * member through {@see PostService::getVisible()} for the actual viewer, so a
 * followers-only item cannot leak through an item array, a count or a cover.
 */
final class MediaCollectionService
{
	public const DEFAULT_LIMIT = 20;

	public const MAX_LIMIT = 40;

	public const MAX_COLLECTIONS = 50;

	public function __construct(
		private readonly MediaCollectionRepository $collections,
		private readonly PostService $posts,
		private readonly AttachmentRepository $attachments,
		private readonly Clock $clock,
		private readonly IdGenerator $ids,
		private readonly ?MediaService $media = null,
		private readonly ?LocalRelationshipService $relationships = null,
	) {
	}

	/**
	 * @throws ValidationException
	 */
	public function create(string $ownerId, string $title, string $description, Visibility $audience): MediaCollection
	{
		$title = trim($title);
		$description = trim($description);
		$this->validateDetails($title, $description, $audience);

		if ($this->collections->countForOwner($ownerId) >= self::MAX_COLLECTIONS) {
			throw new ValidationException(['collections' => 'At most ' . self::MAX_COLLECTIONS . ' collections can be created.']);
		}

		$now = Time::toSecondPrecision($this->clock->now());
		$collection = new MediaCollection($this->ids->generate(), $ownerId, $title, $description, $audience, $now, $now);
		$this->collections->insert($collection);

		return $collection;
	}

	/**
	 * @throws MediaCollectionNotFoundException
	 * @throws ValidationException
	 */
	public function update(
		string $ownerId,
		string $collectionId,
		?string $title,
		?string $description,
		?Visibility $audience,
	): MediaCollection {
		$collection = $this->owned($ownerId, $collectionId);
		$title = $title === null ? $collection->title : trim($title);
		$description = $description === null ? $collection->description : trim($description);
		$audience ??= $collection->audience;
		$this->validateDetails($title, $description, $audience);

		$updated = $collection->withDetails($title, $description, $audience, Time::toSecondPrecision($this->clock->now()));
		$this->collections->update($updated);

		return $updated;
	}

	/**
	 * @throws MediaCollectionNotFoundException
	 */
	public function delete(string $ownerId, string $collectionId): void
	{
		$this->owned($ownerId, $collectionId);
		$this->collections->delete($collectionId);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function forOwner(string $ownerId): array
	{
		return array_map(fn (MediaCollection $collection): array => $this->present($collection, $ownerId, self::MAX_LIMIT, 0), $this->collections->forOwner($ownerId));
	}

	/**
	 * Collections an actor's profile may enumerate for this viewer. Unlisted
	 * is link-only and therefore never appears here; followers-only appears
	 * only to an accepted follower or the owner.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function forActor(string $ownerId, ?string $viewerId, int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
	{
		$limit = max(1, min(self::MAX_LIMIT, $limit));
		$offset = max(0, $offset);
		$collections = [];
		foreach ($this->collections->forOwner($ownerId) as $collection) {
			if ($viewerId === $ownerId || $collection->audience === Visibility::Public
				|| ($collection->audience === Visibility::Followers && $this->isFollower($viewerId, $ownerId))
			) {
				$collections[] = $collection;
			}
		}

		return array_map(
			fn (MediaCollection $collection): array => $this->present($collection, $viewerId, 1, 0),
			array_slice($collections, $offset, $limit),
		);
	}

	/**
	 * @throws MediaCollectionNotFoundException
	 *
	 * @return array<string, mixed>
	 */
	public function show(?string $viewerId, string $collectionId, int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
	{
		$collection = $this->collections->find($collectionId);
		if ($collection === null || !$this->canView($viewerId, $collection)) {
			throw new MediaCollectionNotFoundException();
		}

		return $this->present(
			$collection,
			$viewerId,
			max(1, min(self::MAX_LIMIT, $limit)),
			max(0, $offset),
		);
	}

	/**
	 * @throws MediaCollectionNotFoundException
	 * @throws PostNotFoundException
	 * @throws ValidationException
	 */
	public function addItem(string $ownerId, string $collectionId, string $postId): MediaCollectionItem
	{
		$collection = $this->owned($ownerId, $collectionId);
		$existing = $this->collections->findItem($collection->id, $postId);
		if ($existing !== null) {
			return $existing;
		}

		try {
			$post = $this->posts->getVisible($postId, $ownerId);
		} catch (PostNotFoundException) {
			throw new PostNotFoundException($postId);
		}

		if ($post->authorId !== $ownerId) {
			throw new ValidationException(['post_id' => 'A collection can only contain your own posts.']);
		}

		if ($post->isArchived()) {
			throw new ValidationException(['post_id' => 'An archived post cannot be added to a collection.']);
		}

		if (!$this->hasImageOrVideo($post)) {
			throw new ValidationException(['post_id' => 'A collection item needs an image or video attachment.']);
		}

		if ($this->collections->countItems($collection->id) >= MediaCollection::MAX_ITEMS) {
			throw new ValidationException(['items' => 'A collection can contain at most ' . MediaCollection::MAX_ITEMS . ' posts.']);
		}

		$position = $this->collections->countItems($collection->id) + 1;
		try {
			$item = new MediaCollectionItem(
				$collection->id,
				$postId,
				$position,
				Time::toSecondPrecision($this->clock->now()),
			);
			$this->collections->addItem($item);
		} catch (DatabaseException) {
			$existing = $this->collections->findItem($collection->id, $postId);
			if ($existing !== null) {
				return $existing;
			}

			throw new ValidationException(['items' => 'The post could not be added to the collection.']);
		}

		return $item;
	}

	/**
	 * @throws MediaCollectionNotFoundException
	 */
	public function removeItem(string $ownerId, string $collectionId, string $postId): void
	{
		$this->owned($ownerId, $collectionId);
		$this->collections->removeItem($collectionId, $postId);
	}

	/**
	 * @throws MediaCollectionNotFoundException
	 */
	private function owned(string $ownerId, string $collectionId): MediaCollection
	{
		$collection = $this->collections->find($collectionId);
		if ($collection === null || $collection->ownerId !== $ownerId) {
			throw new MediaCollectionNotFoundException();
		}

		return $collection;
	}

	private function canView(?string $viewerId, MediaCollection $collection): bool
	{
		if ($viewerId === $collection->ownerId) {
			return true;
		}

		return match ($collection->audience) {
			Visibility::Public, Visibility::Unlisted => true,
			Visibility::Followers => $this->isFollower($viewerId, $collection->ownerId),
			Visibility::Direct => false,
		};
	}

	private function isFollower(?string $viewerId, string $ownerId): bool
	{
		return $viewerId !== null && $this->relationships?->isLocalFollower($viewerId, $ownerId) === true;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function present(MediaCollection $collection, ?string $viewerId, int $limit, int $offset): array
	{
		$all = $this->collections->items($collection->id, MediaCollection::MAX_ITEMS, 0);
		$visible = [];
		foreach ($all as $item) {
			$post = $this->visiblePost($viewerId, $collection->ownerId, $item->postId);
			if ($post !== null) {
				$visible[] = $this->presentItem($post, $item);
			}
		}

		$data = $collection->toArray();
		$data['item_count'] = count($visible);
		$data['items'] = array_slice($visible, $offset, $limit);
		$data['next_offset'] = $offset + $limit < count($visible) ? $offset + $limit : null;
		$data['cover'] = $visible === [] ? null : ($visible[0]['post']['attachments'][0] ?? null);

		return $data;
	}

	private function visiblePost(?string $viewerId, string $ownerId, string $postId): ?Post
	{
		try {
			$post = $this->posts->getVisible($postId, $viewerId);
		} catch (PostNotFoundException) {
			return null;
		}

		if ($post->authorId !== $ownerId || $post->isArchived()) {
			return null;
		}

		return $this->hasImageOrVideo($post) ? $post : null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function presentItem(Post $post, MediaCollectionItem $item): array
	{
		$data = $this->media?->presentPost($post) ?? $post->toArray();

		return [
			'post' => $data,
			'position' => $item->position,
			'added_at' => Time::format($item->addedAt),
		];
	}

	private function hasImageOrVideo(Post $post): bool
	{
		foreach ($this->attachments->findMany($post->attachmentIds) as $attachment) {
			if ($attachment->ownerId === $post->authorId
				&& ($attachment->kind === MediaKind::Image || $attachment->kind === MediaKind::Video)
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @throws ValidationException
	 */
	private function validateDetails(string $title, string $description, Visibility $audience): void
	{
		$fields = [];
		if ($title === '' || mb_strlen($title) > MediaCollection::MAX_TITLE_LENGTH) {
			$fields['title'] = 'A collection needs a title of at most ' . MediaCollection::MAX_TITLE_LENGTH . ' characters.';
		}

		if (mb_strlen($description) > MediaCollection::MAX_DESCRIPTION_LENGTH) {
			$fields['description'] = 'The description is too long.';
		}

		if ($audience === Visibility::Direct) {
			$fields['audience'] = 'A collection cannot be direct.';
		}

		if ($fields !== []) {
			throw new ValidationException($fields);
		}
	}
}
