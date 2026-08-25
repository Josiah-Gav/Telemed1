<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-white-900 leading-tight">
            {{ __('User Management') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    @if(session('status'))
                        <div class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                            {{ session('status') }}
                        </div>
                    @endif

                    @if(session('warning'))
                        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-medium text-amber-800">
                            {{ session('warning') }}
                        </div>
                    @endif

                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold">Users</h3>
                        <a href="{{ route('admin.users.create') }}" class="rounded-md bg-brand-green px-4 py-2 text-sm font-medium text-white hover:bg-brand-green-deep">Create User</a>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead>
                                <tr>
                                    <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Name</th>
                                    <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Email</th>
                                    <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Role</th>
                                    <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Status</th>
                                    <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Invitation</th>
                                    <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($users as $user)
                                    <tr>
                                        <td class="px-4 py-3 text-sm text-gray-800">{{ $user->first_name }} {{ $user->last_name }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ $user->email }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ ucfirst($user->role) }}</td>
                                        <td class="px-4 py-3 text-sm text-gray-700">{{ ucfirst($user->account_status) }}</td>
                                        @php($invitation = $invitations[$user->user_id] ?? null)
                                        <td class="px-4 py-3 text-sm">
                                            @if($invitation)
                                                <span @class([
                                                    'inline-block rounded-full px-2 py-0.5 text-xs font-medium',
                                                    'bg-emerald-50 text-emerald-800' => $invitation['state'] === 'pending',
                                                    'bg-amber-50 text-amber-800' => $invitation['state'] === 'expired',
                                                    'bg-gray-100 text-gray-700' => $invitation['state'] === 'missing',
                                                ])>{{ $invitation['label'] }}</span>
                                            @else
                                                <span class="text-gray-400">&mdash;</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 text-sm">
                                            <div class="flex items-center gap-3">
                                                <a href="{{ route('admin.users.edit', $user) }}" class="text-brand-green hover:underline">Edit</a>

                                                {{-- Offered only for accounts the activation flow would actually accept. --}}
                                                @if($invitation)
                                                    <form method="POST" action="{{ route('admin.users.resend_invitation', $user) }}">
                                                        @csrf
                                                        <button type="submit" class="text-brand-green hover:underline">
                                                            {{ $invitation['state'] === 'missing' ? 'Send Invitation' : 'Resend Invitation' }}
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
