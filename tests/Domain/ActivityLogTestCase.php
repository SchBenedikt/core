<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Federation\ActivityLog;
use AlohaSocial\Core\Domain\Federation\InMemoryActivityLog;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

/**
 * The replay behaviour every ActivityLog implementation has to share.
 */
abstract class ActivityLogTestCase extends TestCase
{
	abstract protected function log(): ActivityLog;

	public function testTheSameActivityIsOnlyClaimedOnce(): void
	{
		$log = $this->log();
		$now = Time::parse('2026-11-11T10:00:00Z');

		self::assertTrue($log->claim('activity-1', $now));
		self::assertFalse($log->claim('activity-1', $now->modify('+1 second')));
		self::assertTrue($log->claim('activity-2', $now));
	}

	public function testAReleasedClaimCanBeTakenAgain(): void
	{
		$log = $this->log();
		$now = Time::parse('2026-11-11T10:00:00Z');

		self::assertTrue($log->claim('activity-1', $now));
		$log->release('activity-1');

		self::assertTrue($log->claim('activity-1', $now));
	}

	public function testAnExpiredClaimDoesNotBlockForever(): void
	{
		$log = $this->log();
		$now = Time::parse('2026-11-11T10:00:00Z');

		self::assertTrue($log->claim('activity-1', $now));
		self::assertTrue(
			$log->claim(
				'activity-1',
				$now->modify('+' . (InMemoryActivityLog::RETENTION_SECONDS + 1) . ' seconds'),
			),
		);
	}
}
