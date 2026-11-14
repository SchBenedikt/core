<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Protocol\Atproto\AtprotoRecord;
use AlohaSocial\Core\Protocol\Atproto\AtprotoRecordResolver;
use AlohaSocial\Core\Protocol\Atproto\AtprotoReplyBuilder;
use AlohaSocial\Core\Protocol\Atproto\AtprotoReplyException;
use PHPUnit\Framework\TestCase;

final class AtprotoReplyBuilderTest extends TestCase
{
	private const PDS = 'https://pds.example';

	private const ROOT = 'at://did:plc:alice/app.bsky.feed.post/root1';

	private const PARENT = 'at://did:plc:alice/app.bsky.feed.post/parent1';

	public function testAReplyToATopLevelPostUsesItAsRoot(): void
	{
		$records = $this->records([
			self::PARENT => new AtprotoRecord(self::PARENT, 'bafy-parent', ['text' => 'root']),
		]);

		$ref = (new AtprotoReplyBuilder($records))->build(self::PDS, self::PARENT);

		self::assertSame([
			'root' => ['uri' => self::PARENT, 'cid' => 'bafy-parent'],
			'parent' => ['uri' => self::PARENT, 'cid' => 'bafy-parent'],
		], $ref->toArray());
		self::assertSame([self::PARENT], $records->requested);
	}

	public function testAReplyToAReplyInheritsTheRootWithoutFetchingIt(): void
	{
		$records = $this->records([
			self::PARENT => new AtprotoRecord(self::PARENT, 'bafy-parent', [
				'text' => 'a reply',
				'reply' => [
					'root' => ['uri' => self::ROOT, 'cid' => 'bafy-root'],
					'parent' => ['uri' => self::ROOT, 'cid' => 'bafy-root'],
				],
			]),
		]);

		$ref = (new AtprotoReplyBuilder($records))->build(self::PDS, self::PARENT);

		self::assertSame([
			'root' => ['uri' => self::ROOT, 'cid' => 'bafy-root'],
			'parent' => ['uri' => self::PARENT, 'cid' => 'bafy-parent'],
		], $ref->toArray());
		self::assertSame([self::PARENT], $records->requested);
	}

	public function testARootWithoutACidIsLookedUp(): void
	{
		$records = $this->records([
			self::PARENT => new AtprotoRecord(self::PARENT, 'bafy-parent', [
				'reply' => ['root' => ['uri' => self::ROOT]],
			]),
			self::ROOT => new AtprotoRecord(self::ROOT, 'bafy-root', ['text' => 'root']),
		]);

		$ref = (new AtprotoReplyBuilder($records))->build(self::PDS, self::PARENT);

		self::assertSame(['uri' => self::ROOT, 'cid' => 'bafy-root'], $ref->toArray()['root']);
		self::assertSame([self::PARENT, self::ROOT], $records->requested);
	}

	public function testAnUnresolvableParentIsRefused(): void
	{
		$this->expectException(AtprotoReplyException::class);

		try {
			(new AtprotoReplyBuilder($this->records([])))->build(self::PDS, self::PARENT);
		} catch (AtprotoReplyException $exception) {
			self::assertSame(AtprotoReplyException::PARENT_UNRESOLVABLE, $exception->reason);

			throw $exception;
		}
	}

	public function testAnUnresolvableRootIsRefused(): void
	{
		$records = $this->records([
			self::PARENT => new AtprotoRecord(self::PARENT, 'bafy-parent', [
				'reply' => ['root' => ['uri' => self::ROOT]],
			]),
		]);

		$this->expectException(AtprotoReplyException::class);

		try {
			(new AtprotoReplyBuilder($records))->build(self::PDS, self::PARENT);
		} catch (AtprotoReplyException $exception) {
			self::assertSame(AtprotoReplyException::ROOT_UNRESOLVABLE, $exception->reason);

			throw $exception;
		}
	}

	public function testAnInheritedRootThatIsNotAnAtUriIsRefused(): void
	{
		$records = $this->records([
			self::PARENT => new AtprotoRecord(self::PARENT, 'bafy-parent', [
				'reply' => ['root' => ['uri' => 'https://example.com/post', 'cid' => 'bafy-root']],
			]),
		]);

		$this->expectException(AtprotoReplyException::class);

		try {
			(new AtprotoReplyBuilder($records))->build(self::PDS, self::PARENT);
		} catch (AtprotoReplyException $exception) {
			self::assertSame(AtprotoReplyException::ROOT_UNRESOLVABLE, $exception->reason);

			throw $exception;
		}
	}

	/**
	 * @param array<string, AtprotoRecord> $answers
	 */
	private function records(array $answers): AtprotoRecordResolver
	{
		return new class ($answers) implements AtprotoRecordResolver {
			/** @var list<string> */
			public array $requested = [];

			/**
			 * @param array<string, AtprotoRecord> $answers
			 */
			public function __construct(
				private readonly array $answers,
			) {
			}

			public function getRecord(string $pdsUrl, string $uri): ?AtprotoRecord
			{
				$this->requested[] = $uri;

				return $this->answers[$uri] ?? null;
			}
		};
	}
}
