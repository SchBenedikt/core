<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Post;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use JsonException;
use Throwable;

use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Revision storage in SQL.
 *
 * Attachment ids are stored as a JSON list in one text column on purpose: the
 * snapshot is read as a whole and never joined, so a side table would add a
 * migration without adding a query. Text keeps the format identical on
 * SQLite, PostgreSQL and MySQL.
 */
final class PdoPostRevisionRepository implements PostRevisionRepository
{
	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function add(PostRevision $revision): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . PostSchema::REVISION_TABLE
				. ' (post_id, revision, content, content_warning, visibility, language, attachment_ids, edited_at)'
				. ' VALUES (:post_id, :revision, :content, :content_warning, :visibility, :language, :attachment_ids, :edited_at)',
				[
					'post_id' => $revision->postId,
					'revision' => $revision->revision,
					'content' => $revision->content,
					'content_warning' => $revision->contentWarning,
					'visibility' => $revision->visibility->value,
					'language' => $revision->language,
					'attachment_ids' => json_encode($revision->attachmentIds, JSON_THROW_ON_ERROR),
					'edited_at' => Time::format($revision->editedAt),
				],
			);
		} catch (Throwable $exception) {
			if ($exception instanceof DatabaseException) {
				throw $exception;
			}

			throw new DatabaseException('The post revision could not be stored.', 0, $exception);
		}
	}

	public function forPost(string $postId): array
	{
		$rows = $this->db->fetchAll(
			'SELECT post_id, revision, content, content_warning, visibility, language, attachment_ids, edited_at'
			. ' FROM ' . PostSchema::REVISION_TABLE
			. ' WHERE post_id = :post_id ORDER BY revision ASC',
			['post_id' => $postId],
		);

		return array_map(fn (array $row): PostRevision => $this->hydrate($row), $rows);
	}

	public function countForPost(string $postId): int
	{
		return (int) $this->db->fetchValue(
			'SELECT COUNT(*) FROM ' . PostSchema::REVISION_TABLE . ' WHERE post_id = :post_id',
			['post_id' => $postId],
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): PostRevision
	{
		return new PostRevision(
			postId: (string) $row['post_id'],
			revision: (int) $row['revision'],
			content: (string) $row['content'],
			contentWarning: $row['content_warning'] === null ? null : (string) $row['content_warning'],
			visibility: Visibility::from((string) $row['visibility']),
			attachmentIds: $this->attachmentIds((string) $row['attachment_ids']),
			editedAt: Time::parse((string) $row['edited_at']),
			language: ($row['language'] ?? null) === null ? null : (string) $row['language'],
		);
	}

	/**
	 * A snapshot whose attachment list cannot be read is still a valid
	 * revision of the text; the media reference is dropped rather than
	 * failing the whole history read.
	 *
	 * @return list<string>
	 */
	private function attachmentIds(string $stored): array
	{
		try {
			$decoded = json_decode($stored, true, 16, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			return [];
		}

		if (!is_array($decoded)) {
			return [];
		}

		$ids = [];
		foreach ($decoded as $id) {
			if (is_string($id) && $id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}
}
