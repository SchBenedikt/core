<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccountStatus;
use AlohaSocial\Core\Domain\Atproto\AtprotoEvent;
use AlohaSocial\Core\Domain\Atproto\AtprotoEventKind;
use AlohaSocial\Core\Domain\Atproto\AtprotoIdentifiers;
use AlohaSocial\Core\Domain\Atproto\AtprotoRecordOperation;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AtprotoEventTest extends TestCase
{
	public function testIdentifiersBuildAndValidateTheCanonicalUri(): void
	{
		self::assertTrue(AtprotoIdentifiers::isDid('did:plc:alice'));
		self::assertTrue(AtprotoIdentifiers::isHandle('alice.test'));
		self::assertTrue(AtprotoIdentifiers::isCollection('app.bsky.feed.post'));
		self::assertTrue(AtprotoIdentifiers::isRkey('3kabc'));
		self::assertSame(
			'at://did:plc:alice/app.bsky.feed.post/3kabc',
			AtprotoIdentifiers::uri('did:plc:alice', 'app.bsky.feed.post', '3kabc'),
		);
		self::assertFalse(AtprotoIdentifiers::isDid('not-a-did'));
		self::assertFalse(AtprotoIdentifiers::isHandle('alice'));
		self::assertFalse(AtprotoIdentifiers::isCollection('../post'));
		self::assertFalse(AtprotoIdentifiers::isRkey('a/b'));
	}

	public function testARecordEventNeedsAValidOperationCollectionAndCid(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new AtprotoEvent(
			'relay',
			AtprotoEventKind::Record,
			'did:plc:alice',
			new DateTimeImmutable('2026-11-15T10:00:00Z'),
			operation: AtprotoRecordOperation::Create,
			collection: 'app.bsky.feed.post',
			rkey: 'a',
			record: ['text' => 'missing cid'],
		);
	}

	public function testADeleteEventCannotCarryARecordBody(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new AtprotoEvent(
			'relay',
			AtprotoEventKind::Record,
			'did:plc:alice',
			new DateTimeImmutable('2026-11-15T10:00:00Z'),
			operation: AtprotoRecordOperation::Delete,
			collection: 'app.bsky.feed.post',
			rkey: 'a',
			record: ['text' => 'must not be here'],
		);
	}

	public function testAccountEventsCannotMixInRepositoryFields(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new AtprotoEvent(
			'relay',
			AtprotoEventKind::Account,
			'did:plc:alice',
			new DateTimeImmutable('2026-11-15T10:00:00Z'),
			rkey: 'a',
			active: true,
		);
	}

	public function testAnInactiveAccountCannotClaimAnAvailableStatus(): void
	{
		$this->expectException(InvalidArgumentException::class);

		new AtprotoEvent(
			'relay',
			AtprotoEventKind::Account,
			'did:plc:alice',
			new DateTimeImmutable('2026-11-15T10:00:00Z'),
			active: false,
			accountStatus: AtprotoAccountStatus::Active,
		);
	}

	public function testOnlyAnActiveAccountIsAvailable(): void
	{
		$active = new AtprotoEvent(
			'relay',
			AtprotoEventKind::Account,
			'did:plc:alice',
			new DateTimeImmutable('2026-11-15T10:00:00Z'),
			active: true,
			accountStatus: AtprotoAccountStatus::Active,
			handle: 'alice.test',
		);
		$suspended = new AtprotoEvent(
			'relay',
			AtprotoEventKind::Account,
			'did:plc:alice',
			new DateTimeImmutable('2026-11-15T10:00:00Z'),
			active: false,
			accountStatus: AtprotoAccountStatus::Suspended,
		);

		self::assertTrue($active->accountIsAvailable());
		self::assertFalse($suspended->accountIsAvailable());
	}
}
