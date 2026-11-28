<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service\Translation;

use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\PostNotFoundException;
use AlohaSocial\Core\Domain\Translation\TranslationProvider;
use AlohaSocial\Core\Service\PostService;

/**
 * Read-side translation for local posts behind the Mastodon-compatible
 * translate endpoints.
 *
 * Authorization reuses {@see PostService::getVisible()}, so a viewer only ever
 * reaches the provider with text they could already read, and the cache key
 * carries the post revision so an edit can never serve a stale translation.
 * The cache is process-local and bounded; hosts that need a durable cache can
 * sit in front of the provider instead.
 */
final class TranslationService
{
	private const CACHE_LIMIT = 500;

	/** @var array<string, array{content: string, language: string}> */
	private array $cache = [];

	public function __construct(
		private readonly PostService $posts,
		private readonly ?TranslationProvider $provider = null,
	) {
	}

	/**
	 * @return list<array{code: string, name: string}>
	 */
	public function languages(): array
	{
		return $this->provider()?->languages() ?? [];
	}

	public function enabled(): bool
	{
		return $this->provider() !== null;
	}

	/**
	 * @return array{content: string, language: string}
	 *
	 * @throws TranslationUnavailableException when no provider is configured
	 * @throws UnsupportedTranslationLanguageException for a language the provider does not advertise
	 * @throws TranslationFailedException when the provider cannot translate
	 * @throws PostNotFoundException when the post is missing, deleted or not visible to the viewer
	 */
	public function translate(string $postId, string $targetLanguage, ?string $viewerId): array
	{
		$provider = $this->provider()
			?? throw new TranslationUnavailableException();

		// Visibility is resolved before the language check so an unauthorized
		// viewer cannot probe which languages the provider supports.
		$post = $this->posts->getVisible($postId, $viewerId);

		$supported = false;
		foreach ($provider->languages() as $language) {
			if ($language['code'] === $targetLanguage) {
				$supported = true;
				break;
			}
		}
		if (!$supported) {
			throw new UnsupportedTranslationLanguageException($targetLanguage);
		}

		return $this->cached($post, $targetLanguage, static function () use ($provider, $post, $targetLanguage): ?array {
			$content = $provider->translate($post->content, $post->language, $targetLanguage);
			if ($content === null) {
				return null;
			}

			return ['content' => $content, 'language' => $targetLanguage];
		});
	}

	/**
	 * @param callable(): (array{content: string, language: string}|null) $produce
	 * @return array{content: string, language: string}
	 */
	private function cached(Post $post, string $targetLanguage, callable $produce): array
	{
		$key = $post->id . ':' . $post->revision . ':' . $targetLanguage;
		if (isset($this->cache[$key])) {
			return $this->cache[$key];
		}

		$result = $produce() ?? throw new TranslationFailedException();
		if (count($this->cache) >= self::CACHE_LIMIT) {
			array_shift($this->cache);
		}
		$this->cache[$key] = $result;

		return $result;
	}

	private function provider(): ?TranslationProvider
	{
		$provider = $this->provider;
		if ($provider === null || $provider->languages() === []) {
			return null;
		}

		return $provider;
	}
}
