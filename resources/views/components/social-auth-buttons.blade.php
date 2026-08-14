@props(['type' => 'login'])

<div class="space-y-3">
    <a href="{{ route('social.redirect', 'google') }}" 
       class="w-full flex gap-2 items-center justify-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-200"
    >
        <div class="w-5 h-5 mr-2 flex items-center justify-center">
            @include('components.icons.google')
        </div>

        {{ __($type === 'login' 
            ? 'Login with Google' 
            : 'Register with Google'
        ) }}
    </a>

    <a href="{{ route('social.redirect', 'facebook') }}" 
       class="mt-4 w-full flex gap-2 items-center justify-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors duration-200"
    >
        <div class="w-5 h-5 mr-2 flex items-center justify-center">
            @include('components.icons.facebook')
        </div>

        {{ __($type === 'login' 
            ? 'Login with Facebook' 
            : 'Register with Facebook'
        ) }}
    </a>
</div>
