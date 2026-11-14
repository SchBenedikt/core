<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Domain;

use AlohaSocial\Core\Domain\Moderation\HeldPost;
use AlohaSocial\Core\Domain\Moderation\HeldPostSchema;
use AlohaSocial\Core\Domain\Moderation\HeldPostStatus;
use AlohaSocial\Core\Domain\Moderation\ModerationAction;
use AlohaSocial\Core\Domain\Moderation\ModerationActionKind;
use AlohaSocial\Core\Domain\Moderation\ModerationActionSchema;
use AlohaSocial\Core\Domain\Moderation\ModerationDecision;
use AlohaSocial\Core\Domain\Moderation\PdoHeldPostRepository;
use AlohaSocial\Core\Domain\Moderation\PdoModerationActionRepository;
use AlohaSocial\Core\Domain\Moderation\PdoReportRepository;
use AlohaSocial\Core\Domain\Moderation\Report;
use AlohaSocial\Core\Domain\Moderation\ReportReason;
use AlohaSocial\Core\Domain\Moderation\ReportSchema;
use AlohaSocial\Core\Domain\Moderation\ReportStatus;
use AlohaSocial\Core\Domain\Moderation\ReportTargetType;
use AlohaSocial\Core\Domain\Moderation\StoredModerationPolicy;
use AlohaSocial\Core\Platform\Db\PdoConnection;
use AlohaSocial\Core\Support\Time;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * SQL adapters for the moderation tables: conditional claims, audit history
 * and the held-post state machine against a real SQLite file.
 */
final class PdoModerationRepositoryTest extends TestCase
{
	private PdoConnection $db;

	protected function setUp(): void
	{
		$this->db = new PdoConnection(new PDO('sqlite::memory:'));
		ReportSchema::create($this->db);
		ModerationActionSchema::create($this->db);
		HeldPostSchema::create($this->db);
	}

	public function testReportQueueClaimCompleteAndReleaseAreConditional(): void
	{
		$reports = new PdoReportRepository($this->db);
		$report = $this->report('report-1');

		$reports->insert($report);
		self::assertSame($report->id, $reports->findOpenByReporter('alice', ReportTargetType::Post, 'post-1')?->id);
		self::assertCount(1, $reports->queue(10));
		self::assertSame(1, $reports->countOpen());

		$at = Time::parse('2026-11-14T10:01:00Z');
		self::assertTrue($reports->claimForReview('report-1', 'mod-1', $at));
		self::assertFalse($reports->claimForReview('report-1', 'mod-2', $at), 'Only one reviewer may claim a report.');
		self::assertSame(ReportStatus::Reviewing, $reports->find('report-1')?->status);

		$reports->release('report-1');
		self::assertSame(ReportStatus::Pending, $reports->find('report-1')?->status);
		self::assertTrue($reports->claimForReview('report-1', 'mod-1', $at));

		self::assertTrue($reports->complete(
			'report-1',
			ReportStatus::Resolved,
			ModerationDecision::SuspendActor,
			'mod-1',
			'confirmed',
			$at,
		));
		self::assertFalse($reports->complete(
			'report-1',
			ReportStatus::Resolved,
			ModerationDecision::SuspendActor,
			'mod-1',
			'again',
			$at,
		));
		self::assertSame(0, $reports->countOpen());
		self::assertSame('confirmed', $reports->find('report-1')?->resolution);
	}

	public function testActionsApplyAndReverseWithoutDeletingHistory(): void
	{
		$actions = new PdoModerationActionRepository($this->db);
		$action = $this->action('action-1', ModerationActionKind::SuspendActor, 'https://remote.example/users/bob');

		$actions->insert($action);
		self::assertTrue($actions->isActorRestricted('https://remote.example/users/bob'));
		self::assertCount(1, $actions->active());
		self::assertSame($action->id, $actions->findActive(
			ModerationActionKind::SuspendActor,
			'https://remote.example/users/bob',
		)?->id);

		self::assertTrue($actions->reverse(
			'action-1',
			'mod-2',
			'appeal accepted',
			Time::parse('2026-11-14T11:00:00Z'),
		));
		self::assertFalse($actions->reverse(
			'action-1',
			'mod-2',
			'again',
			Time::parse('2026-11-14T11:00:00Z'),
		));
		self::assertFalse($actions->isActorRestricted('https://remote.example/users/bob'));
		self::assertCount(0, $actions->active());
		self::assertCount(1, $actions->historyFor('https://remote.example/users/bob'));
		self::assertSame('appeal accepted', $actions->historyFor('https://remote.example/users/bob')[0]->reversalNote);
	}

