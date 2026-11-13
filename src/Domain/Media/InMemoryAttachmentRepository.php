<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Media;

use AlohaSocial\Core\Domain\Federation\RemotePostRepository;
use AlohaSocial\Core\Domain\Post\PostRepository;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use DateTimeImmutable;

use function array_filter;
use function array_slice;
use function array_values;
use function strcmp;
use function usort;

/**
 * Attachment metadata in memory, for tests and the reference assembly.
 *
 * The optional post repository supplies the same linked-row rule as SQL. A
 * cleanup cannot remove media that a live or soft-deleted post still uses.
 */
final class InMemoryAttachmentRepository implements AttachmentRepository
{
	/**
	 * @var array<string, Attachment>
	 */
	private array $attachments = [];

	public function __construct(
		private readonly ?PostRepository $posts = null,
		private readonly ?RemotePostRepository $remotePosts = null,
	) {
	}

	public function insert(Attachment $attachment): void
	{
		if (isset($this->attachments[$attachment->id])) {
			throw new DatabaseException('The attachment id already exists.');
		}

		if ($attachment->sourceUrl !== null && $this->findBySourceUrl($attachment->sourceUrl) !== null) {
			throw new DatabaseException('The attachment source URL already exists.');
		}

		$this->attachments[$attachment->id] = $attachment;
	}

	public function find(string $id): ?Attachment
	{
		return $this->attachments[$id] ?? null;
	}

	public function updateAltText(string $id, ?string $altText): void
	{
		$attachment = $this->attachments[$id] ?? null;
		if ($attachment === null) {
			return;
		}

		$this->attachments[$id] = $attachment->withAltText($altText);
	}

	public function findBySourceUrl(string $sourceUrl): ?Attachment
	{
		foreach ($this->attachments as $attachment) {
			if ($attachment->sourceUrl === $sourceUrl) {
				return $attachment;
			}
		}

		return null;
	}

	public function delete(string $id): void
	{
		unset($this->attachments[$id]);
	}

	public function count(): int
	{
		return count($this->attachments);
	}

	public function unattachedBefore(DateTimeImmutable $before, int $limit): array
	{
		$candidates = array_values(array_filter(
			$this->attachments,
			fn (Attachment $attachment): bool => $attachment->createdAt < $before
				&& !$this->isLinked($attachment->id),
		));

		usort(
			$candidates,
			static fn (Attachment $left, Attachment $right): int => ($left->createdAt <=> $right->createdAt)
				?: strcmp($left->id, $right->id),
		);

		return array_slice($candidates, 0, max(0, $limit));
	}

	public function countUnattachedBefore(DateTimeImmutable $before): int
	{
		return count(array_filter(
			$this->attachments,
			fn (Attachment $attachment): bool => $attachment->createdAt < $before
				&& !$this->isLinked($attachment->id),
		));
	}

	public function deleteIfUnattached(string $id): bool
	{
		if (!isset($this->attachments[$id]) || $this->isLinked($id)) {
			return false;
		}

		unset($this->attachments[$id]);

		return true;
	}

	public function findMany(array $ids): array
	{
		$found = [];
		foreach ($ids as $id) {
			if (isset($this->attachments[$id])) {
				$found[$id] = $this->attachments[$id];
			}
		}

		return $found;
	}

	private function isLinked(string $id): bool
	{
		return ($this->posts?->isAttachmentLinked($id) ?? false)
			|| ($this->remotePosts?->isAttachmentLinked($id) ?? false);
	}
}
