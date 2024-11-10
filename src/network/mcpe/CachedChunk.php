<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe;

use BadFunctionCallException;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\protocol\LevelChunkPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\types\ChunkPosition;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\utils\BinaryStream;
use function chr;
use function count;
use function strlen;

class CachedChunk{
	/** @var int[] */
	protected array $hashes = [];
	/** @var string[] */
	protected array $blobs = [];

	protected string $biomes;
	protected int $biomeHash;

	protected ?string $cachablePacket = null;
	protected ?string $packet = null;

	public function addSubChunk(int $hash, string $blob) : void{
		$this->hashes[] = $hash;
		$this->blobs[] = $blob;
	}

	public function setBiomes(int $hash, string $biomes) : void{
		$this->biomes = $biomes;
		$this->biomeHash = $hash;
	}

	/**
	 * @return int[]
	 */
	private function getHashes() : array{
		$hashes = $this->hashes;
		$hashes[] = $this->biomeHash;

		return $hashes;
	}

	/**
	 * @return string[]
	 */
	public function getHashMap() : array{
		$map = [];

		foreach($this->hashes as $id => $hash){
			$map[$hash] = $this->blobs[$id];
		}
		$map[$this->biomeHash] = $this->biomes;

		return $map;
	}

	/**
	 * @phpstan-param DimensionIds::* $dimensionId
	 */
	public function compressPackets(int $chunkX, int $chunkZ, int $dimensionId, string $chunkData, Compressor $compressor, int $protocolId) : void{
		$protocolAddition = $protocolId >= ProtocolInfo::PROTOCOL_1_20_80 ? chr($compressor->getNetworkId()) : '';
		$stream = new BinaryStream();
		PacketBatch::encodePackets($stream, $protocolId, [$this->createPacket($chunkX, $chunkZ, $dimensionId, $chunkData)]);
		$this->packet = $protocolAddition . $compressor->compress($stream->getBuffer());

		$stream = new BinaryStream();
		PacketBatch::encodePackets($stream, $protocolId, [$this->createCachablePacket($chunkX, $chunkZ, $dimensionId, $chunkData)]);
		$this->cachablePacket = $protocolAddition . $compressor->compress($stream->getBuffer());
	}

	public function getCacheablePacket() : string{
		if($this->cachablePacket === null){
			throw new BadFunctionCallException("Tried to get cacheable packet before it was compressed");
		}

		return $this->cachablePacket;
	}

	public function getPacket() : string{
		if($this->packet === null){
			throw new BadFunctionCallException("Tried to get cacheable packet before it was compressed");
		}

		return $this->packet;
	}

	/**
	 * @phpstan-param DimensionIds::* $dimensionId
	 */
	private function createPacket(int $chunkX, int $chunkZ, int $dimensionId, string $chunkData) : LevelChunkPacket{
		$stream = new BinaryStream();

		foreach($this->blobs as $subChunk){
			$stream->put($subChunk);
		}
		$stream->put($this->biomes);
		$stream->put($chunkData);

		return LevelChunkPacket::create(
			new ChunkPosition($chunkX, $chunkZ),
			$dimensionId,
			count($this->hashes),
			false,
			null,
			$stream->getBuffer()
		);
	}

	/**
	 * @phpstan-param DimensionIds::* $dimensionId
	 */
	private function createCachablePacket(int $chunkX, int $chunkZ, int $dimensionId, string $chunkData) : LevelChunkPacket{
		return LevelChunkPacket::create(
			new ChunkPosition($chunkX, $chunkZ),
			$dimensionId,
			count($this->hashes),
			false,
			$this->getHashes(),
			$chunkData
		);
	}

	public function getSize() : int{
		$size = 0;

		foreach($this->getHashMap() as $blob){
			$size += strlen($blob);
		}
		$size += strlen($this->packet ?? "");
		$size += strlen($this->cachablePacket ?? "");

		return $size;
	}
}
