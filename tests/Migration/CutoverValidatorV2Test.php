<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Migration;

use AlohaSocial\Core\Migration\CutoverValidator;
use AlohaSocial\Core\Migration\InvalidMigrationDatasetException;
use AlohaSocial\Core\Migration\MigrationReport;
use AlohaSocial\Core\Migration\MigrationViolation;
use PHPUnit\Framework\TestCase;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * Version 2 is additive: it keeps every version-1 rule and adds cross-checks
 * for the sections that version 1 could not see. These tests fail against the
 * version-1-only validator, which ignored revisions, media rows, reactions,
 * quotes and declared counts entirely.
 */
final class CutoverValidatorV2Test extends TestCase
{
	public function testAConsistentVersionTwoExportPassesWithoutWarnings(): void
	{
		$report = $this->validate($this->dataset());

		self::assertTrue($report->passed());
		self::assertSame(MigrationReport::STATUS_PASS, $report->status());
		self::assertSame(0, $report->exitCode());
		self::assertSame([], $report->violations());
	}

	public function testVersionOneExportsStillValidateAndIgnoreVersionTwoSections(): void
	{
		$dataset = $this->dataset(1);
		$dataset['revisions'][0]['post_id'] = 'unknown-post';
		$dataset['counts']['posts'] = 99;

		$report = $this->validate($dataset);

		self::assertTrue($report->passed());
		self::assertSame(MigrationReport::STATUS_PASS, $report->status());
	}

	public function testAnUnsupportedVersionIsASchemaError(): void
	{
		$dataset = $this->dataset();
		$dataset['version'] = 3;

		$this->expectException(InvalidMigrationDatasetException::class);
		$this->expectExceptionMessage('expected one of 1, 2');

		$this->validate($dataset);
	}

	public function testMediaWithoutChecksumWarnsButStillPasses(): void
	{
		$dataset = $this->dataset();
		$dataset['media'][0]['checksum_sha256'] = null;

		$report = $this->validate($dataset);

		self::assertTrue($report->passed());
		self::assertSame(MigrationReport::STATUS_WARN, $report->status());
		self::assertSame(0, $report->exitCode());
		self::assertCount(1, $report->warnings());
		self::assertSame('checksum_sha256', $report->warnings()[0]->field);
		self::assertSame('media[0]', $report->warnings()[0]->record);
	}

	public function testADeadQueueJobIsReportedAsAWarningInVersionTwo(): void
	{
		$dataset = $this->dataset();
		$dataset['queue'][0]['dead_at'] = '2026-11-12T10:00:00Z';

		$report = $this->validate($dataset);

		self::assertTrue($report->passed());
		self::assertSame(MigrationReport::STATUS_WARN, $report->status());
		self::assertSame('dead_at', $report->warnings()[0]->field);
	}

	public function testAVersionOneExportNeverWarnsAboutADeadJob(): void
	{
		$dataset = $this->dataset(1);
		$dataset['queue'][0]['dead_at'] = '2026-11-12T10:00:00Z';

		$report = $this->validate($dataset);

		self::assertSame([], $report->violations());
	}

	public function testAnIncompleteRevisionHistoryIsAFailure(): void
	{
		$dataset = $this->dataset();
		$dataset['posts'][0]['revision'] = 3;
		$dataset['counts']['revisions'] = 1;

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame(MigrationReport::STATUS_FAIL, $report->status());
		self::assertSame(1, $report->exitCode());
		self::assertSame('revision', $report->failures()[0]->field);
		self::assertSame('posts[0]', $report->failures()[0]->record);
		self::assertSame('2 stored version(s)', $report->failures()[0]->expected);
	}

	public function testRevisionNumbersMustBeContiguous(): void
	{
		$dataset = $this->dataset();
		$dataset['posts'][0]['revision'] = 2;
		$dataset['revisions'][0]['revision'] = 2;

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('revision', $report->failures()[0]->field);
	}

	public function testARevisionMustBelongToAnExportedPost(): void
	{
		$dataset = $this->dataset();
		$dataset['revisions'][0]['post_id'] = 'ghost';

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('post_id', $report->failures()[0]->field);
	}

	public function testDuplicateRevisionNumbersAreRejected(): void
	{
		$dataset = $this->dataset();
		$dataset['posts'][0]['revision'] = 3;
		$dataset['revisions'][] = $dataset['revisions'][0];
		$dataset['counts']['revisions'] = 2;

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertContains('revision', $this->fields($report));
	}

