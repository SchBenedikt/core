<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Migration;

use AlohaSocial\Core\Migration\InvalidMigrationDatasetException;
use AlohaSocial\Core\Migration\MigrationReport;
use AlohaSocial\Core\Migration\ShadowReadComparator;
use PHPUnit\Framework\TestCase;

final class ShadowReadComparatorTest extends TestCase
{
	public function testIdenticalReadsPass(): void
	{
		$report = (new ShadowReadComparator())->compare('posts', ShadowFixtures::posts(), ShadowFixtures::posts());

		self::assertTrue($report->passed());
		self::assertSame(MigrationReport::STATUS_PASS, $report->status());
		self::assertSame('shadow:posts', $report->source);
	}

	public function testTheNewReadPathMayAddAFieldWithoutBlocking(): void
	{
		$new = ShadowFixtures::posts();
		$new[0]['edited_at'] = '2026-11-11T11:00:00Z';

		$report = (new ShadowReadComparator())->compare('posts', ShadowFixtures::posts(), $new);

		self::assertTrue($report->passed());
		self::assertSame(MigrationReport::STATUS_WARN, $report->status());
		self::assertSame('edited_at', $report->warnings()[0]->field);
	}

	public function testARecordThatOnlyTheNewPathReturnsWarns(): void
	{
		$new = ShadowFixtures::posts();
		$new[] = [
			'id' => 'post-3',
			'actor_id' => 'https://social.example/@alice',
			'note_url' => 'https://social.example/api/v1/posts/post-3',
			'activity_id' => 'https://social.example/api/v1/posts/post-3/activity',
			'content' => 'Only new',
			'published_at' => '2026-11-11T10:09:00Z',
			'attachment_urls' => [],
		];

		$report = (new ShadowReadComparator())->compare('posts', ShadowFixtures::posts(), $new);

		self::assertTrue($report->passed());
		self::assertSame(MigrationReport::STATUS_WARN, $report->status());
		self::assertSame('posts[post-3]', $report->warnings()[0]->record);
	}

	public function testARecordMissingInTheNewPathIsADataLossFailure(): void
	{
		$new = [ShadowFixtures::posts()[0]];

		$report = (new ShadowReadComparator())->compare('posts', ShadowFixtures::posts(), $new);

		self::assertFalse($report->passed());
		self::assertSame(1, $report->exitCode());
		self::assertSame('posts[post-2]', $report->failures()[0]->record);
		self::assertSame('id', $report->failures()[0]->field);
		self::assertSame('post-2', $report->failures()[0]->expected);
	}

	public function testADifferentValueIsReportedWithBothSides(): void
	{
		$old = ShadowFixtures::following();
		$new = ShadowFixtures::following();
		$new[0]['undo_id'] = 'https://social.example/api/v1/following/follow-1/undo';

		$report = (new ShadowReadComparator())->compare('following', $old, $new);

		self::assertFalse($report->passed());
		self::assertSame('undo_id', $report->failures()[0]->field);
		self::assertSame('https://social.example/api/v1/following/follow-1/follow/undo', $report->failures()[0]->expected);
		self::assertSame('https://social.example/api/v1/following/follow-1/undo', $report->failures()[0]->actual);
	}

	public function testADroppedFieldFails(): void
	{
		$new = ShadowFixtures::remotePosts();
		unset($new[0]['activity_id']);

		$report = (new ShadowReadComparator())->compare('remote_posts', ShadowFixtures::remotePosts(), $new);

		self::assertFalse($report->passed());
		self::assertSame('activity_id', $report->failures()[0]->field);
	}

	public function testDuplicateIdsInOneReadPathFail(): void
	{
		$rows = ShadowFixtures::posts();
		$rows[] = $rows[0];

		$report = (new ShadowReadComparator())->compare('posts', $rows, $rows);

		self::assertFalse($report->passed());
		self::assertSame('id', $report->failures()[0]->field);
	}

	public function testARowWithoutIdIsASchemaError(): void
	{
		$this->expectException(InvalidMigrationDatasetException::class);
		$this->expectExceptionMessage('posts[0].id');

		(new ShadowReadComparator())->compare('posts', [['content' => 'no id']], []);
	}

	public function testAnOldToNewToRollbackRoundTripKeepsFederationIdentity(): void
	{
		$comparator = new ShadowReadComparator();

		$forward = $comparator->compare('posts', ShadowFixtures::posts(), ShadowFixtures::posts());
		self::assertSame(MigrationReport::STATUS_PASS, $forward->status());

		$rollback = $comparator->compare('posts', ShadowFixtures::posts(), ShadowFixtures::rolledBackPosts());
		self::assertSame(MigrationReport::STATUS_PASS, $rollback->status());
		self::assertSame(0, $rollback->exitCode());

		$broken = $comparator->compare('posts', ShadowFixtures::posts(), ShadowFixtures::tamperedRollbackPosts());
		self::assertFalse($broken->passed());
		self::assertSame('actor_id', $broken->failures()[0]->field);
		self::assertCount(2, $broken->failures());
	}
}
