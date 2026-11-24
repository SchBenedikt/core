<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Domain\Federation\Block;
use AlohaSocial\Core\Domain\Media\AttachmentPresenter;
use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Exceptions\ConfigurationException;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Support\Time;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ActivityFactoryTest extends TestCase
{
	private ActivityFactory $factory;

	protected function setUp(): void
	{
		$this->factory = new ActivityFactory(new ArrayConfig([
			'social' => ['instance.url' => 'https://social.example/'],
		]));
	}

	public function testDerivesActorAndKeyIds(): void
	{
		self::assertSame('https://social.example', $this->factory->baseUrl());
		self::assertSame('https://social.example/@alice', $this->factory->actorId('alice'));
		self::assertSame('https://social.example/@alice#main-key', $this->factory->keyId('alice'));
		self::assertSame('https://social.example/@alice/followers', $this->factory->followersId('alice'));
	}

	public function testEncodesAnAccountIdInTheActorUrl(): void
	{
		self::assertSame('https://social.example/@a%2Fb', $this->factory->actorId('a/b'));
	}

	public function testNoteIdsAreStable(): void
	{
		$post = $this->post('p1', Visibility::Public);

		self::assertSame($this->factory->noteId($post), $this->factory->noteId($post));
		self::assertSame('https://social.example/api/v1/posts/p1', $this->factory->noteId($post));
	}

	#[DataProvider('audiences')]
	public function testMapsVisibilityOntoAudience(Visibility $visibility, array $to, array $cc): void
	{
		$activity = $this->factory->createNote($this->post('p1', $visibility));

		self::assertSame($to, $activity['to']);
		self::assertSame($cc, $activity['cc']);
		self::assertSame($to, $activity['object']['to']);
		self::assertSame($cc, $activity['object']['cc']);
	}

	/**
	 * @return iterable<string, array{Visibility, list<string>, list<string>}>
	 */
	public static function audiences(): iterable
	{
		$followers = 'https://social.example/@alice/followers';
		$public = ActivityFactory::PUBLIC_COLLECTION;

		yield 'public' => [Visibility::Public, [$public], [$followers]];
		yield 'unlisted' => [Visibility::Unlisted, [$followers], [$public]];
		yield 'followers' => [Visibility::Followers, [$followers], []];
		yield 'direct' => [Visibility::Direct, [], []];
	}

	public function testBuildsACreateWithANote(): void
	{
		$activity = $this->factory->createNote($this->post('p1', Visibility::Public));

		self::assertSame(ActivityFactory::CONTEXT, $activity['@context']);
		self::assertSame('Create', $activity['type']);
		self::assertSame('https://social.example/@alice', $activity['actor']);
		self::assertSame('https://social.example/api/v1/posts/p1/activity', $activity['id']);
		self::assertSame('2026-11-11T10:00:00Z', $activity['published']);
		self::assertSame('Note', $activity['object']['type']);
		self::assertSame('https://social.example/api/v1/posts/p1', $activity['object']['id']);
		self::assertSame('hello', $activity['object']['content']);
		self::assertSame('text/plain', $activity['object']['mediaType']);
		self::assertFalse($activity['object']['sensitive']);
		self::assertArrayNotHasKey('summary', $activity['object']);
		self::assertArrayNotHasKey('attachment', $activity['object']);
	}

	public function testTheNoteCarriesThePresentedAttachments(): void
	{
		$presenter = new class implements AttachmentPresenter {
			public function presentActivityAttachments(Post $post): array
			{
				return [[
					'type' => 'Image',
					'url' => 'https://social.example/api/v1/media/a1',
					'mediaType' => 'image/png',
				]];
			}
		};

		$factory = new ActivityFactory(
			new ArrayConfig(['social' => ['instance.url' => 'https://social.example']]),
			$presenter,
		);

		$activity = $factory->createNote($this->post('p1', Visibility::Public));

		self::assertSame([[
			'type' => 'Image',
			'url' => 'https://social.example/api/v1/media/a1',
			'mediaType' => 'image/png',
		]], $activity['object']['attachment']);
	}

	public function testAContentWarningBecomesSensitiveAndSummary(): void
	{
		$activity = $this->factory->createNote($this->post('p1', Visibility::Public, 'spoilers'));

		self::assertTrue($activity['object']['sensitive']);
		self::assertSame('spoilers', $activity['object']['summary']);
	}

	public function testTheNoteCarriesItsLanguageMap(): void
	{
		$activity = $this->factory->createNote($this->post('p1', Visibility::Public, language: 'de'));

		self::assertSame(['de' => 'hello'], $activity['object']['contentMap']);
	}

	public function testBuildsADeleteWithATombstone(): void
	{
		$post = $this->post('p1', Visibility::Public);

		$activity = $this->factory->deleteNote($post, Time::parse('2026-11-11T11:00:00Z'));

		self::assertSame('Delete', $activity['type']);
		self::assertSame('https://social.example/api/v1/posts/p1/delete', $activity['id']);
		self::assertSame('Tombstone', $activity['object']['type']);
		self::assertSame('https://social.example/api/v1/posts/p1', $activity['object']['id']);
		self::assertSame('2026-11-11T11:00:00Z', $activity['published']);
	}

	public function testDirectAudienceNamesTheLocalRecipients(): void
	{
		$activity = $this->factory->createNote($this->post(
			'p1',
			Visibility::Direct,
			recipientIds: ['bob', 'carol'],
		));

		self::assertSame([
			'https://social.example/@bob',
			'https://social.example/@carol',
		], $activity['to']);
		self::assertSame([], $activity['cc']);
		self::assertSame($activity['to'], $activity['object']['to']);
	}

	public function testAReplyCarriesTheParentPostId(): void
	{
		$activity = $this->factory->createNote($this->post(
			'p1',
			Visibility::Public,
			inReplyToId: 'parent',
		));

		self::assertSame('https://social.example/api/v1/posts/parent', $activity['object']['inReplyTo']);
	}

	public function testBlockAndUndoUseTheActorAsObject(): void
	{
		$block = new Block(
			'b1',
			'alice',
			'https://remote.example/users/bob',
			Time::parse('2026-11-11T10:00:00Z'),
		);

		$activity = $this->factory->blockActor($block);
		self::assertSame('Block', $activity['type']);
		self::assertSame('https://social.example/@alice', $activity['actor']);
		self::assertSame('https://remote.example/users/bob', $activity['object']);
		self::assertSame(['https://remote.example/users/bob'], $activity['to']);
		self::assertSame('https://social.example/api/v1/blocks/b1/block', $activity['id']);

		$undo = $this->factory->undoBlock($block);
		self::assertSame('Undo', $undo['type']);
		self::assertSame($activity, $undo['object']);
		self::assertSame('https://social.example/api/v1/blocks/b1/block/undo', $undo['id']);
	}

	public function testRefusesWithoutAnInstanceUrl(): void
	{
		$factory = new ActivityFactory(new ArrayConfig());

		$this->expectException(ConfigurationException::class);

		$factory->actorId('alice');
	}

	public function testRefusesAnInstanceUrlThatIsNotHttp(): void
	{
		$factory = new ActivityFactory(new ArrayConfig([
			'social' => ['instance.url' => 'ftp://social.example'],
		]));

		$this->expectException(ConfigurationException::class);

		$factory->baseUrl();
	}

	private function post(
		string $id,
		Visibility $visibility,
		?string $contentWarning = null,
		array $recipientIds = [],
		?string $inReplyToId = null,
		?string $language = null,
	): Post {
		return new Post(
			$id,
			'alice',
			'hello',
			$visibility,
			Time::parse('2026-11-11T10:00:00Z'),
			$contentWarning,
			null,
			[],
			null,
			$recipientIds,
			$inReplyToId,
			language: $language,
		);
	}
}
