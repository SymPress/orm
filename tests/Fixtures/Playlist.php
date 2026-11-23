<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Collection\Collection;
use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\Id;
use SymPress\Orm\Mapping\OneToMany;

#[Entity(table: 'sympress_playlists')]
final class Playlist
{
    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 32)]
        public string $id,
        #[OneToMany(targetEntity: Track::class, mappedBy: 'playlist', orphanRemoval: true)]
        public Collection $tracks = new Collection(),
    ) {
    }
}
