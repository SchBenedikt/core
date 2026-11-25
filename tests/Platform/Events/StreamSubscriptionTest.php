<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Platform\Events;

use AlohaSocial\Core\Platform\Events\StreamAudience;
use AlohaSocial\Core\Platform\Events\StreamEvent;
use AlohaSocial\Core\Platform\Events\StreamEventDraft;
use AlohaSocial\Core\Platform\Events\StreamSubscription;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class StreamSubscriptionTest extends TestCase
{
	public function testAcceptsAuthenticatedHomeAndNotificationStreams(): void
	{
		$subscription = new StreamSubscription('alice', ['home', 'user:notification']);

		self::assertSame('alice', $subscription->userId);
		self::assertSame(['home', 'user:notification'], $subscription->channels);
	}

	public function testRejectsUnauthenticatedOrUnsupportedSubscriptions(): void
	{
		$this->expectException(InvalidArgumentException::class);
		new StreamSubscription('', ['public']);
	}

	public function testRequiresListAndHashtagFiltersToBeExplicit(): void
	{
		try {
			new StreamSubscription('alice', ['list']);
			self::fail('A list stream without a list id must be rejected.');
		} catch (InvalidArgumentException) {
		}

		try {
			new StreamSubscription('alice', ['hashtag']);
			self::fail('A hashtag stream without filters must be rejected.');
		} catch (InvalidArgumentException) {
		}

		$list = new StreamSubscription('alice', ['list'], listId: 'friends');
		$tag = new StreamSubscription('alice', ['hashtag'], hashtags: ['cats']);
		self::assertSame('friends', $list->listId);
		self::assertSame(['cats'], $tag->hashtags);
	}

	public function testPrivateEventsRequireRecipients(): void
	{
		$this->expectException(InvalidArgumentException::class);
		new StreamEvent('cursor-1', 'notification', StreamAudience::Direct, 'alice', [], ['id' => 'n1'], '2026-11-25T00:00:00Z');
	}

	public function testDirectDraftRequiresAndCarriesRecipientIds(): void
	{
		$draft = new StreamEventDraft(
			'status.created',
			StreamAudience::Direct,
			'alice',
			['alice', 'bob'],
			['status_id' => 'post-1'],
			'2026-11-25T00:00:00Z',
		);

		self::assertSame(['alice', 'bob'], $draft->recipientIds);
	}

	public function testRejectsNonNormalizedHashtagFilters(): void
	{
		$this->expectException(InvalidArgumentException::class);
		new StreamSubscription('alice', ['hashtag'], hashtags: ['#Cats']);
	}
}
