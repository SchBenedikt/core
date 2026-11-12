<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\ActivityLog;
use AlohaSocial\Core\Domain\Federation\ActivityLogSchema;
use AlohaSocial\Core\Domain\Federation\PdoActivityLog;
use AlohaSocial\Core\Platform\Db\Connection;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Support\Time;
use PDO;

/**
 * The replay guard against a real SQLite file: a claim has to survive a new
 * connection, and a release has to be visible from that new connection too.
 */
final class PdoActivityLogTest extends ActivityLogTestCase
{
	private ActivityLog $log;

	private string $databasePath;

	protected function setUp(): void
	{
		$this->databasePath = sys_get_temp_dir() . '/alohasocial-activity-' . bin2hex(random_bytes(8)) . '.sqlite';

		$connection = $this->connection();
		ActivityLogSchema::create($connection);

		$this->log = new PdoActivityLog($connection);
	}

	protected function tearDown(): void
	{
		@unlink($this->databasePath);
	}

	public function testAReplayIsRefusedAcrossAFreshConnection(): void
	{
		$now = Time::parse('2026-11-11T10:00:00Z');

		self::assertTrue($this->log->claim('activity-1', $now));

		$fresh = new PdoActivityLog($this->connection());

		self::assertFalse($fresh->claim('activity-1', $now->modify('+1 second')));

		$fresh->release('activity-1');

		$afterRelease = new PdoActivityLog($this->connection());
		self::assertTrue($afterRelease->claim('activity-1', $now));
	}

	protected function log(): ActivityLog
	{
		return $this->log;
	}

	private function connection(): Connection
	{
		return new PdoConnection(new PDO('sqlite:' . $this->databasePath));
	}
}
