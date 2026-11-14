<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Media;

use DateTimeImmutable;

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
	 * Updates only the description. Unknown ids are a no-op; the caller has
	 * already loaded the row and validated ownership.
	 */
	public function updateAltText(string $id, ?string $altText): void;

	/**
	 * Updates only the focal point. Both coordinates travel together; null
	 * clears it. Unknown ids are a no-op.
	 */
	public function updateFocus(string $id, ?float $focusX, ?float $focusY): void;

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
	 * Unattached rows older than the retention boundary, oldest first with the
	 * id as the tie-break. A soft-deleted post still links its attachments, so
	 * its rows are not candidates.
	 *
	 * @return list<Attachment>
	 */
	public function unattachedBefore(DateTimeImmutable $before, int $limit): array;

	public function countUnattachedBefore(DateTimeImmutable $before): int;

	/**
	 * Deletes only if no post links the row at the time of the delete.
	 *
	 * @return bool whether the row was removed
	 */
	public function deleteIfUnattached(string $id): bool;

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
