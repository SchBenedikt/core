<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Federation\EmptyInboxResolver;
use AlohaSocial\Core\Domain\Federation\InboxResolver;
use AlohaSocial\Core\Domain\Media\Attachment;
use AlohaSocial\Core\Domain\Media\InMemoryAttachmentRepository;
use AlohaSocial\Core\Domain\Post\InMemoryPostRepository;
use AlohaSocial\Core\Domain\Post\InMemoryPostRevisionRepository;
use AlohaSocial\Core\Domain\Post\PostAccessDeniedException;
use AlohaSocial\Core\Domain\Post\PostConflictException;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Jobs\InMemoryJobQueue;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Service\UpdatePost;
use AlohaSocial\Core\Service\ValidationException;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FixedInboxResolver;
use AlohaSocial\Core\Tests\Support\SequenceIdGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Editing a local post.
 *
 * The rules that only exist for an edit — ownership, the optimistic revision,
 * the audience that must not widen, the snapshot of the replaced version — are
 * asserted here rather than through the kernel, which only maps them to HTTP.
 */
final class PostEditTest extends TestCase
{
	private FrozenClock $clock;

	private InMemoryPostRepository $posts;

	private InMemoryAttachmentRepository $attachments;

	private InMemoryPostRevisionRepository $revisions;

	private InMemoryJobQueue $queue;

	private ArrayConfig $config;

