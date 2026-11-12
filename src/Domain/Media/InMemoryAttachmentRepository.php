<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Media;

use AlohaSocial\Core\Platform\Db\DatabaseException;

/**
 * Attachment metadata in memory, for tests and the reference assembly.
 */
final class InMemoryAttachmentRepository implements AttachmentRepository
{
	/**
	 * @var array<string, Attachment>
	 */
	private array $attachments = [];

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
}
