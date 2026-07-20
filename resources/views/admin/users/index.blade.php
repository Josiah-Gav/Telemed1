<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('User Management') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold">Users</h3>
                        <a href="{{ route('admin.users.create') }}" class="rounded-md bg-clsu-green px-4 py-2 text-sm font-medium text-white">Create User</a>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead>
                                <tr>
                                    <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Name</th>
                                    <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Email</th>
                                    <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Role</th>
                                    <th class="px-4 py-2 text-left text-sm font-semibold text-gray-600">Status</th>
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
                                        <td class="px-4 py-3 text-sm">
                                            <a href="{{ route('admin.users.edit', $user) }}" class="text-blue-600 hover:underline">Edit</a>
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
