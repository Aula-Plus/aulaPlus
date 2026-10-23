<?php

namespace App\Http\Requests\Concerns;

use App\Enums\CommentSignal;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * The signal per category of a new observation (ClickUp 86e3qkwpn), shared by
 * the student and group comment store requests:
 *
 * - `category_signals`: an object keyed by category id, one
 *   App\Enums\CommentSignal value each — e.g. `{"12": "difficulty",
 *   "15": "no_change"}`. Every id in `category_ids` must have one (an
 *   observation with a category cannot be published without a signal for
 *   each), and no other id may appear. An observation without category needs
 *   none.
 */
trait ValidatesCommentCategorySignals
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function categorySignalRules(): array
    {
        return [
            'category_signals' => ['sometimes', 'nullable', 'array'],
            'category_signals.*' => ['required', Rule::enum(CommentSignal::class)],
        ];
    }

    protected function validateCategorySignals(Validator $validator): void
    {
        if ($validator->errors()->hasAny(['category_ids', 'category_ids.*', 'category_signals', 'category_signals.*'])) {
            return;
        }

        $categoryIds = array_map('intval', (array) ($this->input('category_ids') ?? []));
        $signals = $this->input('category_signals') ?? [];

        foreach (array_keys($signals) as $id) {
            if (! in_array((int) $id, $categoryIds, true)) {
                $validator->errors()->add('category_signals', 'A signal can only be given for one of the observation\'s categories.');

                return;
            }
        }

        foreach ($categoryIds as $id) {
            if (! isset($signals[$id]) && ! isset($signals[(string) $id])) {
                $validator->errors()->add("category_signals.{$id}", 'Choose a signal for every category of the observation.');
            }
        }
    }

    /**
     * The validated categories with their signal, ready for
     * `$comment->categories()->sync()`.
     *
     * @return array<int, array{signal: string}>
     */
    public function categoriesWithSignals(): array
    {
        $signals = $this->validated('category_signals') ?? [];
        $sync = [];

        foreach ($this->validated('category_ids') ?? [] as $id) {
            $sync[(int) $id] = ['signal' => $signals[$id] ?? $signals[(string) $id]];
        }

        return $sync;
    }
}
