<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Media;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use PDOException;

/**
 * Attachment metadata in SQL.
 *
 * The table has no foreign key to a post: an upload is independent of the
 * post it may later be attached to. That is what lets a user remove a draft
 * without deleting the file, and lets cleanup find rows that were never used.
 */
final class PdoAttachmentRepository implements AttachmentRepository
{
	private const COLUMNS = 'id, owner_id, storage_key, thumbnail_key, mime_type, width, height, byte_size, alt_text, created_at';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function insert(Attachment $attachment): void
	{
		try {
			$this->insertRow($attachment);
		} catch (PDOException $exception) {
			throw new DatabaseException('The attachment row could not be stored.', 0, $exception);
		}
	}

	private function insertRow(Attachment $attachment): void
	{
		$this->db->execute(
			'INSERT INTO ' . AttachmentSchema::TABLE
			. ' (' . self::COLUMNS . ')'
			. ' VALUES (:id, :owner_id, :storage_key, :thumbnail_key, :mime_type,'
			. ' :width, :height, :byte_size, :alt_text, :created_at)',
			[
				'id' => $attachment->id,
				'owner_id' => $attachment->ownerId,
				'storage_key' => $attachment->storageKey,
				'thumbnail_key' => $attachment->thumbnailKey,
				'mime_type' => $attachment->mimeType,
				'width' => $attachment->width,
				'height' => $attachment->height,
				'byte_size' => $attachment->byteSize,
				'alt_text' => $attachment->altText,
				'created_at' => Time::format($attachment->createdAt),
			],
		);
	}

	public function find(string $id): ?Attachment
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . AttachmentSchema::TABLE . ' WHERE id = :id',
			['id' => $id],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function count(): int
	{
		return (int) $this->db->fetchValue('SELECT COUNT(*) FROM ' . AttachmentSchema::TABLE);
	}

	public function findMany(array $ids): array
	{
		if ($ids === []) {
			return [];
		}

		$placeholders = [];
		$parameters = [];
		foreach (array_values($ids) as $index => $id) {
			$name = 'id_' . $index;
			$placeholders[] = ':' . $name;
			$parameters[$name] = $id;
		}

		$rows = $this->db->fetchAll(
			'SELECT ' . self::COLUMNS . ' FROM ' . AttachmentSchema::TABLE
			. ' WHERE id IN (' . implode(', ', $placeholders) . ')',
			$parameters,
		);

		$found = [];
		foreach ($rows as $row) {
			$attachment = $this->hydrate($row);
			$found[$attachment->id] = $attachment;
		}

		return $found;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): Attachment
	{
		return new Attachment(
			id: (string) $row['id'],
			ownerId: (string) $row['owner_id'],
			storageKey: (string) $row['storage_key'],
			thumbnailKey: (string) $row['thumbnail_key'],
			mimeType: (string) $row['mime_type'],
			width: (int) $row['width'],
			height: (int) $row['height'],
			byteSize: (int) $row['byte_size'],
			altText: $row['alt_text'] === null ? null : (string) $row['alt_text'],
			createdAt: Time::parse((string) $row['created_at']),
		);
	}
}
