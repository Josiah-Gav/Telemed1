{{--
    Wraps a password <input> (passed as the default slot) with a show/hide
    toggle button placed beside the field, not layered on top of it. The
    slotted input must bind its own type to this component's Alpine scope —
    :type="show ? 'text' : 'password'".

    A slot-based wrapper rather than an input-rendering component because its
    two callers (x-text-input's password branch, and
    profile/partials/update-password-form.blade.php) use different, non-
    mergeable base classes — indigo-themed auth inputs vs brand-green-themed
    profile inputs — and forcing both through one component's default class
    string would produce conflicting Tailwind utilities (e.g. border-gray-300
    and border-slate-300 both applied). This lets each caller keep its own
    classes untouched while sharing the toggle behavior and icons once.
--}}
<div x-data="{ show: false }" class="flex items-center gap-2">
    <div class="min-w-0 flex-1">
        {{ $slot }}
    </div>
    <button
        type="button"
        @click="show = !show"
        :aria-label="show ? 'Hide password' : 'Show password'"
        :aria-pressed="show ? 'true' : 'false'"
        class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-lg border border-gray-300 text-slate-400 transition hover:border-slate-400 hover:text-slate-600"
    >
        <svg x-show="!show" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
        </svg>
        <svg x-show="show" x-cloak xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="h-5 w-5" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
        </svg>
    </button>
</div>
