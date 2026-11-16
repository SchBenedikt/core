<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Http;

use AlohaSocial\Core\Domain\Account\AccountDeletionSweeper;
use AlohaSocial\Core\Domain\Account\AccountWriteGuard;
use AlohaSocial\Core\Http\Handler;
use AlohaSocial\Core\Http\HttpException;
use AlohaSocial\Core\Http\Middleware\RejectDeletedAccountWrites;
use AlohaSocial\Core\Http\Request;
use AlohaSocial\Core\Http\Response;
use PHPUnit\Framework\TestCase;

final class RejectDeletedAccountWritesTest extends TestCase
{
	public function testAFrozenAccountIsRejectedBeforeTheHandlerRuns(): void
	{
		$guard = new class implements AccountWriteGuard {
			public function accountWriteStatus(string $userId): ?int
			{
				return 403;
			}
		};
		$called = false;
		$middleware = new RejectDeletedAccountWrites($guard);

		try {
			$middleware->process(
				new Request('POST', '/api/v1/posts', userId: 'alice'),
				static function () use (&$called): Response {
					$called = true;

					return Response::json([]);
				},
			);
			self::fail('Expected the frozen account to be rejected.');
		} catch (HttpException $exception) {
			self::assertSame(403, $exception->status);
			self::assertSame('account_deletion_in_progress', $exception->errorCode);
		}

		self::assertFalse($called);
	}

	public function testALateWriteIsSweptWhenTheAccountFreezesWhileTheHandlerRuns(): void
	{
		$guard = new class implements AccountWriteGuard {
			public ?int $status = null;

			public function accountWriteStatus(string $userId): ?int
			{
				return $this->status;
			}
		};
		$sweeper = new class implements AccountDeletionSweeper {
			/** @var list<string> */
			public array $swept = [];

			public function sweep(string $userId): void
			{
				$this->swept[] = $userId;
			}
		};
		$middleware = new RejectDeletedAccountWrites($guard, $sweeper);

		$response = $middleware->process(
			new Request('POST', '/api/v1/posts', userId: 'alice'),
			static function () use ($guard): Response {
				$guard->status = 410;

				return Response::json(['ok' => true], 201);
			},
		);

		self::assertSame(201, $response->status);
		self::assertSame(['alice'], $sweeper->swept);
	}

	public function testAReadIsNeverBlocked(): void
	{
		$guard = new class implements AccountWriteGuard {
			public function accountWriteStatus(string $userId): ?int
			{
				return 410;
			}
		};
		$middleware = new RejectDeletedAccountWrites($guard);

		$response = $middleware->process(
			new Request('GET', '/api/v1/account/deletion', userId: 'alice'),
			static fn (): Response => Response::json(['state' => 'erased']),
		);

		self::assertSame(200, $response->status);
	}
}
