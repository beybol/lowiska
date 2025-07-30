<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Weryfikacja dwuetapowa</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen">
    <div 
        class="w-full max-w-md p-10 bg-white shadow-xl rounded-2xl space-y-6"
    >
        <h1 class="text-3xl font-bold text-center text-gray-800">
            {{ __('Enter verification code') }}
        </h1>

        <div 
            class="bg-blue-50 border border-blue-200 text-blue-800 text-sm p-4 rounded-lg text-center"
        >
            {{ __('We have sent a verification code to your email address') }}:
            <br>
            <strong>{{ auth()->user()->email }}</strong>
        </div>

        @if (session('status'))
            <div 
                class="bg-green-100 border border-green-200 text-green-800 text-sm p-4 rounded-lg text-center"
            >
                {{ session('status') }}
            </div>
        @endif

        @if ($errors->any())
            <div 
                class="bg-red-100 border border-red-200 text-red-800 text-sm p-4 rounded-lg"
            >
                <ul class="list-disc pl-5 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('verify.store') }}" class="space-y-6">
            @csrf

            <div>
                <label 
                    for="two_factor_code" 
                    class="block text-sm font-medium text-gray-700 mb-2"
                >
                    {{ __('Verification code') }}
                </label>
                <input
                    id="two_factor_code"
                    name="two_factor_code"
                    type="text"
                    inputmode="numeric"
                    pattern="[0-9]*"
                    required
                    autofocus
                    class="w-full px-4 py-3 rounded-md border border-gray-300 shadow-sm focus:border-amber-500 focus:ring-amber-500 text-lg"
                >
            </div>

            <button
                type="submit"
                class="w-full py-3 px-4 rounded-md text-white font-medium text-sm bg-amber-600 hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-amber-500 transition cursor-pointer"
            >
                {{ __('Verify') }}
            </button>
        </form>

        <form 
            method="POST" 
            action="{{ route('verify.resend') }}" 
            class="text-center mt-6"
        >
            @csrf

            <button
                type="submit"
                class="text-sm text-gray-600 hover:text-amber-600 underline cursor-pointer"
            >
                {{ __('Resend verification code') }}
            </button>
        </form>
    </div>
</body>
</html>
