<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Notification\ConversationMute;
use AlohaSocial\Core\Domain\Notification\ConversationMuteSchema;
use AlohaSocial\Core\Domain\Notification\NotificationPolicy;
use AlohaSocial\Core\Domain\Notification\NotificationPolicyAction;
use AlohaSocial\Core\Domain\Notification\NotificationPolicySchema;
use AlohaSocial\Core\Domain\Notification\NotificationSender;
use AlohaSocial\Core\Domain\Notification\NotificationSenderCategory;
use AlohaSocial\Core\Domain\Notification\NotificationSenderSchema;
use AlohaSocial\Core\Domain\Notification\NotificationSenderStatus;
use AlohaSocial\Core\Domain\Notification\PdoConversationMuteRepository;
use AlohaSocial\Core\Domain\Notification\PdoNotificationPolicyRepository;
use AlohaSocial\Core\Domain\Notification\PdoNotificationSenderRepository;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Support\Time;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoNotificationPolicyRepositoryTest extends TestCase
{
	private PdoConnection $db;

	protected function setUp(): void
	{
		$this->db = new PdoConnection(new PDO('sqlite::memory:'));
		NotificationPolicySchema::create($this->db);
		NotificationSenderSchema::create($this->db);
		ConversationMuteSchema::create($this->db);
	}

	public function testPolicyRoundTripsAndUpdatesInPlace(): void
	{
		$repository = new PdoNotificationPolicyRepository($this->db);
		self::assertNull($repository->find('alice'));

		$policy = NotificationPolicy::default();
		$repository->save('alice', $policy, Time::parse('2026-11-14T10:00:00Z'));
		self::assertSame($policy->toArray(), $repository->find('alice')?->toArray());

		$changed = $policy->withAction(NotificationSenderCategory::Stranger, NotificationPolicyAction::Drop);
		$repository->save('alice', $changed, Time::parse('2026-11-14T10:01:00Z'));
		self::assertSame('drop', $repository->find('alice')?->toArray()['actions']['stranger']);
	}

	public function testSenderRequestLifecycleIsOneRow(): void
	{
		$repository = new PdoNotificationSenderRepository($this->db);
		$at = Time::parse('2026-11-14T10:00:00Z');
		$sender = new NotificationSender(
			NotificationSender::idFor('alice', 'https://remote.example/users/bob'),
			'alice',
			'https://remote.example/users/bob',
			NotificationSenderStatus::Held,
			NotificationSenderCategory::Stranger,
			$at,
		);
		$repository->save($sender);

		self::assertSame(1, count($repository->forRecipient('alice', NotificationSenderStatus::Held)));
		self::assertNull($repository->find('alice', 'https://remote.example/users/carol'));

		$allowed = $sender->withStatus(NotificationSenderStatus::Allowed, $at);
		$repository->save($allowed);
		self::assertSame(NotificationSenderStatus::Allowed, $repository->findById($sender->id)?->status);
		self::assertSame([], $repository->forRecipient('alice', NotificationSenderStatus::Held));

		self::assertTrue($repository->remove('alice', $sender->actorId));
		self::assertFalse($repository->remove('alice', $sender->actorId));
		self::assertNull($repository->find('alice', $sender->actorId));
	}

	public function testConversationMuteIsPrivateAndIdempotent(): void
	{
		$repository = new PdoConversationMuteRepository($this->db);
		$mute = new ConversationMute(
			ConversationMute::idFor('alice', 'post-1'),
			'alice',
			'post-1',
			Time::parse('2026-11-14T10:00:00Z'),
		);
		$repository->save($mute);
		$repository->save($mute);

		self::assertNotNull($repository->find('alice', 'post-1'));
		self::assertNull($repository->find('bob', 'post-1'));
		self::assertTrue($repository->remove('alice', 'post-1'));
		self::assertFalse($repository->remove('alice', 'post-1'));
	}
}
