<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Cleanup;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;

/**
 * The bounded options of one cleanup run.
 *
 * Retention is measured in whole days and defaults are deliberately short:
 * media is an orphan upload, notifications are read events and thread nodes
 * are a cache. Only read notifications are eligible, so an unread event is
 * never removed by age alone.
 */
final readonly class CleanupOptions
{
	public const KIND_MEDIA = 'media';

	public const KIND_NOTIFICATIONS = 'notifications';

	public const KIND_THREADS = 'threads';

	public const KIND_ALL = 'all';

	public const KINDS = [
		self::KIND_MEDIA,
		self::KIND_NOTIFICATIONS,
		self::KIND_THREADS,
		self::KIND_ALL,
	];

	public const DEFAULT_MEDIA_RETENTION_DAYS = 7;

	public const DEFAULT_NOTIFICATION_RETENTION_DAYS = 30;

	public const DEFAULT_THREAD_RETENTION_DAYS = 7;

	public const DEFAULT_LIMIT = 100;

	public const MAX_LIMIT = 1000;

	public const MAX_RETENTION_DAYS = 3650;

	public function __construct(
		public string $kind = self::KIND_ALL,
		public int $mediaRetentionDays = self::DEFAULT_MEDIA_RETENTION_DAYS,
		public int $notificationRetentionDays = self::DEFAULT_NOTIFICATION_RETENTION_DAYS,
		public int $threadRetentionDays = self::DEFAULT_THREAD_RETENTION_DAYS,
		public int $limit = self::DEFAULT_LIMIT,
		public bool $dryRun = false,
	) {
		if (!in_array($kind, self::KINDS, true)) {
			throw new InvalidCleanupOptionsException(
				'cleanup_invalid_kind',
				'kind must be one of media, notifications, threads or all.',
			);
		}

		foreach ([
			'media_retention_days' => $mediaRetentionDays,
			'notification_retention_days' => $notificationRetentionDays,
			'thread_retention_days' => $threadRetentionDays,
		] as $field => $days) {
			if ($days < 1 || $days > self::MAX_RETENTION_DAYS) {
				throw new InvalidCleanupOptionsException(
					'cleanup_invalid_retention',
					$field . ' must be between 1 and ' . self::MAX_RETENTION_DAYS . '.',
				);
			}
		}

		if ($limit < 1 || $limit > self::MAX_LIMIT) {
			throw new InvalidCleanupOptionsException(
				'cleanup_invalid_limit',
				'limit must be between 1 and ' . self::MAX_LIMIT . '.',
			);
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 *
	 * @throws InvalidCleanupOptionsException
	 */
	public static function fromArray(array $payload): self
	{
		$known = [
			'kind',
			'media_retention_days',
			'notification_retention_days',
			'thread_retention_days',
			'limit',
			'dry_run',
		];
		foreach ($payload as $key => $_value) {
			if (!in_array((string) $key, $known, true)) {
				throw new InvalidCleanupOptionsException(
					'cleanup_unknown_parameter',
					'Unknown cleanup parameter: ' . (string) $key . '.',
				);
			}
		}

		$kind = $payload['kind'] ?? self::KIND_ALL;
		if (!is_string($kind)) {
			throw new InvalidCleanupOptionsException(
				'cleanup_invalid_kind',
				'kind must be a string.',
			);
		}

		$dryRun = $payload['dry_run'] ?? false;
		if (!is_bool($dryRun)) {
			throw new InvalidCleanupOptionsException(
				'cleanup_invalid_dry_run',
				'dry_run must be a boolean.',
			);
		}

		return new self(
			kind: $kind,
			mediaRetentionDays: self::integer($payload, 'media_retention_days', self::DEFAULT_MEDIA_RETENTION_DAYS, 'cleanup_invalid_retention'),
			notificationRetentionDays: self::integer($payload, 'notification_retention_days', self::DEFAULT_NOTIFICATION_RETENTION_DAYS, 'cleanup_invalid_retention'),
			threadRetentionDays: self::integer($payload, 'thread_retention_days', self::DEFAULT_THREAD_RETENTION_DAYS, 'cleanup_invalid_retention'),
			limit: self::integer($payload, 'limit', self::DEFAULT_LIMIT, 'cleanup_invalid_limit'),
			dryRun: $dryRun,
		);
	}

	/**
	 * @return array{kind: string, media_retention_days: int, notification_retention_days: int, thread_retention_days: int, limit: int, dry_run: bool}
	 */
	public function toArray(): array
	{
		return [
			'kind' => $this->kind,
			'media_retention_days' => $this->mediaRetentionDays,
			'notification_retention_days' => $this->notificationRetentionDays,
			'thread_retention_days' => $this->threadRetentionDays,
			'limit' => $this->limit,
			'dry_run' => $this->dryRun,
		];
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function integer(array $payload, string $field, int $default, string $errorCode): int
	{
		if (!array_key_exists($field, $payload)) {
			return $default;
		}

		$value = $payload[$field];
		if (!is_int($value)) {
			throw new InvalidCleanupOptionsException(
				$errorCode,
				$field . ' must be an integer.',
			);
		}

		return $value;
	}
}
