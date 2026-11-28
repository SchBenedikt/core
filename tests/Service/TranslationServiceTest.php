<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Service;

use AlohaSocial\Core\Domain\Federation\EmptyInboxResolver;
use AlohaSocial\Core\Domain\Media\InMemoryAttachmentRepository;
use AlohaSocial\Core\Domain\Post\InMemoryPostRepository;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Domain\Translation\TranslationProvider;
use AlohaSocial\Core\Jobs\InMemoryJobQueue;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Config\ArrayConfig;
use AlohaSocial\Core\Protocol\ActivityPub\ActivityFactory;
use AlohaSocial\Core\Service\CreatePost;
use AlohaSocial\Core\Service\PostService;
use AlohaSocial\Core\Service\Translation\TranslationFailedException;
use AlohaSocial\Core\Service\Translation\TranslationService;
use AlohaSocial\Core\Service\Translation\TranslationUnavailableException;
use AlohaSocial\Core\Service\Translation\UnsupportedTranslationLanguageException;
use AlohaSocial\Core\Service\UpdatePost;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Support\RandomIdGenerator;
use PHPUnit\Framework\TestCase;

final class TranslationServiceTest extends TestCase
{
	private InMemoryPostRepository $posts;

	private PostService $postService;

	private FrozenClock $clock;

	protected function setUp(): void
	{
		$this->clock = new FrozenClock(Time::parse('2026-11-11T10:00:00Z'));
		$this->posts = new InMemoryPostRepository();
		$config = new ArrayConfig(['social' => ['instance.url' => 'https://social.example']]);
		$this->postService = new PostService(
			$this->posts,
			new InMemoryAttachmentRepository(),
			new InMemoryJobQueue($this->clock, new RandomIdGenerator()),
			new EmptyInboxResolver(),
			new ActivityFactory($config),
			$this->clock,
			new RandomIdGenerator(),
		);
	}

	public function testWithoutProviderTranslationIsUnavailable(): void
	{
		$service = new TranslationService($this->postService);
		$post = $this->postService->create(new CreatePost('alice', 'hello'));

		self::assertFalse($service->enabled());
		self::assertSame([], $service->languages());
		$this->expectException(TranslationUnavailableException::class);
		$service->translate($post->id, 'de', null);
	}

	public function testProviderWithNoLanguagesCountsAsUnavailable(): void
	{
		$service = new TranslationService($this->postService, new FakeTranslationProvider([]));
		$post = $this->postService->create(new CreatePost('alice', 'hello'));

		self::assertFalse($service->enabled());
		$this->expectException(TranslationUnavailableException::class);
		$service->translate($post->id, 'de', null);
	}

	public function testTranslatesVisiblePostAndPassesDeclaredLanguage(): void
	{
		$provider = new FakeTranslationProvider();
		$service = new TranslationService($this->postService, $provider);
		$post = $this->postService->create(new CreatePost('alice', 'hallo welt', language: 'de'));

		$result = $service->translate($post->id, 'en', null);

		self::assertSame(['content' => '[en] hallo welt', 'language' => 'en'], $result);
		self::assertSame([['hallo welt', 'de', 'en']], $provider->calls);
		self::assertSame([['code' => 'de', 'name' => 'Deutsch'], ['code' => 'en', 'name' => 'English']], $service->languages());
	}

	public function testUnsupportedTargetLanguageIsRefused(): void
	{
		$service = new TranslationService($this->postService, new FakeTranslationProvider());
		$post = $this->postService->create(new CreatePost('alice', 'hello'));

		$this->expectException(UnsupportedTranslationLanguageException::class);
		$service->translate($post->id, 'fr', null);
	}

	public function testInvisiblePostIsNotFoundBeforeLanguageValidation(): void
	{
		$provider = new FakeTranslationProvider();
		$service = new TranslationService($this->postService, $provider);
		$post = $this->postService->create(new CreatePost(
			'alice',
			'secret',
			visibility: Visibility::Followers,
		));

		// An unsupported language must not be reported for a post the viewer
		// cannot read; the refusal is the same 404-shaped not-found either way.
		try {
			$service->translate($post->id, 'fr', 'bob');
			self::fail('Expected PostNotFoundException.');
		} catch (PostNotFoundException) {
		}
		self::assertSame([], $provider->calls);
	}

	public function testDeletedPostCannotBeTranslated(): void
	{
		$service = new TranslationService($this->postService, new FakeTranslationProvider());
		$post = $this->postService->create(new CreatePost('alice', 'hello'));
		$this->postService->delete('alice', $post->id);

		$this->expectException(PostNotFoundException::class);
		$service->translate($post->id, 'de', null);
	}

	public function testProviderFailureIsReported(): void
	{
		$service = new TranslationService($this->postService, new FakeTranslationProvider(failOn: 'de'));
		$post = $this->postService->create(new CreatePost('alice', 'hello'));

		$this->expectException(TranslationFailedException::class);
		$service->translate($post->id, 'de', null);
	}

	public function testCacheIsKeyedByRevision(): void
	{
		$provider = new FakeTranslationProvider();
		$service = new TranslationService($this->postService, $provider);
		$post = $this->postService->create(new CreatePost('alice', 'hallo'));

		$service->translate($post->id, 'en', null);
		$service->translate($post->id, 'en', null);
		self::assertCount(1, $provider->calls);

		$this->postService->edit('alice', $post->id, new UpdatePost(content: 'hallo edited', expectedRevision: 1));
		$service->translate($post->id, 'en', null);
		self::assertCount(2, $provider->calls);
		self::assertSame('hallo edited', $provider->calls[1][0]);
	}
}

final class FakeTranslationProvider implements TranslationProvider
{
	/** @var list<array{string, string|null, string}> */
	public array $calls = [];

	/**
	 * @param list<array{code: string, name: string}> $languages
	 */
	public function __construct(
		private readonly array $languages = [
			['code' => 'de', 'name' => 'Deutsch'],
			['code' => 'en', 'name' => 'English'],
		],
		private readonly ?string $failOn = null,
	) {
	}

	public function languages(): array
	{
		return $this->languages;
	}

	public function translate(string $text, ?string $sourceLanguage, string $targetLanguage): ?string
	{
		$this->calls[] = [$text, $sourceLanguage, $targetLanguage];
		if ($targetLanguage === $this->failOn) {
			return null;
		}

		return '[' . $targetLanguage . '] ' . $text;
	}
}
