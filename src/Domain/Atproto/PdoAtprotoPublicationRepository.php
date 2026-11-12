<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\DatabaseException;
use AlohaSocial\Core\Support\Time;
use Throwable;

/**
 * AT Protocol publication ids in SQL.
 */
final class PdoAtprotoPublicationRepository implements AtprotoPublicationRepository
{
	private const COLUMNS = 'local_user_id, post_id, rkey, uri, published_at';

	public function __construct(
		private readonly Connection $db,
	) {
	}

	public function findForPost(string $localUserId, string $postId): ?AtprotoPublication
	{
		$row = $this->db->fetchOne(
			'SELECT ' . self::COLUMNS . ' FROM ' . AtprotoPublicationSchema::TABLE
			. ' WHERE local_user_id = :local_user_id AND post_id = :post_id',
			['local_user_id' => $localUserId, 'post_id' => $postId],
		);

		return $row === null ? null : $this->hydrate($row);
	}

	public function insert(AtprotoPublication $publication): void
	{
		try {
			$this->db->execute(
				'INSERT INTO ' . AtprotoPublicationSchema::TABLE
				. ' (local_user_id, post_id, rkey, uri, published_at)'
				. ' VALUES (:local_user_id, :post_id, :rkey, :uri, :published_at)',
				[
					'local_user_id' => $publication->localUserId,
					'post_id' => $publication->postId,
					'rkey' => $publication->rkey,
					'uri' => $publication->uri,
					'published_at' => Time::format($publication->publishedAt),
				],
			);
		} catch (Throwable $exception) {
			if ($exception instanceof DatabaseException) {
				throw $exception;
			}

			throw new DatabaseException('The AT Protocol publication could not be stored.', 0, $exception);
		}
	}

	public function removeForPost(string $localUserId, string $postId): bool
	{
		return $this->db->execute(
			'DELETE FROM ' . AtprotoPublicationSchema::TABLE
			. ' WHERE local_user_id = :local_user_id AND post_id = :post_id',
			['local_user_id' => $localUserId, 'post_id' => $postId],
		) > 0;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function hydrate(array $row): AtprotoPublication
	{
		return new AtprotoPublication(
			localUserId: (string) $row['local_user_id'],
			postId: (string) $row['post_id'],
			rkey: (string) $row['rkey'],
			uri: (string) $row['uri'],
			publishedAt: Time::parse((string) $row['published_at']),
		);
	}
}
