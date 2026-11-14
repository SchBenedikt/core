<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Notification\ConversationMute;
use AlohaSocial\Core\Domain\Notification\InMemoryConversationMuteRepository;
use AlohaSocial\Core\Domain\Notification\InMemoryNotificationPolicyRepository;
use AlohaSocial\Core\Domain\Notification\InMemoryNotificationRepository;
use AlohaSocial\Core\Domain\Notification\InMemoryNotificationSenderRepository;
use AlohaSocial\Core\Domain\Notification\NotificationEventSink;
use AlohaSocial\Core\Domain\Notification\Notification;
use AlohaSocial\Core\Domain\Notification\NotificationPolicy;
use AlohaSocial\Core\Domain\Notification\NotificationPolicyAction;
use AlohaSocial\Core\Domain\Notification\NotificationRelationshipReader;
use AlohaSocial\Core\Domain\Notification\NotificationSenderAge;
use AlohaSocial\Core\Domain\Notification\NotificationSenderStatus;
use AlohaSocial\Core\Domain\Notification\NotificationThreadResolver;
use AlohaSocial\Core\Domain\Moderation\ModerationPolicy;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Service\Notification\NotificationPolicyService;
use AlohaSocial\Core\Service\Notification\NotificationRequestNotFoundException;
use AlohaSocial\Core\Service\NotificationService;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\TestCase;

use function str_starts_with;

final class NotificationPolicyServiceTest extends TestCase
{
	private InMemoryNotificationRepository $repo;

	private InMemoryNotificationPolicyRepository $policies;

	private InMemoryNotificationSenderRepository $senders;

	private InMemoryConversationMuteRepository $conversationMutes;

	private NotificationPolicyService $policyService;

	private FrozenClock $clock;

	public function testTheFiveCategoriesComeFromTheFollowGraphAndAccountAge(): void
	{
		$relationships = new class implements NotificationRelationshipReader {
			/** @var array<string, bool> */
			public array $follows = [];

			/** @var array<string, bool> */
			public array $followedBy = [];

			public function follows(string $recipientId, string $actorId): bool
			{
				return $this->follows[$actorId] ?? false;
			}

			public function isFollowedBy(string $recipientId, string $actorId): bool
			{
				return $this->followedBy[$actorId] ?? false;
			}
		};
		$ages = new class implements NotificationSenderAge {
			/** @var array<string, int> */
			public array $days = [];

			public function ageInDays(string $actorId): ?int
			{
				return $this->days[$actorId] ?? null;
			}
		};
		$service = $this->service(relationships: $relationships, ages: $ages);
		self::assertNotNull($service);

		$relationships->follows['mutual'] = true;
		$relationships->followedBy['mutual'] = true;
		self::assertSame('mutual', $this->policyService->categoryFor('alice', 'mutual')->value);

		$relationships->follows['following'] = true;
		self::assertSame('following', $this->policyService->categoryFor('alice', 'following')->value);

		$relationships->followedBy['follower'] = true;
		self::assertSame('follower', $this->policyService->categoryFor('alice', 'follower')->value);

		$ages->days['new'] = 3;
		self::assertSame('new', $this->policyService->categoryFor('alice', 'new')->value);

		$ages->days['old'] = 30;
		self::assertSame('stranger', $this->policyService->categoryFor('alice', 'old')->value);
	}

	public function testTheDefaultHoldsUnknownSendersWhileLegacyAllowsEveryone(): void
	{
		self::assertSame(
			NotificationPolicyAction::Hold,
			NotificationPolicy::default()->actionFor(
				\AlohaSocial\Core\Domain\Notification\NotificationSenderCategory::Stranger,
			),
		);
		self::assertSame(
			NotificationPolicyAction::Allow,
			NotificationPolicy::legacy()->actionFor(
				\AlohaSocial\Core\Domain\Notification\NotificationSenderCategory::New,
			),
		);
	}

