<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ImpersonationLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "Login as" — admin (superadmin saja, lihat route.admin.superadmin di routes/web.php) menjadi
 * user dosen/mahasiswa tertentu di sesi web yang sama, tanpa memakai kredensial user itu.
 *
 * Bukan Auth::login() polos: id admin asli disimpan terpisah di session ('impersonator_id'),
 * bukan ditimpa, supaya ada jalan kembali (stop()) dan supaya EnsureUserIsSuperadminWeb tidak
 * ikut menghancurkan sesi kalau admin yang sedang menyamar nyasar ke rute superadmin-only. Sesi
 * juga di-regenerate di setiap perpindahan identitas (fixation) dan auto-expire lewat
 * App\Http\Middleware\EnforceImpersonationTimeout.
 *
 * Impersonate bersarang (login-as di atas login-as) tidak mungkin lolos sampai sini: begitu
 * start() sukses, Auth::user() jadi dosen/mahasiswa target, yang otomatis gagal di middleware
 * role.admin.superadmin sebelum request kedua sempat masuk method ini — target impersonate
 * dibatasi hanya dosen/mahasiswa (tidak pernah superadmin), jadi tidak perlu pengecekan eksplisit
 * lagi di sini.
 */
class ImpersonateController extends Controller
{
    public function start(Request $request, int $id): RedirectResponse
    {
        $admin = $request->user();
        abort_unless($admin instanceof User && $admin->isSuperadmin(), 403);

        $target = User::findOrFail($id);

        abort_if($target->id === $admin->id, 422, 'Tidak bisa login as diri sendiri.');
        abort_unless(in_array($target->role, ['dosen', 'mahasiswa'], true), 422, 'Hanya akun dosen atau mahasiswa yang bisa diakses lewat "Login as".');

        $dashboard = $target->webDashboardRouteName();
        abort_if($dashboard === null, 422, 'Akun ini tidak memiliki dashboard web yang bisa dituju.');

        $log = ImpersonationLog::create([
            'id_admin' => $admin->id,
            'id_target_user' => $target->id,
            'ip_address' => $request->ip(),
        ]);

        $request->session()->put('impersonator_id', $admin->id);
        $request->session()->put('impersonation_log_id', $log->id);
        $request->session()->put('impersonation_started_at', now()->toIso8601String());

        Auth::login($target);
        $request->session()->regenerate();

        return redirect()->route($dashboard);
    }

    public function stop(Request $request): RedirectResponse
    {
        $impersonatorId = $request->session()->get('impersonator_id');
        abort_unless($impersonatorId, 404);

        $targetId = Auth::id();

        ImpersonationLog::where('id', $request->session()->get('impersonation_log_id'))
            ->whereNull('ended_at')
            ->update(['ended_at' => now()]);

        $request->session()->forget(['impersonator_id', 'impersonation_log_id', 'impersonation_started_at']);

        Auth::loginUsingId($impersonatorId);
        $request->session()->regenerate();

        return redirect()->route('admin.pengguna.show', $targetId);
    }
}
