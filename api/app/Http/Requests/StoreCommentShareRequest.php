<?php

namespace App\Http\Requests;

use App\Models\Comment;
use App\Models\CommentShare;
use App\Models\Student;
use App\Models\User;
use App\Policies\CommentSharePolicy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * «Compartir con» (ClickUp 86e3qkwnv): psychopedagogy shares an extract of one
 * observation. Authorization delegates to CommentSharePolicy::create (403 for
 * any other role, or an observation it cannot read). Every recipient must be
 * someone who already sees the student (422 otherwise) — checked in `after()`
 * against the same policy, never trusted from the client.
 */
class StoreCommentShareRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Comment $comment */
        $comment = $this->route('comment');

        return $this->user()->can('create', [CommentShare::class, $comment]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Comment $comment */
        $comment = $this->route('comment');

        return [
            'content' => ['required', 'string', 'max:5000'],
            'recipient_ids' => ['required', 'array', 'min:1', 'max:20'],
            // User is not tenant-scoped, so the school is pinned explicitly.
            'recipient_ids.*' => [
                'integer', 'distinct',
                Rule::exists('users', 'id')->where('school_id', $comment->school_id),
            ],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Comment $comment */
            $comment = $this->route('comment');
            $student = Student::query()->findOrFail($comment->commentable_id);
            $policy = app(CommentSharePolicy::class);

            foreach ((array) $this->input('recipient_ids', []) as $userId) {
                $recipient = User::query()->find((int) $userId);

                if ($recipient === null
                    || $recipient->id === $this->user()->id
                    || ! $policy->canReceive($recipient, $student)) {
                    $validator->errors()->add('recipient_ids', 'One of the selected people cannot see this student.');
                    break;
                }
            }
        }];
    }
}