	public function testHeldVisibilityAcceptAndDismissUseTheSameStoredEvent(): void
	{
		$sink = new class implements NotificationEventSink {
			/** @var list<string> */
			public array $ids = [];

			public function emit(Notification $notification): void
			{
				$this->ids[] = $notification->id;
			}
		};
		$threads = new class implements NotificationThreadResolver {
			public function rootFor(string $postId): ?string
			{
				return str_starts_with($postId, 'reply-') ? 'root' : $postId;
			}
		};
		$service = $this->service(sink: $sink, threads: $threads);

		$service->recordMention('alice', 'bob', 'reply-1');
		self::assertSame([], $service->listFor('alice', 10));
		self::assertSame(0, $service->unreadCount('alice'));
		self::assertSame([], $sink->ids);
		$request = $service->requests('alice')[0];
		self::assertSame('bob', $request['actor_id']);
		self::assertSame('held', $request['status']);

		$accepted = $service->acceptSender('alice', $request['id']);
		self::assertSame(1, $accepted['released']);
		$repeated = $service->acceptSender('alice', $request['id']);
		self::assertSame(0, $repeated['released'], 'Accepting twice does not release anything twice.');
		self::assertSame(['bob'], array_map(
			static fn (Notification $notification): string => $notification->actorId,
			$service->listFor('alice', 10),
		));
		self::assertSame(1, $service->unreadCount('alice'));
		self::assertSame([], $sink->ids, 'Accepting releases rows without re-notifying.');
		self::assertSame([], $service->requests('alice'));

		$service->recordMention('alice', 'bob', 'reply-2');
		self::assertCount(2, $service->listFor('alice', 10));
		self::assertCount(1, $sink->ids);

		$dismissed = $service->dismissSender('alice', $request['id']);
		self::assertSame('dismissed', $dismissed['request']['status']);
		self::assertSame(0, $dismissed['suppressed']);
		$service->recordMention('alice', 'bob', 'reply-3');
		self::assertCount(2, $service->listFor('alice', 10));
		self::assertCount(1, $sink->ids, 'A dismissed sender is dropped before storage visibility.');

		self::assertTrue($service->revokeSender('alice', $request['id']));
		$service->recordMention('alice', 'bob', 'reply-4');
		self::assertCount(2, $service->listFor('alice', 10));
		self::assertCount(1, $service->requests('alice'));
	}

	public function testAPolicyUpdateChangesFutureEventsOnly(): void
	{
		$service = $this->service();
		$service->recordMention('alice', 'bob', 'reply-old');
		self::assertSame([], $service->listFor('alice', 10));

		$policy = $service->updatePolicy('alice', [
			'actions' => ['stranger' => 'allow'],
			'new_account_days' => 14,
		]);
		self::assertSame('allow', $policy['actions']['stranger']);
		self::assertSame(14, $policy['new_account_days']);
		self::assertSame([], $service->listFor('alice', 10), 'The held row keeps its recorded status.');

		$service->recordMention('alice', 'carol', 'reply-new');
		self::assertCount(1, $service->listFor('alice', 10));
	}

	public function testConversationMuteSuppressesAlertsButNotThePost(): void
	{
		$threads = new class implements NotificationThreadResolver {
			public function rootFor(string $postId): ?string
			{
				return str_starts_with($postId, 'reply-') ? 'root' : $postId;
			}
		};
		$service = $this->service(threads: $threads);
		$service->updatePolicy('alice', ['actions' => ['stranger' => 'allow']]);

		$service->muteConversation('alice', 'reply-1');
		$service->recordMention('alice', 'bob', 'reply-2');
		self::assertSame([], $service->listFor('alice', 10));

		$service->unmuteConversation('alice', 'reply-1');
		$service->recordMention('alice', 'bob', 'reply-3');
		self::assertCount(1, $service->listFor('alice', 10));
	}

	public function testTheInstanceModerationPolicyDropsBeforeASenderRequestIsCreated(): void
	{
		$service = $this->service(moderation: new class implements ModerationPolicy {
			public function allowsActor(string $actorId): bool
			{
				return $actorId !== 'blocked';
			}

			public function allowsDomain(string $domain): bool
			{
				return true;
			}
		});

		$service->recordMention('alice', 'blocked', 'reply-1');
		self::assertSame([], $service->listFor('alice', 10));
		self::assertSame([], $service->requests('alice'));
	}

	public function testSenderRequestsAreScopedToTheirRecipient(): void
	{
		$service = $this->service();
		$service->recordMention('alice', 'bob', 'reply-1');
		$request = $service->requests('alice')[0];

		$this->expectException(NotificationRequestNotFoundException::class);
		$service->acceptSender('carol', $request['id']);
	}

	private function service(
		?NotificationRelationshipReader $relationships = null,
		?NotificationSenderAge $ages = null,
		?NotificationEventSink $sink = null,
		?NotificationThreadResolver $threads = null,
		?ModerationPolicy $moderation = null,
	): NotificationService {
		$this->clock = new FrozenClock(Time::parse('2026-11-14T10:00:00Z'));
		$this->repo = new InMemoryNotificationRepository();
		$this->policies = new InMemoryNotificationPolicyRepository();
		$this->senders = new InMemoryNotificationSenderRepository();
		$this->conversationMutes = new InMemoryConversationMuteRepository();

		$this->policyService = new NotificationPolicyService(
			$this->policies,
			$this->senders,
			$this->conversationMutes,
			$relationships ?? new \AlohaSocial\Core\Domain\Notification\NullNotificationRelationships(),
			$this->clock,
			$ages,
			$moderation,
		);

		return new NotificationService($this->repo, $this->clock, null, $this->policyService, $sink, $threads);
	}
}
