<?php

namespace App\Http\Controllers\Auth;

use App\Enums\ConsentContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ConsentRegistry;
use App\Services\DictionaryOptions;
use App\Services\OwnerRoleProvisioner;
use App\Services\PanelHome;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        $countries = DictionaryOptions::getSortedCountries();
        $documents = (new ConsentRegistry)->requiredPlatformDocuments();

        return view('auth.register', compact('countries', 'documents'));
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        // Wymagane dokumenty Fisherya (049, R3) — każde konto ma od początku zapis akceptacji.
        $consents = new ConsentRegistry;
        $documents = $consents->requiredPlatformDocuments();

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'surname' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Password::defaults()],
            ...ConsentRegistry::rules($documents),
        ], ConsentRegistry::messages($documents));

        $user = DB::transaction(function () use ($request, $consents, $documents): User {
            $user = User::create([
                'name' => $request->name,
                'surname' => $request->surname,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'country_id' => $request->country_id,
            ]);
            $consents->recordDocuments($user, $documents, ConsentContext::Registration, null, $request->ip());

            return $user;
        });

        OwnerRoleProvisioner::addOwnerRole($user);
        event(new Registered($user));

        Auth::login($user);

        return PanelHome::redirectAfterSignIn($user, intended: false);
    }
}
