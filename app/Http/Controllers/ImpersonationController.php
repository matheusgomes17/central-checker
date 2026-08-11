<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\ImpersonationException;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    public function store(Request $request, User $user, Impersonation $impersonation): RedirectResponse
    {
        $impersonator = Auth::user();

        abort_unless($impersonator instanceof User, 403);

        try {
            $impersonation->start($impersonator, $user);
        } catch (ImpersonationException $e) {
            return back()->withErrors(['impersonation' => $e->getMessage()]);
        }

        $request->session()->regenerate();
        $request->session()->put(Impersonation::SESSION_KEY, $impersonator->id);

        return to_route('dashboard');
    }

    public function destroy(Request $request, Impersonation $impersonation): RedirectResponse
    {
        try {
            $impersonation->stop();
        } catch (ImpersonationException $e) {
            return back()->withErrors(['impersonation' => $e->getMessage()]);
        }

        return to_route('dashboard');
    }
}
