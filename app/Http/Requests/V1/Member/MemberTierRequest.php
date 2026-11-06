<?php

namespace App\Http\Requests\V1\Member;

use App\Http\Requests\BaseFormRequest;
use App\Models\MemberTier;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validates the tier create and edit forms (Phase 18, P18-B6).
 *
 * One class for both: every field means the same thing on a new tier and on an
 * existing one, so the only difference is which row the uniqueness checks have
 * to ignore. Splitting it would duplicate seven rules and let them drift.
 */
class MemberTierRequest extends BaseFormRequest
{
    /**
     * One class for both forms, two permissions: writing a new tier is
     * `member_tiers.create` and editing an existing one is
     * `member_tiers.update` — the same pair the routes carry, so a clerk who
     * may price a new tier cannot silently rewrite a live one.
     */
    public function authorize(): bool
    {
        $ability = $this->isMethod('post') ? 'member_tiers.create' : 'member_tiers.update';

        return $this->user()?->can($ability) ?? false;
    }

    /**
     * The slug is derived, never typed: the form renders it disabled, so a
     * disabled input (which submits nothing) is not a missing field here. Running
     * the generator through the rules below rather than around them keeps the
     * uniqueness check on the value actually written — two names that slugify
     * alike are refused instead of reaching the unique index.
     *
     * Regenerated on every save, so renaming a tier moves its slug with it.
     */
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');

        $this->merge([
            'slug' => is_string($name) ? Str::slug($name) : '',
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // Uniqueness runs against the raw table, so it counts trashed rows —
        // which is what the database's unique index does too. `withoutTrashed()`
        // would be a check that passes a form the INSERT then rejects with a
        // query exception.
        //
        // `ignore()` only on the update path. `ignore(null)` compiles to
        // `id != NULL`, which matches no row at all, so calling it on create
        // would turn the uniqueness check into a form that always passes.
        $name = Rule::unique('member_tiers', 'name');
        $slug = Rule::unique('member_tiers', 'slug');

        if (($tier = $this->route('tier')) !== null) {
            $name = $name->ignore($tier);
            $slug = $slug->ignore($tier);
        }

        return [
            'name' => ['required', 'string', 'max:255', $name],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', $slug],
            // The set the picker offers, from the same source: a payload that
            // names `superadmin` is refused here rather than quietly applied by a
            // subscription nobody watched.
            'role_name' => ['nullable', Rule::in(array_keys(MemberTier::assignableRoleOptions()))],
            // Bounds are the column widths. Beyond them MySQL rounds the value
            // into a different number than the one typed, and a price is not
            // somewhere worth discovering that by looking at an invoice.
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'duration_days' => ['required', 'integer', 'min:1', 'max:65535'],
            'point_multiplier' => ['required', 'numeric', 'min:0', 'max:99.99'],
            'is_active' => ['boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role_name.in' => 'That role cannot be attached to a tier.',
            // Both slug errors are about the name, and the name is the only field
            // the operator can still type in — the slug box is disabled.
            'slug.required' => 'That name has no letters or numbers to build a slug from.',
            'slug.unique' => 'That name slugs to one another tier already uses.',
        ];
    }
}
