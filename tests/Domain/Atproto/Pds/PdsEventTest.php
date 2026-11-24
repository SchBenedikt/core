<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain\Atproto\Pds;

use AlohaSocial\Core\Domain\Atproto\Pds\PdsEvent;
use PHPUnit\Framework\TestCase;

final class PdsEventTest extends TestCase
{
	public function testAccountDeletionEventIsAProtocolAccountStatusUpdate(): void
	{
		$event = PdsEvent::accountDeleted(4, 'did:plc:alice', '2026-11-24T14:00:00Z');

		self::assertSame([
			'$type' => '#account',
			'seq' => 4,
			'did' => 'did:plc:alice',
			'time' => '2026-11-24T14:00:00Z',
			'active' => false,
			'status' => 'deleted',
		], $event->toArray());
		self::assertSame($event->toArray(), $event->withSeq(4)->toArray());
	}
}
