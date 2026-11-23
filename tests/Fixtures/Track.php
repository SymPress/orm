<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\Id;
use SymPress\Orm\Mapping\JoinColumn;
use SymPress\Orm\Mapping\ManyToOne;

#[Entity(table: 'sympress_tracks')]
final class Track
{
    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 32)]
        public string $id,
        #[ManyToOne(targetEntity: Playlist::class, inversedBy: 'tracks')]
        #[JoinColumn(name: 'playlist_id', referencedColumnName: 'id', nullable: false)]
        public Playlist $playlist,
    ) {
    }
}
