<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Platform\Storage;

/**
 * Host-provided S3-compatible operations used by {@see S3BlobStore}.
 *
 * Implementations own endpoint, region, credentials, timeouts and the actual
 * S3 protocol client. They must stream request/response bodies, avoid public
 * ACLs, and clean up any multipart upload after a failed write. Listing must
 * return one bounded page and a continuation token rather than collecting the
 * whole bucket. Server-side encryption is selected by the store and passed to
 * each write; no ACL parameter exists in this contract.
 */
interface S3CompatibleClient
{
	/**
	 * @param resource $body readable stream positioned at the first byte
	 *
	 * The implementation must not close the caller's stream.
	 */
	public function putObject(
		string $bucket,
		string $key,
		mixed $body,
		int $contentLength,
		?string $serverSideEncryption,
		?string $kmsKeyId,
	): void;

	/**
	 * @return resource|null readable body, or null when the key is absent
	 */
	public function getObject(string $bucket, string $key, int $maxBytes): mixed;

	/** Returns the object length, or null when the key is absent. */
	public function headObject(string $bucket, string $key): ?int;

	/** Deleting an absent key must be a successful no-op. */
	public function deleteObject(string $bucket, string $key): void;

	public function listObjects(
		string $bucket,
		string $prefix,
		?string $continuationToken,
		int $maxKeys,
	): S3ObjectPage;
}
