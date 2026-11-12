<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Media;

/**
 * Storage of uploaded attachment metadata.
 *
 * Bytes live in a {@see \AlohaSocial\Core\Platform\Storage\BlobStore}; this
 * repository only owns the row that makes them addressable and auditable.
 */
interface AttachmentRepository
{
	/**
	 * @throws \AlohaSocial\Core\Platform\Db\DatabaseException when the id is taken
	 */
	public function insert(Attachment $attachment): void;

	public function find(string $id): ?Attachment;

	/**
	 * Finds an attachment imported from a remote URL, so the same media is
	 * fetched and stored at most once.
	 */
	public function findBySourceUrl(string $sourceUrl): ?Attachment;

	/**
	 * Removes metadata for an attachment whose blobs are cleaned separately.
	 */
	public function delete(string $id): void;

	/**
	 * The number of stored rows, used by operational cleanup checks.
	 */
	public function count(): int;

	/**
	 * @param list<string> $ids
	 *
	 * @return array<string, Attachment> keyed by attachment id
	 */
	public function findMany(array $ids): array;
}
