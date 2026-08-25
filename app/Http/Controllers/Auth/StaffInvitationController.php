<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Activation of admin-provisioned nurse and physician accounts.
 *
 * The invitation token is issued and validated by Laravel's password broker
 * (see the 'staff_invitations' broker in config/auth.php). The broker knows
 * nothing about account_status, so eligibility is enforced here.
 */
class StaffInvitationController extends Controller
{
    /**
     * Every rejection returns this same message. Distinguishing "no such
     * account" from "already active" would turn the endpoint into an
     * account-status oracle.
     */
    private const REJECTION = 'This invitation link is invalid, has expired, or has already been used.';

    /**
     * Display the activation form for a valid, unconsumed invitation.
     */
    public function create(Request $request, string $token): View|RedirectResponse
    {
        $user = $this->invitee($request->query('email'), $token);

        if (! $user instanceof User) {
            return redirect()->route('login')->withErrors(['email' => __(self::REJECTION)]);
        }

        return view('auth.activate-staff-account', [
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Activate the account: set the chosen password, mark it active and
     * verified, and consume the invitation.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // The broker deletes the token *after* the callback returns, so both
        // writes are wrapped together: a failure anywhere leaves the account
        // untouched and the invitation still usable.
        $status = DB::transaction(fn () => $this->broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request): void {
                // Reached only once the token has already proven valid, so an
                // ineligible account is never revealed to a random guesser.
                if (! $this->isEligible($user)) {
                    $this->reject();
                }

                $user->forceFill([
                    'password' => Hash::make($request->string('password')->toString()),
                    'account_status' => 'active',
                    'email_verified_at' => now(),
                    'remember_token' => Str::random(60),
                ])->save();
            }
        ));

        if ($status !== Password::PASSWORD_RESET) {
            $this->reject();
        }

        return redirect()->route('login')->with('status', __('Your account is now active. You can sign in with your new password.'));
    }

    /**
     * Resolve the user an invitation belongs to, but only when the token is
     * valid and the account is still eligible to be activated.
     */
    private function invitee(?string $email, string $token): ?User
    {
        if (blank($email)) {
            return null;
        }

        $user = $this->broker()->getUser(['email' => $email]);

        if (! $user instanceof User || ! $this->isEligible($user)) {
            return null;
        }

        return $this->broker()->tokenExists($user, $token) ? $user : null;
    }

    /**
     * Only an inactive nurse or physician may be activated. This is what stops
     * an invitation from resurrecting a suspended account or re-activating a
     * live one, and it deliberately never consults the submitted role.
     */
    private function isEligible(User $user): bool
    {
        return $user->awaitsStaffActivation();
    }

    /**
     * @throws ValidationException
     */
    private function reject(): never
    {
        throw ValidationException::withMessages(['email' => __(self::REJECTION)]);
    }

    private function broker(): PasswordBroker
    {
        return Password::broker('staff_invitations');
    }
}
