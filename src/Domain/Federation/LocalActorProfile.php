<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Domain\Federation;

use AlohaSocial\Core\Support\Time;
use DateTimeImmutable;
use InvalidArgumentException;

use function filter_var;
use function in_array;
use function is_string;
use function mb_strlen;
use function parse_url;
use function strtolower;
use function trim;

use const FILTER_VALIDATE_URL;

/**
 * The public profile fields a host can expose for one local account.
 *
 * The core does not own accounts or profile storage. This value object is the
 * complete, already-authorised projection the host chooses to publish; it
 * contains no credentials, group memberships or host user IDs.
 *
 * URLs are absolute http(s) URLs with a bounded length so an actor document
 * cannot turn into a vector for `javascript:` or an oversized response.
 * Profile fields carry no HTML: the ActivityPub value is plain text and the
 * Mastodon projection escapes it separately.
 */
final readonly class LocalActorProfile
{
	public const MAX_NAME_LENGTH = 255;

	public const MAX_SUMMARY_LENGTH = 5000;

	public const MAX_URL_LENGTH = 512;

	public const MAX_ATTACHMENTS = 8;

	public const MAX_FIELDS = 4;

	/**
	 * @param list<string>                   $attachmentUrls
	 * @param list<LocalActorProfileField>   $fields
	 */
	public function __construct(
		public string $userId,
		public string $displayName,
		public string $summary,
		public ?string $avatarUrl,
		public ?string $headerUrl,
		public array $attachmentUrls,
		public array $fields,
		public bool $locked,
		public bool $hideCollections,
		public DateTimeImmutable $updatedAt,
		public ?DateTimeImmutable $createdAt = null,
	) {
		if ($userId === '' || mb_strlen($userId) > self::MAX_NAME_LENGTH) {
			throw new InvalidArgumentException('A local profile needs a bounded user id.');
		}

		if (trim($displayName) === '' || mb_strlen($displayName) > self::MAX_NAME_LENGTH) {
			throw new InvalidArgumentException('A local profile needs a bounded display name.');
		}

		if (mb_strlen($summary) > self::MAX_SUMMARY_LENGTH) {
			throw new InvalidArgumentException('A local profile summary is too long.');
		}

		$this->assertUrl($avatarUrl);
		$this->assertUrl($headerUrl);

		if (count($attachmentUrls) > self::MAX_ATTACHMENTS) {
			throw new InvalidArgumentException('A local profile carries too many attachments.');
		}

		foreach ($attachmentUrls as $url) {
			$this->assertUrl($url, false);
		}

		if (count($fields) > self::MAX_FIELDS) {
			throw new InvalidArgumentException('A local profile carries too many fields.');
		}

		foreach ($fields as $field) {
			if (!$field instanceof LocalActorProfileField) {
				throw new InvalidArgumentException('A local profile field has the wrong type.');
			}
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'user_id' => $this->userId,
			'display_name' => $this->displayName,
			'summary' => $this->summary,
			'avatar' => $this->avatarUrl,
			'header' => $this->headerUrl,
			'attachments' => $this->attachmentUrls,
			'fields' => array_map(
				static fn (LocalActorProfileField $field): array => $field->toArray(),
				$this->fields,
			),
			'locked' => $this->locked,
			'hide_collections' => $this->hideCollections,
			'updated_at' => Time::format($this->updatedAt),
			'created_at' => $this->createdAt === null ? null : Time::format($this->createdAt),
		];
	}

	private function assertUrl(?string $url, bool $allowNull = true): void
	{
		if ($url === null) {
			if ($allowNull) {
				return;
			}

			throw new InvalidArgumentException('A profile attachment needs an absolute URL.');
		}

		$url = trim($url);
		if ($url === '' || mb_strlen($url) > self::MAX_URL_LENGTH) {
			throw new InvalidArgumentException('A profile URL is empty or too long.');
		}

		if (filter_var($url, FILTER_VALIDATE_URL) === false) {
			throw new InvalidArgumentException('A profile URL must be an absolute http(s) URL.');
		}

		$scheme = parse_url($url, PHP_URL_SCHEME);
		$host = parse_url($url, PHP_URL_HOST);
		if (!is_string($scheme)
			|| !in_array(strtolower($scheme), ['http', 'https'], true)
			|| !is_string($host)
			|| $host === ''
		) {
			throw new InvalidArgumentException('A profile URL must be an absolute http(s) URL.');
		}
	}
}
