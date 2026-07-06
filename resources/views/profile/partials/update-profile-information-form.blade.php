<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            {{ __("Update your account's profile information.") }}
        </p>
    </header>

    <form id="send-verification" method="post" action="{{ route('verification.send') }}">
        @csrf
    </form>

    <form method="post" action="{{ route('profile.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('patch')

        <div>
            <x-input-label for="first_name" :value="__('First Name')" />
            <x-text-input id="first_name" name="first_name" type="text" class="mt-1 block w-full" :value="old('first_name', $user->first_name)" required autofocus autocomplete="first_name" />
            <x-input-error class="mt-2" :messages="$errors->get('first_name')" />
        </div>

        <div>
            <x-input-label for="last_name" :value="__('Last Name')" />
            <x-text-input id="last_name" name="last_name" type="text" class="mt-1 block w-full" :value="old('last_name', $user->last_name)" required autofocus autocomplete="last_name" />
            <x-input-error class="mt-2" :messages="$errors->get('last_name')" />
        </div>

        <div>
            <x-input-label for="clsu_id" :value="__('CLSU ID')" />
            <x-text-input id="clsu_id" name="clsu_id" type="text" class="mt-1 block w-full" :value="old('clsu_id', $user->clsu_id)" required autofocus autocomplete="clsu_id" />
            <x-input-error class="mt-2" :messages="$errors->get('clsu_id')" />
        </div>

        <div>
            <x-input-label for="department" :value="__('Department')" />
            <x-text-input id="department" name="department" type="text" class="mt-1 block w-full" :value="old('department', $user->department)" required autofocus autocomplete="department" />
            <x-input-error class="mt-2" :messages="$errors->get('department')" />
        </div>

        <div>
            <x-input-label for="contact_num" :value="__('Contact Number')" />
            <x-text-input id="contact_num" name="contact_num" type="text" class="mt-1 block w-full" :value="old('contact_num', $user->contact_num)" required autofocus autocomplete="contact_num" />
            <x-input-error class="mt-2" :messages="$errors->get('contact_num')" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            @if (session('status') === 'profile-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-gray-600"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
