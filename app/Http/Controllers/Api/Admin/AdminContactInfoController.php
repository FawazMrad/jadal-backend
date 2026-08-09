<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactInfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** V2 / MF_FU §2 — admin-editable support contact, served on login. */
class AdminContactInfoController extends Controller
{
    // ── GET /admin/contact-info ──────────────────────────────────────────────

    public function show(): JsonResponse
    {
        return $this->success(
            ContactInfo::payload(ContactInfo::current()),
            'تم جلب معلومات التواصل. | Contact info retrieved.'
        );
    }

    // ── PUT /admin/contact-info ───────────────────────────────────────────────

    public function update(Request $request): JsonResponse
    {
        // Every field is `sometimes` — an admin editing one channel must not
        // blank the others. Send an explicit null to clear a channel.
        $validated = $request->validate([
            'email'     => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone'     => ['sometimes', 'nullable', 'string', 'max:30'],
            'instagram' => ['sometimes', 'nullable', 'string', 'max:255'],
            'whatsapp'  => ['sometimes', 'nullable', 'string', 'max:30'],
            'website'   => ['sometimes', 'nullable', 'url', 'max:255'],
            'telegram'  => ['sometimes', 'nullable', 'string', 'max:255'],
            'x'         => ['sometimes', 'nullable', 'string', 'max:255'],
            'facebook'  => ['sometimes', 'nullable', 'string', 'max:255'],
            'youtube'   => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $contact = ContactInfo::current();
        if ($contact) {
            $contact->update($validated);
        } else {
            $contact = ContactInfo::create($validated);
        }

        return $this->success(
            ContactInfo::payload($contact->refresh()),
            'تم تحديث معلومات التواصل. | Contact info updated.'
        );
    }
}
