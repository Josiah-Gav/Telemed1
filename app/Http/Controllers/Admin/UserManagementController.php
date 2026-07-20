<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    public function index(): View
    {
        $users = User::orderBy('created_at', 'desc')->get();

        return view('admin.users.index', compact('users'));
    }

    public function create(): View
    {
        return view('admin.users.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', 'in:patient,nurse,physician,admin'],
            'account_status' => ['required', 'in:active,inactive'],
            'user_type' => ['nullable', 'string', 'max:50'],
            'department' => ['nullable', 'string', 'max:100'],
            'staff_position' => ['nullable', 'string', 'max:100'],
            'specialization' => ['nullable', 'string', 'max:100'],
        ]);

        $payload = [
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
            'account_status' => $validated['account_status'],
            'user_type' => ! empty($validated['user_type']) ? $validated['user_type'] : 'staff',
            'department' => ! empty($validated['department']) ? $validated['department'] : 'General',
            'staff_position' => ! empty($validated['staff_position']) ? $validated['staff_position'] : null,
            'specialization' => ! empty($validated['specialization']) ? $validated['specialization'] : null,
            'online_status' => 'offline',
            'email_verified_at' => in_array($validated['role'], ['admin', 'nurse', 'physician'], true) ? now() : null,
        ];

        $user = User::create($payload);

        return Redirect::route('admin.users.index')->with('status', 'User created successfully.');
    }

    public function edit(User $user): View
    {
        return view('admin.users.edit', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email,' . $user->getKey() . ',' . $user->getKeyName()],
            'role' => ['required', 'in:patient,nurse,physician,admin'],
            'account_status' => ['required', 'in:active,inactive'],
            'user_type' => ['nullable', 'string', 'max:50'],
            'department' => ['nullable', 'string', 'max:100'],
            'staff_position' => ['nullable', 'string', 'max:100'],
            'specialization' => ['nullable', 'string', 'max:100'],
        ]);

        $user->fill($validated);

        if (empty($user->email_verified_at) && in_array($user->role, ['admin', 'nurse', 'physician'], true)) {
            $user->email_verified_at = now();
        }

        $user->save();

        return Redirect::route('admin.users.index')->with('status', 'User updated successfully.');
    }
}
