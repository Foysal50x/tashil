<?php

declare(strict_types=1);

use Foysal50x\Tashil\Contracts\ShouldBeUnique;
use Foysal50x\Tashil\Exceptions\UniqueIdGenerationException;
use Foysal50x\Tashil\Services\Generators\TokenizedIdGenerator;

it('returns the generated id without checking when the generator is not unique-aware', function () {
    $generator = new class extends TokenizedIdGenerator
    {
        protected function prefix(): string
        {
            return 'T';
        }

        protected function format(): string
        {
            return '#-NN';
        }
    };

    expect($generator->generate())->toMatch('/^T-[0-9]{2}$/');
});

it('regenerates until isUnique accepts an id', function () {
    $generator = new class extends TokenizedIdGenerator implements ShouldBeUnique
    {
        /** @var array<int, string> */
        public array $checked = [];

        protected function prefix(): string
        {
            return 'T';
        }

        protected function format(): string
        {
            return '#-NNNNNN';
        }

        public function isUnique(string $id): bool
        {
            $this->checked[] = $id;

            // Reject the first rendered id, accept the second.
            return count($this->checked) > 1;
        }
    };

    $id = $generator->generate();

    expect($generator->checked)->toHaveCount(2)
        ->and($id)->toBe($generator->checked[1]);
});

it('throws after exhausting the attempt budget when no id is ever unique', function () {
    $generator = new class extends TokenizedIdGenerator implements ShouldBeUnique
    {
        public int $calls = 0;

        protected function prefix(): string
        {
            return 'T';
        }

        protected function format(): string
        {
            return '#-NNNNNN';
        }

        protected function maxGenerationAttempts(): int
        {
            return 3;
        }

        public function isUnique(string $id): bool
        {
            $this->calls++;

            return false;
        }
    };

    expect(fn () => $generator->generate())->toThrow(UniqueIdGenerationException::class);
    expect($generator->calls)->toBe(3);
});

it('throws an exception that is still catchable as a RuntimeException', function () {
    $generator = new class extends TokenizedIdGenerator implements ShouldBeUnique
    {
        protected function prefix(): string
        {
            return 'T';
        }

        protected function format(): string
        {
            return '#-NN';
        }

        protected function maxGenerationAttempts(): int
        {
            return 1;
        }

        public function isUnique(string $id): bool
        {
            return false;
        }
    };

    // Dedicated type for precise catches, RuntimeException for back-compat.
    expect(fn () => $generator->generate())->toThrow(RuntimeException::class);
});
