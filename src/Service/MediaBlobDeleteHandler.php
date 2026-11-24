<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Service;

use AlohaSocial\Core\Jobs\Job;
use AlohaSocial\Core\Jobs\JobHandler;
use AlohaSocial\Core\Jobs\TerminalJobException;
use AlohaSocial\Core\Platform\Storage\BlobPath;
use AlohaSocial\Core\Platform\Storage\BlobStore;
use AlohaSocial\Core\Platform\Storage\StorageException;
use RuntimeException;

/** Retries one idempotent deletion under the media namespace. */
final readonly class MediaBlobDeleteHandler implements JobHandler
{
	public function __construct(private BlobStore $blobs)
	{
	}

	public function handle(Job $job): void
	{
		$path = $job->payload['path'] ?? null;
		if (!is_string($path) || !str_starts_with($path, 'media/')) {
			throw new InvalidMediaBlobDeleteJobException();
		}

		try {
			BlobPath::normalize($path);
		} catch (StorageException) {
			throw new InvalidMediaBlobDeleteJobException();
		}

		$this->blobs->delete($path);
	}
}

final class InvalidMediaBlobDeleteJobException extends RuntimeException implements TerminalJobException
{
	public function __construct()
	{
		parent::__construct('A media blob deletion job needs a valid media path.');
	}

	public function errorCode(): string
	{
		return 'invalid_media_blob_delete_job';
	}
}
