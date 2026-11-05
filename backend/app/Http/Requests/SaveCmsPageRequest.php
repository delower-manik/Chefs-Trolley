<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCmsPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');
        $pageId = $this->route('cmsPage')?->id;

        return [
            'slug' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:150', 'alpha_dash', Rule::unique('cms_pages', 'slug')->ignore($pageId)],
            'title' => [$isCreate ? 'required' : 'sometimes', 'string', 'max:150'],
            // Structured typed blocks only -- never raw HTML (02_CLAUDE_DEVELOPMENT_RULES.md §8/§9).
            'sections' => ['nullable', 'array'],
            'sections.*.type' => ['required_with:sections', 'string', 'in:hero,text,image,menu_highlight,gallery,cta'],
            'sections.*.title' => ['nullable', 'string', 'max:200'],
            'sections.*.body' => ['nullable', 'string', 'max:5000'],
            'sections.*.mediaId' => ['nullable', 'uuid', 'exists:media,id'],
            'sections.*.mediaIds' => ['nullable', 'array', 'max:12'],
            'sections.*.mediaIds.*' => ['uuid', 'exists:media,id'],
            'meta_title' => ['nullable', 'string', 'max:150'],
            'meta_description' => ['nullable', 'string', 'max:300'],
            'og_media_id' => ['nullable', 'uuid', 'exists:media,id'],
            'is_published' => ['nullable', 'boolean'],
        ];
    }
}