	public function testRevisionTimestampsMustNotGoBackwards(): void
	{
		$dataset = $this->dataset();
		$dataset['posts'][0]['revision'] = 3;
		$dataset['revisions'][] = [
			'post_id' => 'post-1',
			'revision' => 2,
			'content' => 'An even newer body',
			'content_warning' => null,
			'edited_at' => '2026-11-09T10:00:00Z',
		];
		$dataset['counts']['revisions'] = 2;

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('edited_at', $report->failures()[0]->field);
	}

	public function testALinkedAttachmentWithoutAMediaRowFails(): void
	{
		$dataset = $this->dataset();
		$dataset['posts'][0]['attachment_ids'] = ['att-1', 'att-missing'];
		$dataset['counts']['media'] = 1;

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('posts[0]', $report->failures()[0]->record);
		self::assertSame('attachment_ids', $report->failures()[0]->field);
		self::assertSame('att-missing', $report->failures()[0]->actual);
	}

	public function testAMalformedChecksumFails(): void
	{
		$dataset = $this->dataset();
		$dataset['media'][0]['checksum_sha256'] = 'not-a-checksum';

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('checksum_sha256', $report->failures()[0]->field);
	}

	public function testAnUnservedMediaTypeFails(): void
	{
		$dataset = $this->dataset();
		$dataset['media'][0]['mime_type'] = 'application/x-executable';

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertContains('mime_type', $this->fields($report));
	}

	public function testDuplicateAttachmentIdsFail(): void
	{
		$dataset = $this->dataset();
		$dataset['media'][] = $dataset['media'][0];
		$dataset['counts']['media'] = 2;

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertContains('attachment_id', $this->fields($report));
	}

	public function testAnEmojiReactionNeedsItsEmoji(): void
	{
		$dataset = $this->dataset();
		$dataset['reactions'][0]['kind'] = 'emoji';

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('emoji', $report->failures()[0]->field);
	}

	public function testTheSameReactionFromTheSameActorIsRejected(): void
	{
		$dataset = $this->dataset();
		$dataset['reactions'][] = $dataset['reactions'][0];
		$dataset['counts']['reactions'] = 2;

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertContains('actor_id', $this->fields($report));
	}

	public function testALikeThatCarriesAnEmojiWarns(): void
	{
		$dataset = $this->dataset();
		$dataset['reactions'][0]['emoji'] = '❤';

		$report = $this->validate($dataset);

		self::assertTrue($report->passed());
		self::assertSame(MigrationReport::STATUS_WARN, $report->status());
		self::assertSame('emoji', $report->warnings()[0]->field);
	}

	public function testAnInvalidReactionKindFails(): void
	{
		$dataset = $this->dataset();
		$dataset['reactions'][0]['kind'] = 'dislike';

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('kind', $report->failures()[0]->field);
	}

	public function testAQuoteMustPointAtAnExportedPost(): void
	{
		$dataset = $this->dataset();
		$dataset['quotes'][0]['post_id'] = 'ghost';

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('post_id', $report->failures()[0]->field);
	}

	public function testAPostCanOnlyQuoteOnce(): void
	{
		$dataset = $this->dataset();
		$dataset['quotes'][] = $dataset['quotes'][0];
		$dataset['counts']['quotes'] = 2;

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertContains('post_id', $this->fields($report));
	}

	public function testADeclaredCountThatDoesNotMatchTheRowsFails(): void
	{
		$dataset = $this->dataset();
		$dataset['counts']['following'] = 2;

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('counts', $report->failures()[0]->record);
		self::assertSame('following', $report->failures()[0]->field);
		self::assertSame(1, $report->failures()[0]->expected);
		self::assertSame(2, $report->failures()[0]->actual);
	}

	public function testAnUnknownDeclaredCountWarnsInsteadOfBeingIgnored(): void
	{
		$dataset = $this->dataset();
		$dataset['counts']['stories'] = 7;

		$report = $this->validate($dataset);

		self::assertTrue($report->passed());
		self::assertSame(MigrationReport::STATUS_WARN, $report->status());
		self::assertSame('stories', $report->warnings()[0]->field);
	}

	public function testANonIntegerDeclaredCountFails(): void
	{
		$dataset = $this->dataset();
		$dataset['counts']['posts'] = '1';

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('posts', $report->failures()[0]->field);
	}

	public function testWarningViolationsAreSerializedWithTheirSeverity(): void
	{
		$report = $this->validate($this->dataset());
		$array = $report->toArray();

		self::assertSame(MigrationReport::STATUS_PASS, $array['status']);
		self::assertSame(0, $array['warnings']);
		self::assertSame(0, $array['failures']);
		self::assertSame(0, $array['exit_code']);

		$warning = new MigrationViolation('media[0]', 'checksum_sha256', 'Missing.', null, null, MigrationViolation::WARN);
		self::assertSame('WARN media[0].checksum_sha256: Missing.', $warning->toText());
		self::assertSame('fail', (new MigrationViolation('a', 'b', 'c'))->severity);
	}

