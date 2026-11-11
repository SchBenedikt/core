<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Protocol\ActivityPub;

use AlohaSocial\Core\Domain\Post\Post;
use AlohaSocial\Core\Domain\Post\Visibility;
use AlohaSocial\Core\Exceptions\ConfigurationException;
use AlohaSocial\Core\Platform\Config\Config;
use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;

use function is_string;

/**
 * Builds the ActivityStreams documents this instance sends.
 *
 * The ids are derived from the instance URL and the post id, so the same post
 * always produces the same document id — which is what makes a retried
 * delivery idempotent for the receiving server.
 */
final class ActivityFactory
{
	public const CONTEXT = 'https://www.w3.org/ns/activitystreams';

	public const PUBLIC_COLLECTION = 'https://www.w3.org/ns/activitystreams#Public';

	public const CONFIG_APP = 'social';

	public const CONFIG_URL = 'instance.url';

	public function __construct(
		private readonly Config $config,
	) {
	}

	/**
	 * @throws ConfigurationException when the instance URL is missing or not http(s)
	 */
	public function baseUrl(): string
	{
		$url = $this->config->get(self::CONFIG_APP, self::CONFIG_URL);
		if (!is_string($url) || trim($url) === '') {
			throw new ConfigurationException(
				'The instance URL is not configured (' . self::CONFIG_APP . '/' . self::CONFIG_URL . ').',
			);
		}

		$url = rtrim(trim($url), '/');
		$scheme = parse_url($url, PHP_URL_SCHEME);
		if ($scheme !== 'https' && $scheme !== 'http') {
			throw new ConfigurationException('The instance URL must be an absolute http(s) URL: ' . $url);
		}

		return $url;
	}

	public function actorId(string $userId): string
	{
		return $this->baseUrl() . '/@' . rawurlencode($userId);
	}

	public function keyId(string $userId): string
	{
		return $this->actorId($userId) . '#main-key';
	}

	public function followersId(string $userId): string
	{
		return $this->actorId($userId) . '/followers';
	}

	public function noteId(Post $post): string
	{
		return $this->baseUrl() . '/api/v1/posts/' . rawurlencode($post->id);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function createNote(Post $post): array
	{
		$actor = $this->actorId($post->authorId);
		$noteId = $this->noteId($post);
		$audience = $this->audience($post);

		$note = [
			'id' => $noteId,
			'type' => 'Note',
			'attributedTo' => $actor,
			// Plain text for now: the core does not render HTML, and claiming
			// text/html over an unescaped string would be a cross-server XSS.
			'content' => $post->content,
			'mediaType' => 'text/plain',
			'published' => Time::format($post->publishedAt),
			'to' => $audience['to'],
			'cc' => $audience['cc'],
			'sensitive' => $post->contentWarning !== null,
		];

		if ($post->contentWarning !== null) {
			$note['summary'] = $post->contentWarning;
		}

		return [
			'@context' => self::CONTEXT,
			'id' => $noteId . '/activity',
			'type' => 'Create',
			'actor' => $actor,
			'published' => Time::format($post->publishedAt),
			'to' => $audience['to'],
			'cc' => $audience['cc'],
			'object' => $note,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function deleteNote(Post $post, DateTimeImmutable $at): array
	{
		$audience = $this->audience($post);
		$noteId = $this->noteId($post);

		return [
			'@context' => self::CONTEXT,
			'id' => $noteId . '/delete',
			'type' => 'Delete',
			'actor' => $this->actorId($post->authorId),
			'published' => Time::format($at),
			'to' => $audience['to'],
			'cc' => $audience['cc'],
			'object' => [
				'id' => $noteId,
				'type' => 'Tombstone',
			],
		];
	}

	/**
	 * @return array{to: list<string>, cc: list<string>}
	 */
	private function audience(Post $post): array
	{
		$followers = $this->followersId($post->authorId);

		return match ($post->visibility) {
			Visibility::Public => [
				'to' => [self::PUBLIC_COLLECTION],
				'cc' => [$followers],
			],
			Visibility::Unlisted => [
				'to' => [$followers],
				'cc' => [self::PUBLIC_COLLECTION],
			],
			Visibility::Followers => [
				'to' => [$followers],
				'cc' => [],
			],
			Visibility::Direct => [
				'to' => [],
				'cc' => [],
			],
		};
	}
}
