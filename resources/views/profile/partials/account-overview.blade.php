@php
    $fullName = trim($user->first_name.' '.$user->last_name) ?: 'Unnamed user';
    $initials = strtoupper(mb_substr($user->first_name ?? '', 0, 1).mb_substr($user->last_name ?? '', 0, 1)) ?: '?';

    // Colour-coded per role so the badge itself carries meaning at a glance,
    // matching the brand palette used across the dashboards (brand green for
    // the clinical roles, gold for admin, sky for the patient-facing role).
    $roleStyles = [
        'patient' => 'bg-sky-100 text-sky-800',
        'nurse' => 'bg-violet-100 text-violet-800',
        'physician' => 'bg-brand-green-soft text-brand-green-deep',
        'admin' => 'bg-brand-gold-soft text-amber-800',
    ];
    $roleClasses = $roleStyles[$user->role] ?? 'bg-slate-100 text-slate-700';

    $statusStyles = [
        'active' => ['label' => 'Active', 'classes' => 'bg-brand-green text-white', 'dot' => 'bg-white'],
        'inactive' => ['label' => 'Inactive', 'classes' => 'bg-slate-100 text-slate-600', 'dot' => 'bg-slate-400'],
        'suspended' => ['label' => 'Suspended', 'classes' => 'bg-red-100 text-red-800', 'dot' => 'bg-red-500'],
    ];
    $status = $statusStyles[$user->account_status] ?? ['label' => ucfirst($user->account_status), 'classes' => 'bg-slate-100 text-slate-600', 'dot' => 'bg-slate-400'];

    $isClinicalStaff = in_array($user->role, ['nurse', 'physician'], true);
@endphp

<section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
    <div class="flex flex-col items-center text-center">
        <div class="flex h-20 w-20 items-center justify-center rounded-full bg-brand-green text-2xl font-semibold text-white" aria-hidden="true">
            {{ $initials }}
        </div>
        <h3 class="mt-3 text-lg font-semibold text-slate-900">{{ $fullName }}</h3>
        <div class="mt-2 flex flex-wrap items-center justify-center gap-2">
            <span class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold {{ $roleClasses }}">
                {{ ucfirst($user->role) }}
            </span>
            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold {{ $status['classes'] }}">
                <span class="h-1.5 w-1.5 rounded-full {{ $status['dot'] }}" aria-hidden="true"></span>
                {{ $status['label'] }}
            </span>
        </div>
    </div>

    <dl class="mt-6 space-y-4 border-t border-slate-100 pt-4">
        <div>
            <dt class="text-xs font-medium text-slate-500">Email Address</dt>
            <dd class="mt-1 flex items-center gap-1.5 break-all text-sm font-semibold text-slate-900">
                {{ $user->email }}
            </dd>
            <dd class="mt-1 text-xs {{ $user->email_verified_at ? 'text-brand-green' : 'text-amber-600' }}">
                {{ $user->email_verified_at ? 'Verified' : 'Not verified' }}
            </dd>
        </div>

        @if($isClinicalStaff)
            <div>
                <dt class="text-xs font-medium text-slate-500">Staff Position</dt>
                <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $user->staff_position ?: 'Not set' }}</dd>
            </div>

            <div>
                <dt class="text-xs font-medium text-slate-500">Specialization</dt>
                <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $user->specialization ?: 'Not set' }}</dd>
            </div>
        @endif

        <div>
            <dt class="text-xs font-medium text-slate-500">Member Since</dt>
            <dd class="mt-1 text-sm font-semibold text-slate-900">{{ optional($user->created_at)->format('M d, Y') }}</dd>
        </div>
    </dl>

    <p class="mt-5 rounded-xl bg-slate-50 px-3 py-2.5 text-xs leading-5 text-slate-500">
        Email, role{{ $isClinicalStaff ? ', staff position, and specialization' : '' }} are managed by an administrator and can't be changed here.
    </p>
</section>