	public function testDomainPolicyIsDerivedFromStoredActions(): void
	{
		$actions = new PdoModerationActionRepository($this->db);
		$actions->insert($this->action('action-domain', ModerationActionKind::BlockDomain, 'remote.example'));

		$policy = new StoredModerationPolicy($actions);
		self::assertFalse($policy->allowsDomain('remote.example'));
		self::assertFalse($policy->allowsActor('https://remote.example/users/bob'));
		self::assertTrue($policy->allowsActor('https://other.example/users/bob'));
		// A domain block is exact; it does not silently cover a subdomain.
		self::assertTrue($policy->allowsDomain('sub.remote.example'));
	}

	public function testHeldPostClaimApproveRejectAndRelease(): void
	{
		$held = new PdoHeldPostRepository($this->db);
		$post = new HeldPost(
			id: 'held-1',
			authorId: 'alice',
			draft: ['content' => 'review me', 'visibility' => 'public'],
			reason: 'first_post',
			status: HeldPostStatus::Pending,
			postId: null,
			createdAt: Time::parse('2026-11-14T10:00:00Z'),
		);

		$held->insert($post);
		self::assertCount(1, $held->queue(10));
		$at = Time::parse('2026-11-14T10:01:00Z');
		self::assertTrue($held->claim('held-1', 'mod-1', $at));
		self::assertFalse($held->claim('held-1', 'mod-2', $at));
		$held->release('held-1');
		self::assertSame(HeldPostStatus::Pending, $held->find('held-1')?->status);

		self::assertTrue($held->claim('held-1', 'mod-1', $at));
		self::assertTrue($held->approve('held-1', 'post-1', 'mod-1', 'welcome', $at));
		self::assertSame('post-1', $held->find('held-1')?->postId);
		self::assertSame(HeldPostStatus::Approved, $held->find('held-1')?->status);

		$second = new HeldPost(
			id: 'held-2',
			authorId: 'alice',
			draft: ['content' => 'spam', 'visibility' => 'public'],
			reason: 'spam',
			status: HeldPostStatus::Pending,
			postId: null,
			createdAt: Time::parse('2026-11-14T10:02:00Z'),
		);
		$held->insert($second);
		self::assertTrue($held->claim('held-2', 'mod-1', $at));
		self::assertTrue($held->reject('held-2', 'mod-1', 'not welcome', $at));
		self::assertSame(HeldPostStatus::Rejected, $held->find('held-2')?->status);
		self::assertNull($held->find('held-2')?->postId);
	}

	private function report(string $id): Report
	{
		$at = Time::parse('2026-11-14T10:00:00Z');

		return new Report(
			id: $id,
			reporterId: 'alice',
			targetType: ReportTargetType::Post,
			targetId: 'post-1',
			reason: ReportReason::Spam,
			comment: 'spam',
			evidence: ['excerpt'],
			status: ReportStatus::Pending,
			decision: null,
			moderatorId: null,
			resolution: null,
			createdAt: $at,
			updatedAt: $at,
		);
	}

	private function action(string $id, ModerationActionKind $kind, string $target): ModerationAction
	{
		return new ModerationAction(
			id: $id,
			kind: $kind,
			target: $target,
			reportId: 'report-1',
			moderatorId: 'mod-1',
			note: 'confirmed',
			createdAt: Time::parse('2026-11-14T10:00:00Z'),
		);
	}
}
