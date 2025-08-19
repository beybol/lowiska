<x-guest-layout>
    <form method="POST" action="{{ route('register') }}">
        @csrf

        <!-- Name -->
        <div>
            <x-input-label 
                for="name" 
                :value="__('Name')" 
                required 
            />

            <x-text-input 
                id="name" 
                class="block mt-1 w-full" 
                type="text" 
                name="name" 
                :value="old('name')" 
                required 
                autofocus 
                autocomplete="name" 
            />

            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>
        <!-- Surname -->
        <div class="mt-4">
            <x-input-label 
                for="surname" 
                :value="__('Surname')" 
                required 
            />

            <x-text-input 
                id="surname" 
                class="block mt-1 w-full" 
                type="text" 
                name="surname" 
                :value="old('surname')" 
                required 
                autofocus 
                autocomplete="surname" 
            />

            <x-input-error :messages="$errors->get('surname')" class="mt-2" />
        </div>
        <!-- Email Address -->
        <div class="mt-4">
            <x-input-label 
                for="email" 
                :value="__('Email')" 
                required 
            />

            <x-text-input 
                id="email" 
                class="block mt-1 w-full" 
                type="email" 
                name="email" 
                :value="old('email')" 
                required 
                autocomplete="username" 
            />

            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>
        <!-- Password -->
        <div class="mt-4">
            <x-input-label 
                for="password" 
                :value="__('Password')" 
                required
            />

            <x-text-input 
                id="password" 
                class="block mt-1 w-full"        
                type="password"
                name="password"
                required 
                autocomplete="new-password"
            />

            <x-input-error 
                :messages="$errors->get('password')" 
                class="mt-2" 
            />
        </div>
        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label 
                for="password_confirmation" 
                :value="__('Confirm password')" 
                required 
            />

            <x-text-input 
                id="password_confirmation" 
                class="block mt-1 w-full"
                type="password"
                name="password_confirmation" 
                required 
                autocomplete="new-password" 
            />

            <x-input-error 
                :messages="$errors->get('password_confirmation')" 
                class="mt-2" 
            />
        </div>
        <!-- Country -->
        <div class="mt-4">
            <x-input-label for="country_id" :value="__('Country prefix')" />
            <select 
                id="country_id" 
                name="country_id" 
                class="block mt-1 w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"
            >
                <option value="">{{ __('Select from list') }}</option>

                @foreach($countries as $country)
                    <option 
                        value="{{ $country->id }}" 
                        {{ old('country_id') == $country->id ? 'selected' : '' }}
                    >
                        {{ __($country->country_name) }}, {{ $country->prefix }}
                    </option>
                @endforeach
            </select>

            <x-input-error 
                :messages="$errors->get('country_id')" 
                class="mt-2" 
            />
        </div>
        <!-- Phone -->
        <div class="mt-4">
            <x-input-label 
                for="phone" 
                :value="__('Phone (without prefix)')" 
            />

            <x-text-input 
                id="phone" 
                class="block mt-1 w-full" 
                type="text" 
                name="phone" 
                :value="old('phone')" 
                autofocus 
                autocomplete="phone" 
            />

            <x-input-error :messages="$errors->get('phone')" class="mt-2" />
        </div>
        <div class="flex items-center justify-end mt-4">
            <a 
                class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" 
                href="{{ route('owner') }}"
            >
                {{ __('Register fishery') }}
            </a>
            <a 
                class="ms-4 underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" 
                href="{{ route('login') }}"
            >
                {{ __('Already registered?') }}
            </a>

            <x-primary-button class="ms-4">
                {{ __('Register') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
