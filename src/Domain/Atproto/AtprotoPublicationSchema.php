<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Atproto;

use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\SchemaUpgrade;
use RuntimeException;

/**
 * The canonical schema of the AT Protocol publication table.
 *
 * A local post may be mirrored to multiple AT Protocol identities. The DID is
 * part of the idempotency key and uniquely owns each remote record key.
 */
final class AtprotoPublicationSchema
{
	public const TABLE = 'social_atproto_publication';

	/**
	 * @return list<string>
	 */
	public static function statements(): array
	{
		return [
			'CREATE TABLE ' . self::TABLE . ' ('
			. ' local_user_id VARCHAR(255) NOT NULL,'
			. ' account_did VARCHAR(512) NOT NULL,'
			. ' post_id VARCHAR(64) NOT NULL,'
			. ' rkey VARCHAR(13) NOT NULL,'
			. ' uri VARCHAR(512) NOT NULL,'
			. ' cid VARCHAR(256) NULL,'
			. ' pds_url VARCHAR(512) NULL,'
			. ' published_at VARCHAR(20) NOT NULL,'
			. ' PRIMARY KEY (local_user_id, account_did, post_id)'
			. ')',
			'CREATE UNIQUE INDEX ' . self::TABLE . '_record_key ON ' . self::TABLE
			. ' (account_did, rkey)',
			'CREATE INDEX ' . self::TABLE . '_published ON ' . self::TABLE
			. ' (published_at)',
		];
	}

	public static function create(Connection $connection): void
	{
		foreach (self::statements() as $statement) {
			$connection->execute($statement);
		}
	}

	/** Upgrades the publication mapping while preserving each existing remote URI. */
	public static function upgrade(Connection $connection): void
	{
		if (!SchemaUpgrade::tableExists($connection, self::TABLE)) {
			self::create($connection);

			return;
		}

		SchemaUpgrade::addColumnIfMissing($connection, self::TABLE, 'cid', 'VARCHAR(256) NULL');
		if (SchemaUpgrade::columnExists($connection, self::TABLE, 'account_did')) {
			$connection->execute('DROP TABLE IF EXISTS ' . self::TABLE . '_identity_new');
			$connection->execute('DROP TABLE IF EXISTS ' . self::TABLE . '_identity_old');
			return;
		}
		$rebuild = static function () use ($connection): void {
			self::rebuildForIdentity($connection);
		};
		if (in_array($connection->driver(), ['sqlite', 'pgsql'], true)) {
			$connection->transaction($rebuild);
		} else {
			$rebuild();
		}
	}

	private static function rebuildForIdentity(Connection $connection): void
	{
		$temp = self::TABLE . '_identity_new';
		$backup = self::TABLE . '_identity_old';
		$connection->execute('DROP TABLE IF EXISTS ' . $temp);
		$connection->execute(str_replace(self::TABLE, $temp, self::statements()[0]));
		$rows = $connection->fetchAll(
			'SELECT local_user_id, post_id, rkey, uri, cid, pds_url, published_at FROM ' . self::TABLE,
		);
		foreach ($rows as $row) {
			$uri = (string) $row['uri'];
			if (preg_match('~^at://(did:[a-z0-9]+:[A-Za-z0-9._:%-]+)/app\\.bsky\\.feed\\.post/[^/]+$~', $uri, $match) !== 1) {
				throw new RuntimeException('An existing AT Protocol publication has no usable account DID.');
			}
			$connection->execute(
				'INSERT INTO ' . $temp
				. ' (local_user_id, account_did, post_id, rkey, uri, cid, pds_url, published_at)'
				. ' VALUES (:user, :did, :post, :rkey, :uri, :cid, :pds, :published)',
				[
					'user' => (string) $row['local_user_id'], 'did' => $match[1], 'post' => (string) $row['post_id'],
					'rkey' => (string) $row['rkey'], 'uri' => $uri, 'cid' => $row['cid'],
					'pds' => $row['pds_url'], 'published' => (string) $row['published_at'],
				],
			);
		}

		if ($connection->driver() === 'mysql') {
			$connection->execute('DROP TABLE IF EXISTS ' . $backup);
			$connection->execute('RENAME TABLE ' . self::TABLE . ' TO ' . $backup . ', ' . $temp . ' TO ' . self::TABLE);
			$connection->execute('DROP TABLE ' . $backup);
		} elseif ($connection->driver() === 'sqlsrv') {
			$connection->execute('DROP TABLE ' . self::TABLE);
			$connection->execute("EXEC sp_rename '" . $temp . "', '" . self::TABLE . "'");
		} else {
			$connection->execute('DROP TABLE ' . self::TABLE);
			$connection->execute('ALTER TABLE ' . $temp . ' RENAME TO ' . self::TABLE);
		}
		$connection->execute('CREATE UNIQUE INDEX ' . self::TABLE . '_record_key ON ' . self::TABLE . ' (account_did, rkey)');
		SchemaUpgrade::createIndexIfMissing($connection, self::TABLE, self::TABLE . '_published', 'published_at');
	}
}
