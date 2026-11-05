<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ScopedToBranch;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class SaveCategoryRequest extends FormRequest
{
    use ScopedToBranch;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $category = $this->route('category');

        return [
            'name' => ['required', 'string', 'max:120', function (string $attribute, mixed $value, \Closure $fail) use ($category) {
                $exists = Category::withTrashed()
                    ->where('slug', $this->slugFor((string) $value))
                    ->where('branch_id', $this->targetBranchId($category))
                    ->when($category, fn ($q) => $q->whereKeyNot($category->id))
                    ->exists();

                if ($exists) {
                    $fail('A category with this name already exists.');
                }
            }],
            'description' => ['nullable', 'string', 'max:500'],
            'branch_id' => $this->branchIdRules(false),
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** Slug is derived server-side, never trusted from the client. */
    public function slug(): string
    {
        return $this->slugFor($this->validated('name'));
    }

    /**
     * Str::slug() returns '' for names it cannot transliterate (some Bangla
     * text, emoji-only names), and two such categories would then collide on
     * the (branch_id, slug) unique index -- so fall back to a stable hash.
     */
    private function slugFor(string $name): string
    {
        $slug = Str::slug($name);

        return $slug !== '' ? $slug : 'category-'.substr(md5(mb_strtolower(trim($name))), 0, 10);
    }
}