	/**
	 * @param array<string, mixed> $dataset
	 */
	private function validate(array $dataset): MigrationReport
	{
		return (new CutoverValidator())->validateJson(
			json_encode($dataset, JSON_THROW_ON_ERROR),
			'fixture-v2.json',
		);
	}

	/**
	 * @return list<string>
	 */
	private function fields(MigrationReport $report): array
	{
		$fields = [];
		foreach ($report->violations() as $violation) {
			$fields[] = $violation->field;
		}

		return $fields;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function dataset(int $version = 2): array
	{
		$dataset = [
			'format' => CutoverValidator::FORMAT,
			'version' => $version,
			'instance_url' => 'https://social.example',
			'accounts' => [[
				'legacy_user_id' => 'alice',
				'local_user_id' => 'alice',
				'actor_url' => 'https://social.example/@alice',
				'key_id' => 'https://social.example/@alice#main-key',
			]],
			'posts' => [[
				'legacy_id' => 'post-1',
				'id' => 'post-1',
				'author_id' => 'alice',
				'content' => 'Hello from the old instance',
				'content_warning' => null,
				'visibility' => 'public',
				'published_at' => '2026-11-11T10:00:00Z',
				'deleted_at' => null,
				'note_url' => 'https://social.example/api/v1/posts/post-1',
				'create_id' => 'https://social.example/api/v1/posts/post-1/activity',
				'delete_id' => 'https://social.example/api/v1/posts/post-1/delete',
				'revision' => 2,
				'edited_at' => '2026-11-11T11:00:00Z',
				'attachment_ids' => ['att-1'],
			]],
			'followers' => [[
				'legacy_id' => 'follower-1',
				'id' => 'follower-1',
				'local_user_id' => 'alice',
				'actor_id' => 'https://remote.example/users/bob',
				'inbox_url' => 'https://remote.example/users/bob/inbox',
				'followed_at' => '2026-11-11T10:00:00Z',
			]],
			'following' => [[
				'legacy_id' => 'follow-1',
				'id' => 'follow-1',
				'local_user_id' => 'alice',
				'actor_id' => 'https://remote.example/users/bob',
				'inbox_url' => 'https://remote.example/users/bob/inbox',
				'followed_at' => '2026-11-11T10:00:00Z',
				'follow_id' => 'https://social.example/api/v1/following/follow-1/follow',
				'undo_id' => 'https://social.example/api/v1/following/follow-1/follow/undo',
			]],
			'queue' => [[
				'id' => 'job-1',
				'type' => 'federation.deliver',
				'activity_type' => 'Create',
				'activity_id' => 'https://social.example/api/v1/posts/post-1/activity',
				'inbox' => 'https://remote.example/users/bob/inbox',
				'key_id' => 'https://social.example/@alice#main-key',
				'available_at' => '2026-11-11T10:00:00Z',
				'attempts' => 0,
				'dead_at' => null,
			]],
			'activity_log' => [[
				'activity_id' => 'https://remote.example/activities/1',
				'seen_at' => '2026-11-11T10:00:00Z',
			]],
			'revisions' => [[
				'post_id' => 'post-1',
				'revision' => 1,
				'content' => 'Hello from the old instance',
				'content_warning' => null,
				'edited_at' => '2026-11-11T10:30:00Z',
			]],
			'media' => [[
				'attachment_id' => 'att-1',
				'post_id' => 'post-1',
				'storage_key' => 'attachments/att-1.webp',
				'mime_type' => 'image/webp',
				'alt_text' => 'A sunset over the harbour',
				'byte_size' => 1024,
				'checksum_sha256' => str_repeat('a', 64),
			]],
			'reactions' => [[
				'activity_id' => 'https://remote.example/activities/like-1',
				'actor_id' => 'https://remote.example/users/bob',
				'object_id' => 'https://social.example/api/v1/posts/post-1',
				'kind' => 'like',
				'emoji' => null,
				'created_at' => '2026-11-11T12:00:00Z',
			]],
			'quotes' => [[
				'post_id' => 'post-1',
				'quoted_url' => 'https://remote.example/notes/9',
				'authorization' => null,
			]],
			'counts' => [
				'accounts' => 1,
				'posts' => 1,
				'followers' => 1,
				'following' => 1,
				'queue' => 1,
				'activity_log' => 1,
				'revisions' => 1,
				'media' => 1,
				'reactions' => 1,
				'quotes' => 1,
			],
		];

		if ($version === 1) {
			unset($dataset['posts'][0]['revision'], $dataset['posts'][0]['edited_at'], $dataset['posts'][0]['attachment_ids']);
		}

		return $dataset;
	}
}
