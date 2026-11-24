<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol;

use AlohaSocial\Core\Domain\Atproto\AtprotoAccount;
use AlohaSocial\Core\Platform\Clock\FrozenClock;
use AlohaSocial\Core\Platform\Http\HttpClientException;
use AlohaSocial\Core\Platform\Http\HttpResponse;
use AlohaSocial\Core\Protocol\Atproto\AtprotoBlobException;
use AlohaSocial\Core\Protocol\Atproto\AtprotoMedia;
use AlohaSocial\Core\Protocol\Atproto\Es256DpopProofProvider;
use AlohaSocial\Core\Protocol\Atproto\XrpcAtprotoBlobUploader;
use AlohaSocial\Core\Support\Time;
use AlohaSocial\Core\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class XrpcAtprotoBlobUploaderTest extends TestCase
{
	private const BLOB_CID = 'bafkreiblob';

	public function testItUploadsTheBlobAndReturnsTheReference(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse($this->blobResponse([
			'$type' => 'blob',
			'ref' => ['$link' => self::BLOB_CID],
			'mimeType' => 'image/png',
			'size' => 5,
		]));

		$blob = (new XrpcAtprotoBlobUploader($http))->upload(
			$this->account(),
			new AtprotoMedia('image/png', 'bytes'),
		);

		self::assertSame([
			'$type' => 'blob',
			'ref' => ['$link' => self::BLOB_CID],
			'mimeType' => 'image/png',
			'size' => 5,
		], $blob->toArray());

		$request = $http->requests[0];
		self::assertSame('POST', $request['method']);
		self::assertSame('https://pds.example/xrpc/com.atproto.repo.uploadBlob', $request['url']);
		self::assertSame('image/png', $request['headers']['Content-Type']);
		self::assertSame('application/json', $request['headers']['Accept']);
		self::assertSame('Bearer token', $request['headers']['Authorization']);
		self::assertArrayNotHasKey('DPoP', $request['headers']);
		self::assertSame('bytes', $request['body']);
		self::assertSame(20, $request['timeout']);
	}

	public function testTheLocalMetadataFillsInWhenTheResponseOmitsIt(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse($this->blobResponse([
			'ref' => ['$link' => self::BLOB_CID],
		]));

		$blob = (new XrpcAtprotoBlobUploader($http))->upload(
			$this->account(),
			new AtprotoMedia('image/webp', '1234567'),
		);

		self::assertSame('image/webp', $blob->mimeType);
		self::assertSame(7, $blob->size);
	}

	public function testItRejectsResponseMetadataThatChangesTheUploadedMimeType(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse($this->blobResponse([
			'ref' => ['$link' => self::BLOB_CID],
			'mimeType' => 'image/jpeg',
			'size' => 5,
		]));

		try {
			(new XrpcAtprotoBlobUploader($http))->upload(
				$this->account(),
				new AtprotoMedia('image/png', 'bytes'),
			);
			self::fail('Mismatched PDS media metadata must be rejected.');
		} catch (AtprotoBlobException $exception) {
			self::assertSame(AtprotoBlobException::UPLOAD_FAILED, $exception->reason);
		}
	}

	public function testItRejectsResponseMetadataThatChangesTheUploadedSize(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse($this->blobResponse([
			'ref' => ['$link' => self::BLOB_CID],
			'mimeType' => 'image/png',
			'size' => 500,
		]));

		try {
			(new XrpcAtprotoBlobUploader($http))->upload(
				$this->account(),
				new AtprotoMedia('image/png', 'bytes'),
			);
			self::fail('Mismatched PDS media metadata must be rejected.');
		} catch (AtprotoBlobException $exception) {
			self::assertSame(AtprotoBlobException::UPLOAD_FAILED, $exception->reason);
		}
	}

	public function testADpopProviderTurnsTheUploadIntoADpopRequest(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse($this->blobResponse([
			'ref' => ['$link' => self::BLOB_CID],
			'mimeType' => 'video/mp4',
			'size' => 4,
		]));

		(new XrpcAtprotoBlobUploader($http, $this->dpop()))->upload(
			$this->account(),
			new AtprotoMedia('video/mp4', 'data'),
		);

		$headers = $http->requests[0]['headers'];
		self::assertSame('DPoP token', $headers['Authorization']);
		self::assertArrayHasKey('DPoP', $headers);
		self::assertSame('video/mp4', $headers['Content-Type']);
	}

	public function testARefusedUploadIsReported(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(413, [], '{"error":"BlobTooLarge","message":"secret"}'));

		try {
			(new XrpcAtprotoBlobUploader($http))->upload($this->account(), new AtprotoMedia('image/png', 'x'));
			self::fail('The uploader should have failed.');
		} catch (AtprotoBlobException $exception) {
			self::assertSame(AtprotoBlobException::UPLOAD_FAILED, $exception->reason);
			self::assertFalse($exception->retryable);
			self::assertSame(413, $exception->status);
			self::assertStringNotContainsString('secret', $exception->getMessage());
		}
	}

	public function testAServiceUnavailableUploadIsMarkedRetryable(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(503, [], '{"error":"ServiceUnavailable"}'));

		try {
			(new XrpcAtprotoBlobUploader($http))->upload($this->account(), new AtprotoMedia('image/png', 'x'));
			self::fail('A temporary PDS outage should fail this attempt.');
		} catch (AtprotoBlobException $exception) {
			self::assertSame(AtprotoBlobException::UPLOAD_FAILED, $exception->reason);
			self::assertTrue($exception->retryable);
			self::assertSame(503, $exception->status);
		}
	}

	public function testATransportFailureIsReported(): void
	{
		$http = new FakeHttpClient();
		$http->queueTransportFailure(new HttpClientException('offline'));

		$this->expectException(AtprotoBlobException::class);

		try {
			(new XrpcAtprotoBlobUploader($http))->upload($this->account(), new AtprotoMedia('image/png', 'x'));
		} catch (AtprotoBlobException $exception) {
			self::assertSame(AtprotoBlobException::UPLOAD_FAILED, $exception->reason);
			self::assertTrue($exception->retryable);
			self::assertSame(0, $exception->status);

			throw $exception;
		}
	}

	public function testASuccessWithoutABlobReferenceIsReported(): void
	{
		$http = new FakeHttpClient();
		$http->queueResponse(new HttpResponse(200, [], '{"blob":{"ref":{}}}'));

		$this->expectException(AtprotoBlobException::class);

		try {
			(new XrpcAtprotoBlobUploader($http))->upload($this->account(), new AtprotoMedia('image/png', 'x'));
		} catch (AtprotoBlobException $exception) {
			self::assertSame(AtprotoBlobException::MISSING, $exception->reason);

			throw $exception;
		}
	}

	/**
	 * @param array<string, mixed> $blob
	 */
	private function blobResponse(array $blob): HttpResponse
	{
		return new HttpResponse(200, [], json_encode(['blob' => $blob], JSON_THROW_ON_ERROR));
	}

	private function account(): AtprotoAccount
	{
		return new AtprotoAccount('did:plc:alice', 'alice.test', 'https://pds.example', 'token');
	}

	private function dpop(): Es256DpopProofProvider
	{
		$key = openssl_pkey_new([
			'private_key_type' => OPENSSL_KEYTYPE_EC,
			'curve_name' => 'prime256v1',
		]);
		self::assertNotFalse($key);
		self::assertTrue(openssl_pkey_export($key, $privateKey));

		return new Es256DpopProofProvider(
			$privateKey,
			new FrozenClock(Time::parse('2026-11-11T10:00:00Z')),
		);
	}
}
