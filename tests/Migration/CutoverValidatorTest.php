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
use PHPUnit\Framework\TestCase;

use function dirname;
use function escapeshellarg;
use function file_put_contents;
use function json_decode;
use function json_encode;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class CutoverValidatorTest extends TestCase
{
	public function testAPassingExportHasNoViolations(): void
	{
		$report = $this->validate($this->dataset());

		self::assertTrue($report->passed());
		self::assertSame(0, $report->exitCode());
		self::assertSame([], $report->violations());
	}

	public function testACorruptActorUrlIsReportedByRecordAndField(): void
	{
		$dataset = $this->dataset();
		$dataset['accounts'][0]['actor_url'] = 'https://social.example/@someone-else';

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame(1, $report->exitCode());
		self::assertCount(1, $report->violations());
		self::assertSame('accounts[0]', $report->violations()[0]->record);
		self::assertSame('actor_url', $report->violations()[0]->field);
	}

	public function testACorruptKeyIdIsReported(): void
	{
		$dataset = $this->dataset();
		$dataset['accounts'][0]['key_id'] = 'https://social.example/@alice#other-key';

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('accounts[0]', $report->violations()[0]->record);
		self::assertSame('key_id', $report->violations()[0]->field);
	}

	public function testACorruptPostUrlIsReported(): void
	{
		$dataset = $this->dataset();
		$dataset['posts'][0]['note_url'] = 'https://social.example/api/v1/posts/other';

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('posts[0]', $report->violations()[0]->record);
		self::assertSame('note_url', $report->violations()[0]->field);
	}

	public function testACorruptCreateActivityIdIsReported(): void
	{
		$dataset = $this->dataset();
		$dataset['posts'][0]['create_id'] = 'https://social.example/api/v1/posts/post-1/other';

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('posts[0]', $report->violations()[0]->record);
		self::assertSame('create_id', $report->violations()[0]->field);
	}

	public function testACorruptDeleteActivityIdIsReported(): void
	{
		$dataset = $this->dataset();
		$dataset['posts'][0]['delete_id'] = 'https://social.example/api/v1/posts/post-1/other';

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('posts[0]', $report->violations()[0]->record);
		self::assertSame('delete_id', $report->violations()[0]->field);
	}

	public function testACorruptFollowActivityIdIsReported(): void
	{
		$dataset = $this->dataset();
		$dataset['following'][0]['follow_id'] = 'https://social.example/api/v1/following/other/follow';

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('following[0]', $report->violations()[0]->record);
		self::assertSame('follow_id', $report->violations()[0]->field);
	}

	public function testACorruptUndoActivityIdIsReported(): void
	{
		$dataset = $this->dataset();
		$dataset['following'][0]['undo_id'] = 'https://social.example/api/v1/following/other/follow/undo';

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('following[0]', $report->violations()[0]->record);
		self::assertSame('undo_id', $report->violations()[0]->field);
	}

	public function testAChangedSocialFollowingRowIdIsReportedEvenWhenItsUrlsWereRegenerated(): void
	{
		$dataset = $this->dataset();
		$dataset['following'][0]['id'] = 'follow-2';
		$dataset['following'][0]['follow_id'] = 'https://social.example/api/v1/following/follow-2/follow';
		$dataset['following'][0]['undo_id'] = 'https://social.example/api/v1/following/follow-2/follow/undo';

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('following[0]', $report->violations()[0]->record);
		self::assertSame('id', $report->violations()[0]->field);
		self::assertSame('follow-1', $report->violations()[0]->expected);
		self::assertSame('follow-2', $report->violations()[0]->actual);
	}

	public function testACorruptPostIdIsReported(): void
	{
		$dataset = $this->dataset();
		$dataset['posts'][0]['id'] = 'post-2';
		$dataset['posts'][0]['note_url'] = 'https://social.example/api/v1/posts/post-2';
		$dataset['posts'][0]['create_id'] = 'https://social.example/api/v1/posts/post-2/activity';
		$dataset['posts'][0]['delete_id'] = 'https://social.example/api/v1/posts/post-2/delete';
		$dataset['queue'][0]['activity_id'] = 'https://social.example/api/v1/posts/post-2/activity';

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('posts[0]', $report->violations()[0]->record);
		self::assertSame('id', $report->violations()[0]->field);
	}

	public function testANewFollowerRowIdIsNotAFederationIdentityBreak(): void
	{
		$dataset = $this->dataset();
		$dataset['followers'][0]['id'] = 'follower-2';

		$report = $this->validate($dataset);

		self::assertTrue($report->passed());
	}

	public function testAnOrphanedPostAuthorIsReported(): void
	{
		$dataset = $this->dataset();
		$dataset['posts'][0]['author_id'] = 'missing-user';

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('posts[0]', $report->violations()[0]->record);
		self::assertSame('author_id', $report->violations()[0]->field);
	}

	public function testAnOrphanedFollowerLocalUserIsReported(): void
	{
		$dataset = $this->dataset();
		$dataset['followers'][0]['local_user_id'] = 'missing-user';

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('followers[0]', $report->violations()[0]->record);
		self::assertSame('local_user_id', $report->violations()[0]->field);
	}

	public function testAnOrphanedFollowingLocalUserIsReported(): void
	{
		$dataset = $this->dataset();
		$dataset['following'][0]['local_user_id'] = 'missing-user';

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('following[0]', $report->violations()[0]->record);
		self::assertSame('local_user_id', $report->violations()[0]->field);
	}

	public function testAnOrphanedQueueKeyIdIsReported(): void
	{
		$dataset = $this->dataset();
		$dataset['queue'][0]['key_id'] = 'https://social.example/@missing#main-key';

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('queue[0]', $report->violations()[0]->record);
		self::assertSame('key_id', $report->violations()[0]->field);
	}

	public function testAnOrphanedQueueActivityIdIsReported(): void
	{
		$dataset = $this->dataset();
		$dataset['queue'][0]['activity_id'] = 'https://social.example/api/v1/posts/missing/activity';

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('queue[0]', $report->violations()[0]->record);
		self::assertSame('activity_id', $report->violations()[0]->field);
	}

	public function testADuplicateFollowerPairIsReported(): void
	{
		$dataset = $this->dataset();
		$duplicate = $dataset['followers'][0];
		$duplicate['legacy_id'] = 'follower-2';
		$duplicate['id'] = 'follower-2';
		$dataset['followers'][] = $duplicate;

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('followers[1]', $report->violations()[0]->record);
		self::assertSame('actor_id', $report->violations()[0]->field);
	}

	public function testADuplicateActivityLogEntryIsReported(): void
	{
		$dataset = $this->dataset();
		$dataset['activity_log'][] = $dataset['activity_log'][0];

		$report = $this->validate($dataset);

		self::assertFalse($report->passed());
		self::assertSame('activity_log[1]', $report->violations()[0]->record);
		self::assertSame('activity_id', $report->violations()[0]->field);
	}

	public function testInvalidJsonIsAToolingErrorNotAParityFailure(): void
	{
		$this->expectException(InvalidMigrationDatasetException::class);

		(new CutoverValidator())->validateJson('{"format":');
	}

	public function testTheCommandReturnsZeroAndAPassingReport(): void
	{
		$path = $this->writeExport($this->dataset());

		try {
			[$status, $stdout, $stderr] = $this->runCli($path);

			self::assertSame(0, $status, $stderr);
			self::assertStringContainsString('Result: PASS', $stdout);
			self::assertSame('', $stderr);
		} finally {
			unlink($path);
		}
	}

	public function testTheCommandReturnsOneAndTheParityReportForViolations(): void
	{
		$dataset = $this->dataset();
		$dataset['accounts'][0]['actor_url'] = 'https://social.example/@someone-else';
		$path = $this->writeExport($dataset);

		try {
			[$status, $stdout, $stderr] = $this->runCli($path, '--json');
			$report = json_decode($stdout, true, 64, JSON_THROW_ON_ERROR);

			self::assertSame(1, $status, $stderr);
			self::assertFalse($report['passed']);
			self::assertSame('accounts[0]', $report['violations'][0]['record']);
			self::assertSame('actor_url', $report['violations'][0]['field']);
		} finally {
			unlink($path);
		}
	}

	public function testTheCommandReturnsTwoForAnUnreadableOrInvalidExport(): void
	{
		$path = $this->writeExport(['format' => 'wrong']);

		try {
			[$status, $stdout, $stderr] = $this->runCli($path);

			self::assertSame(2, $status);
			self::assertSame('', $stdout);
			self::assertStringContainsString('Invalid migration export', $stderr);
		} finally {
			unlink($path);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function dataset(): array
	{
		return [
			'format' => CutoverValidator::FORMAT,
			'version' => CutoverValidator::VERSION,
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
		];
	}

	/**
	 * @param array<string, mixed> $dataset
	 */
	private function validate(array $dataset): MigrationReport
	{
		return (new CutoverValidator())->validateJson(
			json_encode($dataset, JSON_THROW_ON_ERROR),
			'fixture.json',
		);
	}

	/**
	 * @param array<string, mixed> $dataset
	 */
	private function writeExport(array $dataset): string
	{
		$path = tempnam(sys_get_temp_dir(), 'cutover-');
		self::assertIsString($path);
		file_put_contents($path, json_encode($dataset, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

		return $path;
	}

	/**
	 * @return array{int, string, string}
	 */
	private function runCli(string $path, string ...$options): array
	{
		$root = dirname(__DIR__, 2);
		$command = escapeshellarg(PHP_BINARY)
			. ' ' . escapeshellarg($root . '/bin/validate-cutover')
			. ($options === [] ? '' : ' ' . implode(' ', array_map(escapeshellarg(...), $options)))
			. ' ' . escapeshellarg($path);

		$process = proc_open(
			$command,
			[
				1 => ['pipe', 'w'],
				2 => ['pipe', 'w'],
			],
			$pipes,
			$root,
		);
		self::assertIsResource($process);

		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);

		return [$status, (string) $stdout, (string) $stderr];
	}
}
