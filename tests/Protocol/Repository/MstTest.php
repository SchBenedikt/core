<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace AlohaSocial\Core\Tests\Protocol\Repository;

use AlohaSocial\Core\Protocol\Atproto\Repository\Bytes;
use AlohaSocial\Core\Protocol\Atproto\Repository\Cid;
use AlohaSocial\Core\Protocol\Atproto\Repository\DagCbor;
use AlohaSocial\Core\Protocol\Atproto\Repository\Mst;
use AlohaSocial\Core\Protocol\Atproto\Repository\MstKey;
use AlohaSocial\Core\Protocol\Atproto\Repository\MstBuilder;
use AlohaSocial\Core\Protocol\Atproto\Repository\MstNode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class MstTest extends TestCase
{
	private const ROOT = 'bafyreibuqr2ixxw7e422tsctkbqctr2m44wtuuprnnhuqpezxop4g52d7q';

	private const NODE_HEX = 'a2616583a4616b58186170702e62736b792e666565642e6c696b652f336b6768696170006174f66176d82a58250001711220f96a83d2f2dfbc68dda3d0a37506696d32801ec490eb6c18e95f2cb41770f124a4616b4a706f73742f336b61626361700e6174f66176d82a58250001711220112da4efd1a03e0ea6b1a68c05e677f3b6a34b79432fc7313d0f6890f5e7a618a4616b436465666170156174f66176d82a58250001711220e79deda100b6f065ccaff33e9a2dd746598cd5fb611d75a956b010628f25ed53616cf6';

	private const LEAVES = [
		'app.bsky.feed.post/3kabc' => 'bafyreiarfwso7unahyhknmngrqc6m57tw2ruw6kdf7dtcpipnciplz5gda',
		'app.bsky.feed.post/3kdef' => 'bafyreihhtxw2cafw6bs4zl7th2nc3v2glggnl63bdv22svvqcbri6jpnkm',
		'app.bsky.feed.like/3kghi' => 'bafyreihznkb5f4w7xrun3i6qun2qm2lngkab5req5nwbr2k7fs2bo4hreq',
	];

	public function testItReadsTheOfficialNodeAndAllLeaves(): void
	{
		$root = Cid::fromString(self::ROOT);
		$node = MstNode::decode($root, hex2bin(self::NODE_HEX));

		self::assertCount(3, $node->entries);
		self::assertSame('app.bsky.feed.like/3kghi', $node->entries[0]->key);
		self::assertSame(self::LEAVES['app.bsky.feed.like/3kghi'], $node->entries[0]->value->toString());

		$mst = new Mst($root, [self::ROOT => hex2bin(self::NODE_HEX)]);
		$all = array_map(static fn (Cid $cid): string => $cid->toString(), $mst->all());
		$expected = self::LEAVES;
		ksort($expected, SORT_STRING);

		self::assertSame($expected, $all);
	}

	public function testItLooksUpALeafAndReturnsNullForAMissingKey(): void
	{
		$root = Cid::fromString(self::ROOT);
		$mst = new Mst($root, [self::ROOT => hex2bin(self::NODE_HEX)]);

		self::assertSame(
			self::LEAVES['app.bsky.feed.post/3kabc'],
			$mst->lookup('app.bsky.feed.post/3kabc')?->toString(),
		);
		self::assertNull($mst->lookup('app.bsky.feed.post/missing'));
	}

	public function testItBuildsTheSameSingleLayerNodeAsTheReferenceImplementation(): void
	{
		$values = [];
		foreach (self::LEAVES as $key => $cid) {
			$values[$key] = Cid::fromString($cid);
		}

		$node = MstNode::forEntries($values);

		self::assertSame(self::ROOT, $node->cid->toString());
		self::assertSame(self::NODE_HEX, bin2hex($node->encode()));
	}

	public function testItBuildsTheOfficialMultiLayerTree(): void
	{
		$values = [
			'app.bsky.feed.post/0' => 'bafyreiaauo6thjgusybkdfri5irrshiyrs32745u5gvf2p37u65vypjlqe',
			'app.bsky.feed.post/1' => 'bafyreifffzxwlzk2botzd5exvh3ajh6fmwxjyjepa4pi4cb7rt6sslqmae',
			'app.bsky.feed.post/2' => 'bafyreifxlw63hhgkcrjvc6k66umkwbgczztpuzy5gqenxdxotr73q7euq4',
			'app.bsky.feed.post/3' => 'bafyreicimwkqlvdcjvmm6jgwwcvo5ifi643ylphaqiul3tnwxuhzvkuaeq',
			'app.bsky.feed.post/4' => 'bafyreiexujnhpa5flxlfg6ui3dw7i45emqocibaw3grrwudituihwxr56y',
			'app.bsky.feed.post/5' => 'bafyreihgbrmeciyng4ezuwarfvj3t6c2javvw7kat3zk3ngnu7jk3kz3ke',
			'app.bsky.feed.post/6' => 'bafyreiczwtyh5ps5ldbinh32pu3kor6xp4jb6gbsojn72ygapi7tgp32f4',
			'app.bsky.feed.post/7' => 'bafyreiggm4konohff6ao7u6e3mpmg3b62y7txsbm2dmsyman3upsmpgp4u',
			'app.bsky.feed.post/8' => 'bafyreicasccvde4kwusbcvraid4bsqlxrzowsxj53vufivysarsshxdu24',
			'app.bsky.feed.post/9' => 'bafyreigcvscboxf47scqksyxhkcccyvecciiq4aljdqkhubranfwitecmu',
			'app.bsky.feed.post/10' => 'bafyreiet43s7yowct7oltaskfrhxhklpm24qbtw4fxqegpq2lh2pfk66aa',
			'app.bsky.feed.post/11' => 'bafyreiab7orpyn2kep33avhormdo7iozepnbal7kwr2wc5xvckno2wf5di',
		];
		$cids = [];
		foreach ($values as $key => $cid) {
			$cids[$key] = Cid::fromString($cid);
		}

		$built = (new MstBuilder())->build($cids);

		self::assertSame(
			'bafyreief4p5igfkypale6nytlylsqtasjgnm2ttop46tb33vxs442cndwm',
			$built['root']->toString(),
		);
		self::assertCount(5, $built['blocks']);
		$all = (new Mst($built['root'], $built['blocks']))->all();
		self::assertCount(12, $all);
		foreach ($cids as $key => $cid) {
			self::assertSame($cid->toString(), $all[$key]?->toString());
		}
	}

	public function testItBuildsTheOfficialEmptyTreeCid(): void
	{
		self::assertSame(
			'bafyreie5737gdxlw5i64vzichcalba3z2v5n6icifvx5xytvske7mr3hpm',
			MstNode::forEntries([])->cid->toString(),
		);
	}

	public function testItRefusesANonCanonicalPrefix(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$first = 'app.bsky.feed.post/3kabc';
		$second = 'app.bsky.feed.post/3kdef';
		$value = Cid::fromString(self::LEAVES[$first]);
		$bytes = DagCbor::encode([
			'l' => null,
			'e' => [
				['p' => 0, 'k' => new Bytes($first), 'v' => $value, 't' => null],
				['p' => 0, 'k' => new Bytes($second), 'v' => $value, 't' => null],
			],
		]);

		MstNode::decode(Cid::forData($bytes), $bytes);
	}

	public function testItRefusesADuplicateKey(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$key = 'app.bsky.feed.post/3kabc';
		$value = Cid::fromString(self::LEAVES[$key]);
		$bytes = DagCbor::encode([
			'l' => null,
			'e' => [
				['p' => 0, 'k' => new Bytes($key), 'v' => $value, 't' => null],
				['p' => strlen($key), 'k' => new Bytes(''), 'v' => $value, 't' => null],
			],
		]);

		MstNode::decode(Cid::forData($bytes), $bytes);
	}

	public function testItRefusesAMissingBlock(): void
	{
		$this->expectException(UnexpectedValueException::class);

		(new Mst(Cid::fromString(self::ROOT), []))->all();
	}

	public function testItRefusesAMixedLayerNode(): void
	{
		$this->expectException(InvalidArgumentException::class);

		$first = 'app.bsky.feed.post/3kabc';
		$second = $first;
		for ($index = 0; $index < 1000; ++$index) {
			$candidate = 'app.bsky.feed.post/' . $index;
			if (MstKey::layer($candidate) !== MstKey::layer($first)) {
				$second = $candidate;
				break;
			}
		}

		$values = [
			$first => Cid::fromString(self::LEAVES[$first]),
			$second => Cid::fromString(self::LEAVES[$first]),
		];

		MstNode::forEntries($values);
	}
}