	private ActivityFactory $activities;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$this->posts = new InMemoryPostRepository();
		$this->attachments = new InMemoryAttachmentRepository();
		$this->revisions = new InMemoryPostRevisionRepository();
		$this->queue = new InMemoryJobQueue($this->clock, new SequenceIdGenerator('job-'));
		$this->config = new ArrayConfig([
			'social' => ['instance.url' => 'https://social.example'],
		]);
		$this->activities = new ActivityFactory($this->config);
	}

	public function testTheAuthorEditsTheContentAndTheLiveVersionIsReadBack(): void
	{
		$service = $this->service();
		$post = $service->create(new CreatePost('alice', 'first'));

		$edited = $service->edit('alice', $post->id, new UpdatePost(content: '  second  '));

		self::assertSame('second', $edited->content);
		self::assertSame(2, $edited->revision);
		self::assertNotNull($edited->editedAt);
		self::assertSame('second', $service->get($post->id)->content);
		self::assertSame(2, $service->get($post->id)->revision);
	}

	public function testAnEditLeavesEveryFieldItDoesNotNameAlone(): void
	{
		$service = $this->service();
		$post = $service->create(new CreatePost('alice', 'text', Visibility::Followers, contentWarning: 'cw'));

		$edited = $service->edit('alice', $post->id, new UpdatePost(content: 'new text'));

		self::assertSame('new text', $edited->content);
		self::assertSame('cw', $edited->contentWarning);
		self::assertSame(Visibility::Followers, $edited->visibility);
		self::assertSame($post->publishedAt, $edited->publishedAt);
	}

	public function testClearingTheContentWarningIsItsOwnChange(): void
	{
		$service = $this->service();
		$post = $service->create(new CreatePost('alice', 'text', contentWarning: 'cw'));

		$cleared = $service->edit('alice', $post->id, new UpdatePost(contentWarning: null, contentWarningProvided: true));
		self::assertNull($cleared->contentWarning);

		$blanked = $service->edit('alice', $post->id, new UpdatePost(contentWarning: '   ', contentWarningProvided: true));
		self::assertNull($blanked->contentWarning);
	}

	public function testSomebodyElseCannotEditThePost(): void
	{
		$service = $this->service();
		$post = $service->create(new CreatePost('alice', 'text'));

		$this->expectException(PostAccessDeniedException::class);

		$service->edit('bob', $post->id, new UpdatePost(content: 'hijacked'));
	}

	public function testAnUnknownPostCannotBeEdited(): void
	{
		$this->expectException(PostNotFoundException::class);

		$this->service()->edit('alice', 'missing', new UpdatePost(content: 'text'));
	}

	public function testAStaleRevisionIsRefusedInsteadOfOverwritingTheNewerEdit(): void
	{
		$service = $this->service();
		$post = $service->create(new CreatePost('alice', 'first'));
		$service->edit('alice', $post->id, new UpdatePost(content: 'second'));

		try {
			$service->edit('alice', $post->id, new UpdatePost(content: 'stale', expectedRevision: 1));
			self::fail('Expected a revision conflict.');
		} catch (PostConflictException $exception) {
			self::assertSame($post->id, $exception->postId);
			self::assertSame(2, $exception->revision);
		}

		self::assertSame('second', $service->get($post->id)->content);
	}

	public function testTheMatchingRevisionIsAccepted(): void
	{
		$service = $this->service();
		$post = $service->create(new CreatePost('alice', 'first'));

		$edited = $service->edit('alice', $post->id, new UpdatePost(content: 'second', expectedRevision: 1));

		self::assertSame('second', $edited->content);
	}

	public function testEditingRejectsEmptyContent(): void
	{
		$service = $this->service();
		$post = $service->create(new CreatePost('alice', 'text'));

		try {
			$service->edit('alice', $post->id, new UpdatePost(content: '   '));
			self::fail('Expected a validation error.');
		} catch (ValidationException $exception) {
			self::assertArrayHasKey('content', $exception->fields());
		}

		self::assertSame('text', $service->get($post->id)->content);
	}

	public function testAMediaOnlyEditCanClearTheCaption(): void
	{
		$this->attachments->insert(new Attachment(
			id: 'media-1',
			ownerId: 'alice',
			storageKey: 'blobs/media-1',
			thumbnailKey: 'blobs/media-1-thumb',
			mimeType: 'image/jpeg',
			width: 64,
			height: 64,
			byteSize: 1024,
			altText: 'A cat',
			createdAt: $this->clock->now(),
		));

		$service = $this->service();
		$post = $service->create(new CreatePost('alice', '', attachmentIds: ['media-1']));
		$edited = $service->edit('alice', $post->id, new UpdatePost(content: '   '));

		self::assertSame('', $edited->content);
		self::assertSame(['media-1'], $edited->attachmentIds);
	}

	public function testAnEditCannotRemoveTheLastMediaAndLeaveTheTextEmpty(): void
	{
		$this->attachments->insert(new Attachment(
			id: 'media-1',
			ownerId: 'alice',
			storageKey: 'blobs/media-1',
			thumbnailKey: 'blobs/media-1-thumb',
			mimeType: 'image/jpeg',
			width: 64,
			height: 64,
			byteSize: 1024,
			altText: 'A cat',
			createdAt: $this->clock->now(),
		));

		$service = $this->service();
		$post = $service->create(new CreatePost('alice', '', attachmentIds: ['media-1']));

		try {
			$service->edit('alice', $post->id, new UpdatePost(content: '', attachmentIds: []));
			self::fail('Expected a validation error.');
		} catch (ValidationException $exception) {
			self::assertArrayHasKey('content', $exception->fields());
		}

		self::assertSame(['media-1'], $service->get($post->id)->attachmentIds);
	}

	public function testEditingRejectsContentAboveTheLimit(): void
	{
		$service = $this->service();
		$post = $service->create(new CreatePost('alice', 'text'));

		try {
			$service->edit('alice', $post->id, new UpdatePost(content: str_repeat('a', PostService::MAX_CONTENT_LENGTH + 1)));
			self::fail('Expected a validation error.');
		} catch (ValidationException $exception) {
			self::assertArrayHasKey('content', $exception->fields());
		}
	}

	public function testEditingRejectsAContentWarningAboveTheLimit(): void
	{
		$service = $this->service();
		$post = $service->create(new CreatePost('alice', 'text'));

		try {
			$service->edit('alice', $post->id, new UpdatePost(
				content: 'text',
				contentWarning: str_repeat('w', PostService::MAX_CONTENT_WARNING_LENGTH + 1),
				contentWarningProvided: true,
			));
			self::fail('Expected a validation error.');
		} catch (ValidationException $exception) {
			self::assertArrayHasKey('content_warning', $exception->fields());
		}
	}

	public function testEditingRejectsAnAttachmentTheAuthorDoesNotOwn(): void
	{
		$service = $this->service();
		$post = $service->create(new CreatePost('alice', 'text'));

		try {
			$service->edit('alice', $post->id, new UpdatePost(attachmentIds: ['missing-attachment']));
			self::fail('Expected a validation error.');
		} catch (ValidationException $exception) {
			self::assertArrayHasKey('attachment_ids', $exception->fields());
		}
	}

	public function testAnEditCannotWidenTheAudience(): void
	{
		$service = $this->service();
		$post = $service->create(new CreatePost('alice', 'text', Visibility::Followers));

		try {
			$service->edit('alice', $post->id, new UpdatePost(content: 'text', visibility: Visibility::Public));
			self::fail('Expected a validation error.');
		} catch (ValidationException $exception) {
			self::assertArrayHasKey('visibility', $exception->fields());
		}

		self::assertSame(Visibility::Followers, $service->get($post->id)->visibility);
	}

	public function testTheReplacedVersionIsStoredAsARevision(): void
	{
		$service = $this->service();
		$post = $service->create(new CreatePost('alice', 'first', Visibility::Public, contentWarning: 'cw'));

		$service->edit('alice', $post->id, new UpdatePost(content: 'second', visibility: Visibility::Unlisted));

		$history = $this->revisions->forPost($post->id);
		self::assertCount(1, $history);
		self::assertSame(1, $history[0]->revision);
		self::assertSame('first', $history[0]->content);
		self::assertSame('cw', $history[0]->contentWarning);
		self::assertSame(Visibility::Public, $history[0]->visibility);
		self::assertSame($post->publishedAt, $history[0]->editedAt);
		self::assertSame(1, $this->revisions->countForPost($post->id));
	}

	public function testEveryEditAppendsOneRevision(): void
	{
		$service = $this->service();
		$post = $service->create(new CreatePost('alice', 'one'));

		$service->edit('alice', $post->id, new UpdatePost(content: 'two'));
		$service->edit('alice', $post->id, new UpdatePost(content: 'three'));

		$history = $this->revisions->forPost($post->id);
		self::assertCount(2, $history);
		self::assertSame([1, 2], array_column($history, 'revision'));
		self::assertSame(['one', 'two'], array_column($history, 'content'));
		self::assertSame('three', $service->get($post->id)->content);
	}

	public function testAnEditQueuesOneUpdatePerFollowerInbox(): void
	{
		$service = $this->service(new FixedInboxResolver([
			'https://remote.example/inbox',
			'https://other.example/inbox',
		]));
		$post = $service->create(new CreatePost('alice', 'first'));

		$edited = $service->edit('alice', $post->id, new UpdatePost(content: 'second'));

		$updateJobs = array_values(array_filter(
			$this->queue->pending(),
			static fn ($job): bool => $job->payload['activity']['type'] === 'Update',
		));

		self::assertCount(2, $updateJobs);
		self::assertSame('https://remote.example/inbox', $updateJobs[0]->payload['inbox']);
		self::assertSame('https://other.example/inbox', $updateJobs[1]->payload['inbox']);
		self::assertSame('second', $updateJobs[0]->payload['activity']['object']['content']);
		self::assertNotSame('', $updateJobs[0]->payload['activity']['object']['id']);
	}

	public function testTheUpdateObjectIsSerializedExactlyLikeTheCreatedNote(): void
	{
		$service = $this->service(new FixedInboxResolver(['https://remote.example/inbox']));
		$post = $service->create(new CreatePost('alice', 'first'));
		$edited = $service->edit('alice', $post->id, new UpdatePost(content: 'second'));

		$update = array_values(array_filter(
			$this->queue->pending(),
			static fn ($job): bool => $job->payload['activity']['type'] === 'Update',
		))[0]->payload['activity'];

		$created = $this->activities->createNote($edited);

		// Delivery and outbox share one serializer, so the object an Update
		// carries is byte-for-byte the note a Create would carry.
		self::assertSame($created['object'], $update['object']);
		self::assertSame('Update', $update['type']);
		self::assertSame(Time::format($edited->editedAt), $update['published']);
		self::assertStringEndsWith('/update/' . $edited->revision, $update['id']);
	}

	public function testNoUpdateIsQueuedWithoutFollowerInboxes(): void
	{
		$service = $this->service();
		$post = $service->create(new CreatePost('alice', 'first'));

		$service->edit('alice', $post->id, new UpdatePost(content: 'second'));

		self::assertCount(0, $this->queue->pending());
	}

	public function testNarrowingTheAudienceStaysLocal(): void
	{
		$service = $this->service(new FixedInboxResolver(['https://remote.example/inbox']));
		$post = $service->create(new CreatePost('alice', 'text', Visibility::Public));

		$edited = $service->edit('alice', $post->id, new UpdatePost(content: 'text', visibility: Visibility::Unlisted));

		self::assertSame(Visibility::Unlisted, $edited->visibility);
		self::assertCount(0, array_filter(
			$this->queue->pending(),
			static fn ($job): bool => $job->payload['activity']['type'] === 'Update',
		));
	}

	public function testAnEmptyCommandStillBumpsTheRevision(): void
	{
		$service = $this->service();
		$post = $service->create(new CreatePost('alice', 'text'));

		$edited = $service->edit('alice', $post->id, new UpdatePost());

		self::assertSame('text', $edited->content);
		self::assertSame(2, $edited->revision);
		self::assertCount(1, $this->revisions->forPost($post->id));
	}

	private function service(?InboxResolver $inboxes = null): PostService
	{
		return new PostService(
			$this->posts,
			$this->attachments,
			$this->queue,
			$inboxes ?? new EmptyInboxResolver(),
			$this->activities,
			$this->clock,
			new SequenceIdGenerator('post-'),
			revisions: $this->revisions,
		);
	}
}
