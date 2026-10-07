<?php

namespace App\Services\Numbering;

use App\Models\Sequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sequential document numbers (ORD-000001, slip numbers, RET-…, SKUs).
 *
 * next() locks the sequence row FOR UPDATE inside the caller's transaction. If that
 * transaction rolls back (e.g. a failed payment) the increment rolls back too, so slip
 * numbers never have gaps. Numbers only ever move forward.
 */
class SequenceService
{
    public function next(string $key): string
    {
        return DB::transaction(function () use ($key) {
            $sequence = $this->lockedRow($key);
            $number = $sequence->next_number;

            $sequence->next_number = $number + 1;
            $sequence->saveQuietly();

            return $this->format($sequence, $number);
        });
    }

    /**
     * The number the next call to next() will hand out, without consuming it.
     */
    public function peek(string $key): string
    {
        $sequence = $this->find($key);

        return $this->format($sequence, $sequence->next_number);
    }

    public function lastUsedNumber(string $key): int
    {
        return $this->find($key)->next_number - 1;
    }

    public function nextNumber(string $key): int
    {
        return $this->find($key)->next_number;
    }

    public function prefix(string $key): string
    {
        return $this->find($key)->prefix;
    }

    /**
     * Move the counter forward (never backward, never reset).
     *
     * @throws ValidationException
     */
    public function setNextNumber(string $key, int $nextNumber): void
    {
        DB::transaction(function () use ($key, $nextNumber) {
            $sequence = $this->lockedRow($key);

            if ($nextNumber < $sequence->next_number) {
                throw ValidationException::withMessages([
                    'next_number' => sprintf(
                        'The next number cannot be lower than %d (last used: %d). Numbers can only move forward.',
                        $sequence->next_number,
                        $sequence->next_number - 1,
                    ),
                ]);
            }

            $sequence->update(['next_number' => $nextNumber]);
        });
    }

    public function setPrefix(string $key, string $prefix): void
    {
        $this->find($key)->update(['prefix' => trim($prefix)]);
    }

    public function format(Sequence $sequence, int $number): string
    {
        return $sequence->prefix.str_pad((string) $number, $sequence->padding, '0', STR_PAD_LEFT);
    }

    private function find(string $key): Sequence
    {
        return Sequence::query()->where('key', $key)->firstOrFail();
    }

    private function lockedRow(string $key): Sequence
    {
        return Sequence::query()->where('key', $key)->lockForUpdate()->firstOrFail();
    }
}
