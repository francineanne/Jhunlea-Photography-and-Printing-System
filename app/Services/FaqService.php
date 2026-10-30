<?php

namespace App\Services;

use App\Models\Faq;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one place that adds, changes, removes and moves FAQ items. Every write runs under the settings row lock
 * (ShopSettingsService::locked), so two owners clicking at once can never break the order: positions are always
 * 1..N with no gaps, at most Faq::MAX_ITEMS items exist, and no two questions read the same (case does not count).
 */
class FaqService
{
    public const DUPLICATE_MESSAGE = 'This question is already in the list.';

    public function __construct(private readonly ShopSettingsService $settings) {}

    /** @param  array{question: string, answer: string, is_published: bool}  $data */
    public function create(array $data): Faq
    {
        return $this->settings->locked(function () use ($data) {
            if (Faq::query()->count() >= Faq::MAX_ITEMS) {
                throw ValidationException::withMessages(['question' => 'The FAQ can hold up to '.Faq::MAX_ITEMS.' questions. Delete one to add another.']);
            }

            $this->refuseDuplicate($data['question']);

            $faq = new Faq($data);
            $faq->position = (int) Faq::query()->max('position') + 1;
            $faq->save();

            return $faq;
        });
    }

    /** @param  array{question: string, answer: string, is_published: bool}  $data */
    public function update(Faq $faq, array $data): void
    {
        $this->settings->locked(function () use ($faq, $data) {
            $this->refuseDuplicate($data['question'], $faq->id);
            $faq->fill($data)->save();
        });
    }

    /** Show or hide on the public page. Setting the state it already has changes nothing. */
    public function setPublished(Faq $faq, bool $published): void
    {
        $this->settings->locked(fn () => $faq->forceFill(['is_published' => $published])->save());
    }

    /** @return bool false when it was already gone (a second click on Delete) */
    public function delete(int $id): bool
    {
        return $this->settings->locked(function () use ($id) {
            $deleted = Faq::query()->whereKey($id)->delete() > 0;
            $this->renumber(Faq::query()->ordered()->pluck('id')->all());

            return $deleted;
        });
    }

    /**
     * Moves an item one place up (-1) or down (+1). $from is the position the owner saw: when the item is no longer
     * there (a double click, or a stale page) nothing moves, so one click moves it once. The first item cannot move
     * up and the last cannot move down.
     *
     * @return bool true when it moved
     */
    public function move(int $id, int $direction, int $from): bool
    {
        return $this->settings->locked(function () use ($id, $direction, $from) {
            $ids = Faq::query()->ordered()->pluck('id')->all();
            $index = array_search($id, $ids, true);
            $target = $index === false ? -1 : $index + $direction;

            if ($index === false || $index + 1 !== $from || $target < 0 || $target >= count($ids)) {
                return false;
            }

            [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
            $this->renumber($ids);

            return true;
        });
    }

    /**
     * Writes positions 1..N in the given order. Every row first moves out of the way (positions are unique), then
     * takes its place.
     *
     * @param  list<int>  $ids
     */
    private function renumber(array $ids): void
    {
        $offset = (int) Faq::query()->max('position') + count($ids) + 1;

        foreach ($ids as $i => $id) {
            DB::table('faqs')->where('id', $id)->update(['position' => $offset + $i]);
        }
        foreach ($ids as $i => $id) {
            DB::table('faqs')->where('id', $id)->update(['position' => $i + 1]);
        }
    }

    private function refuseDuplicate(string $question, ?int $except = null): void
    {
        $taken = Faq::query()
            ->when($except, fn ($query, int $id) => $query->whereKeyNot($id))
            ->whereRaw('LOWER(question) = ?', [mb_strtolower($question)])
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['question' => self::DUPLICATE_MESSAGE]);
        }
    }
}
