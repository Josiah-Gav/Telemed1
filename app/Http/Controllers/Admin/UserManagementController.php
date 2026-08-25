<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Illuminate\View\View;

class UserManagementController extends Controller
{
    /**
     * Roles that are provisioned by invitation rather than by the admin
     * choosing a password. Kept in step with StaffInvitationController.
     */
    private const INVITED_ROLES = ['nurse', 'physician'];

    private function authorizeAdmin(): void
    {
        if (Auth::user()->role !== 'admin') {
            abort(403, 'Unauthorized access.');
        }
    }

    public function index(): View
    {
        $this->authorizeAdmin();

        $users = User::orderBy('created_at', 'desc')->get();

        return view('admin.users.index', compact('users'));
    }

    public function create(): View
    {
        $this->authorizeAdmin();

        return view('admin.users.create');
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin();

        // The submitted role decides which path applies; there is no separate
        // flag a caller could use to opt into the invitation path.
        $invited = in_array($request->input('role'), self::INVITED_ROLES, true);

        $rules = [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'role' => ['required', 'in:patient,nurse,physician,admin'],
            'clsu_id' => ['nullable', 'string', 'max:50'],
            'user_type' => ['nullable', 'in:student,staff,faculty'],
            'department' => ['nullable', 'string', 'max:100'],
            'contact_num' => ['nullable', 'string', 'max:20'],
            'staff_position' => ['nullable', 'string', 'max:100'],
            'specialization' => ['nullable', 'string', 'max:100'],
        ];

        if ($invited) {
            // Invited staff always start inactive; an admin may not pre-activate
            // an account whose owner has not set a password yet.
            $rules['account_status'] = ['nullable', 'in:inactive'];
        } else {
            $rules['password'] = ['required', 'string', 'min:8', 'confirmed'];
            $rules['account_status'] = ['required', 'in:active,inactive'];
        }

        $validated = $request->validate($rules);

        $payload = [
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'clsu_id' => ! empty($validated['clsu_id']) ? $validated['clsu_id'] : null,
            'user_type' => ! empty($validated['user_type']) ? $validated['user_type'] : 'staff',
            'department' => ! empty($validated['department']) ? $validated['department'] : 'General',
            'contact_num' => ! empty($validated['contact_num']) ? $validated['contact_num'] : null,
            'staff_position' => ! empty($validated['staff_position']) ? $validated['staff_position'] : null,
            'specialization' => ! empty($validated['specialization']) ? $validated['specialization'] : null,
            'online_status' => 'offline',
        ];

        if ($invited) {
            $payload += [
                // Not a temporary password: a discarded random value that nobody
                // ever learns, because the column is NOT NULL. Login is blocked
                // by account_status anyway, and activation overwrites this.
                'password' => Hash::make(Str::random(64)),
                'account_status' => 'inactive',
                'email_verified_at' => null,
            ];
        } else {
            $payload += [
                'password' => Hash::make($validated['password']),
                'account_status' => $validated['account_status'],
                'email_verified_at' => $validated['role'] === 'admin' ? now() : null,
            ];
        }

        // The account and its invitation are written together: if the token
        // insert fails the user must not be left behind unable to activate.
        DB::transaction(function () use ($payload, $invited): void {
            $user = User::create($payload);

            if ($invited) {
                Password::broker('staff_invitations')->createToken($user);
            }
        });

        return Redirect::route('admin.users.index')->with(
            'status',
            $invited
                ? 'Staff account created. An activation invitation valid for 7 days has been generated.'
                : 'User created successfully.'
        );
    }

    public function edit(User $user): View
    {
        $this->authorizeAdmin();

        return view('admin.users.edit', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email,'.$user->getKey().','.$user->getKeyName()],
            'role' => ['required', 'in:patient,nurse,physician,admin'],
            'account_status' => ['required', 'in:active,inactive'],
            'user_type' => ['nullable', 'string', 'max:50'],
            'department' => ['nullable', 'string', 'max:100'],
            'staff_position' => ['nullable', 'string', 'max:100'],
            'specialization' => ['nullable', 'string', 'max:100'],
        ]);

        $user->fill($validated);

        if (empty($user->email_verified_at) && $user->role === 'admin') {
            $user->email_verified_at = now();
        }

        $user->save();

        return Redirect::route('admin.users.index')->with('status', 'User updated successfully.');
    }
}
