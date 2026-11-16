<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Api;

use AlohaSocial\Core\Application;
use AlohaSocial\Core\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * The routes are registered in the normal kernel, require authentication and
 * answer honestly when the host did not supply the SQL account adapter.
 */
final class AccountDataApiTest extends TestCase
{
	public function testExportRequiresAuthenticationAndTheHostAdapter(): void
	{
		$app = Application::inMemory();

		$anonymous = $app->handle(new Request(method: 'GET', path: '/api/v1/account/export'));
		self::assertSame(401, $anonymous->status);
		self::assertSame('authentication_required', $anonymous->decodedBody()['error']['code']);

		$unavailable = $app->handle(new Request(
			method: 'GET',
			path: '/api/v1/account/export',
			userId: 'alice',
		));
		self::assertSame(503, $unavailable->status);
		self::assertSame('account_data_unavailable', $unavailable->decodedBody()['error']['code']);
	}

	public function testDeletionStatusRequiresAuthenticationAndTheHostAdapter(): void
	{
		$app = Application::inMemory();

		$anonymous = $app->handle(new Request(method: 'GET', path: '/api/v1/account/deletion'));
		self::assertSame(401, $anonymous->status);

		$unavailable = $app->handle(new Request(
			method: 'GET',
			path: '/api/v1/account/deletion',
			userId: 'alice',
		));
		self::assertSame(503, $unavailable->status);
		self::assertSame('account_data_unavailable', $unavailable->decodedBody()['error']['code']);
	}

	public function testErasureRequiresAuthenticationAndTheHostAdapter(): void
	{
		$app = Application::inMemory();

		$anonymous = $app->handle(new Request(method: 'DELETE', path: '/api/v1/account/data'));
		self::assertSame(401, $anonymous->status);

		$unavailable = $app->handle(new Request(
			method: 'DELETE',
			path: '/api/v1/account/data',
			userId: 'alice',
		));
		self::assertSame(503, $unavailable->status);
		self::assertSame('account_data_unavailable', $unavailable->decodedBody()['error']['code']);
	}
}
