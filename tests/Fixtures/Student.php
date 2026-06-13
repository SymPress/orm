<?php

declare(strict_types=1);

namespace SymPress\Orm\Tests\Fixtures;

use SymPress\Orm\Collection\Collection;
use SymPress\Orm\Mapping\Column;
use SymPress\Orm\Mapping\Entity;
use SymPress\Orm\Mapping\Id;
use SymPress\Orm\Mapping\InverseJoinColumn;
use SymPress\Orm\Mapping\JoinColumn;
use SymPress\Orm\Mapping\JoinTable;
use SymPress\Orm\Mapping\ManyToMany;

#[Entity(table: 'sympress_students')]
final class Student
{
    public function __construct(
        #[Id]
        #[Column(type: 'string', length: 32)]
        public string $id,
        #[Column(type: 'string', length: 100)]
        public string $name,
        #[ManyToMany(targetEntity: Course::class)]
        #[JoinTable(
            name: 'sympress_student_courses',
            joinColumns: [new JoinColumn(name: 'student_id', referencedColumnName: 'id', nullable: false)],
            inverseJoinColumns: [new InverseJoinColumn(name: 'course_id', referencedColumnName: 'id', nullable: false)],
        )]
        public Collection $courses = new Collection(),
    ) {
    }
}
