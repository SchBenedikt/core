<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service\Cleanup;

use AlohaSocial\Core\Service\Cleanup\CleanupOptions;
use AlohaSocial\Core\Service\Cleanup\InvalidCleanupOptionsException;
use PHPUnit\Framework\TestCase;

/**
 * The cleanup job payload is host/cron input. Invalid values are rejected with
 * a stable reason instead of silently widening a deletion.
 */
final class CleanupOptionsTest extends TestCase
{
	public function testDefaultsAreBoundedAndDocumented(): void
	{
		$options = CleanupOptions::fromArray([]);

		self::assertSame(CleanupOptions::KIND_ALL, $options->kind);
		self::assertSame(7, $options->mediaRetentionDays);
		self::assertSame(30, $options->notificationRetentionDays);
		self::assertSame(7, $options->threadRetentionDays);
		self::assertSame(100, $options->limit);
		self::assertFalse($options->dryRun);
		self::assertSame([
			'kind' => 'all',
			'media_retention_days' => 7,
			'notification_retention_days' => 30,
			'thread_retention_days' => 7,
			'limit' => 100,
			'dry_run' => false,
		], $options->toArray());
	}

	public function testBoundaryValuesAreAccepted(): void
	{
		$options = CleanupOptions::fromArray([
			'kind' => 'media',
			'media_retention_days' => 1,
			'notification_retention_days' => 3650,
			'thread_retention_days' => 1,
			'limit' => 1000,
			'dry_run' => true,
		]);

		self::assertSame(1, $options->mediaRetentionDays);
		self::assertSame(3650, $options->notificationRetentionDays);
		self::assertSame(1000, $options->limit);
		self::assertTrue($options->dryRun);
	}

	public function testInvalidKindsRetentionsLimitsAndKeysUseStableCodes(): void
	{
		$cases = [
			[['kind' => 'posts'], 'cleanup_invalid_kind'],
			[['kind' => 1], 'cleanup_invalid_kind'],
			[['media_retention_days' => 0], 'cleanup_invalid_retention'],
			[['notification_retention_days' => 3651], 'cleanup_invalid_retention'],
			[['thread_retention_days' => '7'], 'cleanup_invalid_retention'],
			[['limit' => 0], 'cleanup_invalid_limit'],
			[['limit' => '100'], 'cleanup_invalid_limit'],
			[['dry_run' => 1], 'cleanup_invalid_dry_run'],
			[['unknown' => true], 'cleanup_unknown_parameter'],
		];

		foreach ($cases as [$payload, $expected]) {
			self::assertSame($expected, $this->errorCodeFor($payload));
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function errorCodeFor(array $payload): string
	{
		try {
			CleanupOptions::fromArray($payload);
		} catch (InvalidCleanupOptionsException $exception) {
			return $exception->errorCode;
		}

		self::fail('Expected cleanup options to be rejected.');
	}
}
