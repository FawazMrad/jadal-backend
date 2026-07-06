<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactInfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** V2 — admin-editable support contact (email/phone/instagram), served on login. */
class AdminContactInfoController extends Controller
{
    // ── GET /admin/contact-info ──────────────────────────────────────────────

    public function show(): JsonResponse
    {
        $contact = ContactInfo::current();

        return $this->success([
            'email'     => $contact?->email,
            'phone'     => $contact?->phone,
            'instagram' => $contact?->instagram,
        ], 'تم جلب معلومات التواصل. | Contact info retrieved.');
    }

    // ── PUT /admin/contact-info ───────────────────────────────────────────────

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'     => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone'     => ['sometimes', 'nullable', 'string', 'max:30'],
            'instagram' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $contact = ContactInfo::current();
        if ($contact) {
            $contact->update($validated);
        } else {
            $contact = ContactInfo::create($validated);
        }

        return $this->success([
            'email'     => $contact->email,
            'phone'     => $contact->phone,
            'instagram' => $contact->instagram,
        ], 'تم تحديث معلومات التواصل. | Contact info updated.');
    }
}
