<?php

namespace App\Http\Requests\Account;

use App\Services\AvatarService;

/**
 * Phase 10C: one picture file. The type and size are checked by AvatarService through UploadCheck (extension AND the
 * MIME type read from the file); this only makes sure a single file arrived.
 */
class UpdateAvatarRequest extends AccountRequest
{
    protected $errorBag = 'avatar';

    protected string $section = 'profile';

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['avatar' => ['required', 'file', 'max:'.(AvatarService::MAX_MB * 1024)]];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'avatar.required' => 'Choose a picture first.',
            'avatar.file' => 'Choose a JPG, PNG or WEBP picture.',
            'avatar.uploaded' => 'The picture did not upload. It may be larger than '.AvatarService::MAX_MB.' MB.',
            'avatar.max' => 'The picture is larger than '.AvatarService::MAX_MB.' MB. Please choose a smaller one.',
        ];
    }
}
