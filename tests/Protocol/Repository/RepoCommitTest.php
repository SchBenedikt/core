<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol\Repository;

use AlohaSocial\Core\Protocol\Atproto\Repository\Cid;
use AlohaSocial\Core\Protocol\Atproto\Repository\RepoCommit;
use PHPUnit\Framework\TestCase;

final class RepoCommitTest extends TestCase
{
	private const COMMIT_HEX = 'a66364696478206469643a706c633a6162636465666768696a6b6c6d6e6f707172737475767778637265766d336b7a7a7a7a7a7a7a7a7a7a7a6373696758400cda75b10b34383add9f2811013e8dff30cd3113630e51fbe2b9626539c6669d036c45c391bce03e7d433ad46cb0c410acc33260a6658ee94d789ca975557dd66464617461d82a582500017112203484748bdedf2735a9c853506029c74ce72d3a51f16b4f483c99bb9fc37743fc6470726576f66776657273696f6e03';

	private const DATA = 'bafyreibuqr2ixxw7e422tsctkbqctr2m44wtuuprnnhuqpezxop4g52d7q';

	public function testItDecodesAndVerifiesTheOfficialSignedCommit(): void
	{
		$commit = RepoCommit::decode(hex2bin(self::COMMIT_HEX));

		self::assertSame('did:plc:abcdefghijklmnopqrstuvwx', $commit->did);
		self::assertSame('3kzzzzzzzzzzz', $commit->rev);
		self::assertNull($commit->previous);
		self::assertSame(self::DATA, $commit->data->toString());
		self::assertSame(
			'bafyreifwkqy46q76sh3wj5xbgwqevsih5t7ldaspcjhwpys2p3qmyym5yy',
			$commit->cid()->toString(),
		);
		self::assertSame(
			'a56364696478206469643a706c633a6162636465666768696a6b6c6d6e6f707172737475767778637265766d336b7a7a7a7a7a7a7a7a7a7a7a6464617461d82a582500017112203484748bdedf2735a9c853506029c74ce72d3a51f16b4f483c99bb9fc37743fc6470726576f66776657273696f6e03',
			bin2hex($commit->unsignedBytes()),
		);
		self::assertTrue($commit->verify($this->publicKey()));
	}

	public function testItSignsAndVerifiesItsOwnCommit(): void
	{
		$commit = RepoCommit::sign(
			'did:plc:abcdefghijklmnopqrstuvwx',
			'3kzzzzzzzzzzz',
			null,
			Cid::fromString(self::DATA),
			$this->privateKey(),
		);

		self::assertSame(64, strlen($commit->signature ?? ''));
		self::assertTrue($commit->verify($this->publicKey()));
		self::assertSame($commit->cid()->toString(), RepoCommit::decode($commit->bytes())->cid()->toString());
	}

	public function testItRejectsATamperedCommit(): void
	{
		$commit = RepoCommit::decode(hex2bin(self::COMMIT_HEX));
		$tampered = new RepoCommit(
			$commit->did,
			$commit->rev,
			$commit->previous,
			Cid::fromString('bafyreie5737gdxlw5i64vzichcalba3z2v5n6icifvx5xytvske7mr3hpm'),
			$commit->signature,
		);

		self::assertFalse($tampered->verify($this->publicKey()));
	}

	public function testItRejectsAnInvalidSignatureLength(): void
	{
		$this->expectException(\InvalidArgumentException::class);

		new RepoCommit(
			'did:plc:abcdefghijklmnopqrstuvwx',
			'3kzzzzzzzzzzz',
			null,
			Cid::fromString(self::DATA),
			'short',
		);
	}

	private function privateKey(): string
	{
		return "-----BEGIN PRIVATE KEY-----\n"
			. "MIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgLdK9cCU/fDBgba+2\n"
			. "mnLqQQ8zYGAsucMRoXRuOfVAmAGhRANCAASekWqm5rNEOd8D7HTOSVyVBZ4Nz0Z2\n"
			. "nZ7wobh+LbpaUFmGIEKT4CTb5KJzwnxdyhCjt+o+RP76jNcxyIm8ZLBg\n"
			. "-----END PRIVATE KEY-----\n";
	}

	private function publicKey(): string
	{
		return "-----BEGIN PUBLIC KEY-----\n"
			. "MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEnpFqpuazRDnfA+x0zklclQWeDc9G\n"
			. "dp2e8KG4fi26WlBZhiBCk+Ak2+Sic8J8XcoQo7fqPkT++ozXMciJvGSwYA==\n"
			. "-----END PUBLIC KEY-----\n";
	}
}
