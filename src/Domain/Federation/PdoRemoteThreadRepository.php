<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use Throwable;

/**
 * SQL storage for objects fetched into a remote thread.
 *
 * The table is a cache, not the delivered remote-post store. `object_id` is the
 * primary key, so a repeated context request refreshes one row instead of
 * appending duplicates. Upserting avoids vendor-specific ON CONFLICT syntax;
 * the conditional update and insert path works on SQLite, PostgreSQL and MySQL.
 */
final class PdoRemoteThreadRepository implements RemoteThreadRepository
{
	private const COLUMNS = 'object_id, author_id, content, content_warning,'
		. ' published_at, in_reply_to_id, fetched_at';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function upsert(RemoteThreadPost $post): void
	{
		$parameters = $this->parameters($post);

		if ($this->find($post->objectId) !== null) {
			$this->update($post->objectId, $parameters);

			return;
		}

		try {
			$this->db->execute(
				'INSERT INTO ' . RemoteThreadSchema::TABLE
				. ' (' . self::COLUMNS . ')'
				. ' VALUES (:object_id, :author_id, :content, :content_warning,'
				. ' :published_at, :in_reply_to_id, :fetched_at)',
				['object_id' => $post->objectId, ...$parameters],
			);
		} catch (Throwable $exception) {
			// A concurrent request may have inserted the same object between
			// the lookup and this insert. Refresh that row instead of
			// reporting a duplicate context node.
			if ($this->find($post->objectId) === null) {
				if ($exception instanceof DatabaseException) {
					throw $exception;
				}

				throw new DatabaseException('The remote thread node could not be stored.', 0, $exception);
			}

			$this->update($post->objectId, $parameters);
		}
	}

	public function find(string $objectId): ?RemoteThreadPost
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . RemoteThreadSchema::TABLE
			. ' WHERE object_id = :object_id',
			['object_id' => $objectId],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function children(string $parentObjectId, int $limit): array
	{
		if ($limit <= 0) {
			return [];
		}

		$rows = $this->db->fetchAll(
			'SELECT ' . self::COLUMNS . ' FROM ' . RemoteThreadSchema::TABLE
			. ' WHERE in_reply_to_id = :parent_object_id'
			. ' ORDER BY published_at ASC, object_id ASC LIMIT ' . $limit,
			['parent_object_id' => $parentObjectId],
		);

		return array_map(
			fn (array $row): RemoteThreadPost => $this->hydrate($row),
			$rows,
		);
	}

	public function deleteFetchedBefore(DateTimeImmutable $before): int
	{
		try {
			return $this->db->execute(
				'DELETE FROM ' . RemoteThreadSchema::TABLE
				. ' WHERE fetched_at < :before',
				['before' => Time::format($before)],
			);
		} catch (Throwable $exception) {
			if ($exception instanceof DatabaseException) {
				throw $exception;
			}

			throw new DatabaseException('Remote thread context could not be expired.', 0, $exception);
		}
	}

	/**
	 * @param array<string, mixed> $parameters
	 */
	private function update(string $objectId, array $parameters): void
	{
		try {
			$this->db->execute(
				'UPDATE ' . RemoteThreadSchema::TABLE
				. ' SET author_id = :author_id, content = :content,'
				. ' content_warning = :content_warning, published_at = :published_at,'
				. ' in_reply_to_id = :in_reply_to_id, fetched_at = :fetched_at'
				. ' WHERE object_id = :object_id',
				['object_id' => $objectId, ...$parameters],
			);
		} catch (Throwable $exception) {
			if ($exception instanceof DatabaseException) {
				throw $exception;
			}

			throw new DatabaseException('The remote thread context could not be updated.', 0, $exception);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function parameters(RemoteThreadPost $post): array
	{
		return [
			'author_id' => $post->authorId,
			'content' => $post->content,
			'content_warning' => $post->contentWarning,
			'published_at' => Time::format($post->publishedAt),
			'in_reply_to_id' => $post->inReplyToId,
			'fetched_at' => Time::format($post->fetchedAt),
		];
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): RemoteThreadPost
	{
		return new RemoteThreadPost(
			objectId: (string) $row['object_id'],
			authorId: (string) $row['author_id'],
			content: (string) $row['content'],
			publishedAt: Time::parse((string) $row['published_at']),
			fetchedAt: Time::parse((string) $row['fetched_at']),
			contentWarning: $row['content_warning'] === null ? null : (string) $row['content_warning'],
			inReplyToId: $row['in_reply_to_id'] === null ? null : (string) $row['in_reply_to_id'],
		);
	}
}
