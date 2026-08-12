<?php

namespace App\Http\Middleware;

use App\Models\ImpersonationLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Jaring pengaman fitur "Login as" (App\Http\Controllers\Web\ImpersonateController): kalau admin
 * lupa klik "Kembali ke admin", sesi impersonate dipaksa berakhir sendiri setelah batas waktu
 * ini. Dipasang global di grup middleware 'web' (bootstrap/app.php) supaya jalan di request
 * manapun (admin/dosen/mahasiswa), bukan hanya rute panel admin.
 */
class EnforceImpersonationTimeout
{
    private const TIMEOUT_MINUTES = 30;

    public function handle(Request $request, Closure $next): Response
    {
        $impersonatorId = $request->session()->get('impersonator_id');
        $startedAt = $request->session()->get('impersonation_started_at');

        if ($impersonatorId && $startedAt && Carbon::parse($startedAt)->addMinutes(self::TIMEOUT_MINUTES)->isPast()) {
            ImpersonationLog::where('id', $request->session()->get('impersonation_log_id'))
                ->whereNull('ended_at')
                ->update(['ended_at' => now()]);

            $request->session()->forget(['impersonator_id', 'impersonation_log_id', 'impersonation_started_at']);

            Auth::loginUsingId($impersonatorId);
            $request->session()->regenerate();

            return redirect()->route('admin.dashboard')
                ->with('status', 'Sesi "Login as" berakhir otomatis setelah '.self::TIMEOUT_MINUTES.' menit.');
        }

        return $next($request);
    }
}
