<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\UpdateRun;
use App\Services\Update\ArchiveUpdater;
use App\Services\Update\InstallationInspector;
use App\Services\Update\LocalChangeDetector;
use App\Services\Update\ReleaseChecker;
use App\Services\Update\UpdateRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use Throwable;

/**
 * Wizard pembaruan satu klik, di area superadmin web — tempat yang sama dengan editor .env dan
 * halaman migrasi, karena sifatnya sama: perkakas pemeliharaan yang mengubah instalasi.
 *
 * Sengaja BUKAN Livewire. Setiap langkah adalah form POST biasa, sehingga proses tetap berjalan
 * tanpa JavaScript dan rutenya bisa dikecualikan dari mode pemeliharaan satu per satu — kalau
 * lewat Livewire, yang harus dikecualikan adalah /livewire/update, yang berarti SELURUH halaman
 * Livewire ikut hidup selama pemeliharaan.
 */
class SuperadminUpdateController extends Controller
{
    public function index(
        ReleaseChecker $checker,
        InstallationInspector $inspector,
        LocalChangeDetector $detector,
    ): View {
        $run = UpdateRun::latest('id')->first();
        $check = $checker->latest();

        return view('superadmin.pembaruan', [
            'run' => $run && $run->isRunning() ? $run : null,
            'lastRun' => $run,
            'release' => $check['release'],
            'error' => $check['error'],
            'installed' => (string) config('sikampus.version'),
            'inspector' => $inspector,
            'changes' => $detector->detect(),
        ]);
    }

    public function start(Request $request, ReleaseChecker $checker, InstallationInspector $inspector): RedirectResponse
    {
        $validated = $request->validate([
            // Jalur ditentukan server dari preflight, bukan diterima apa adanya dari form; nilai
            // ini hanya menyatakan bahwa pengguna memang menekan tombol untuk jalur itu.
            'confirm' => ['required', 'accepted'],
        ], [
            'confirm.accepted' => 'Centang konfirmasi backup database terlebih dahulu.',
        ]);

        if (UpdateRun::where('status', UpdateRun::STATUS_RUNNING)->exists()) {
            return $this->back('Masih ada pembaruan yang berjalan. Selesaikan atau batalkan dulu.');
        }

        $release = $checker->latest()['release'];

        if (! $release) {
            return $this->back('Informasi rilis tidak tersedia saat ini.');
        }

        if ($inspector->type() === InstallationInspector::TYPE_MANAGED) {
            return $this->back('Instalasi ini dikelola Sikampus Cloud; pembaruan dijalankan dari portal.');
        }

        if (! $inspector->isFullyWritable()) {
            return $this->back('Direktori aplikasi tidak bisa ditulis oleh PHP, sehingga pembaruan otomatis tidak dapat dijalankan.');
        }

        // Jalur Git dipakai kalau instalasi memang klon Git DAN ketiga binary tersedia; kalau
        // tidak, jatuh ke jalur arsip. Lihat InstallationInspector::canUseGitPath().
        $path = $inspector->canUseGitPath() ? UpdateRun::PATH_GIT : UpdateRun::PATH_ARCHIVE;

        $run = UpdateRun::create([
            'version_from' => (string) config('sikampus.version'),
            'version_to' => $release->version,
            'path' => $path,
            'status' => UpdateRun::STATUS_RUNNING,
            'step' => UpdateRun::STEPS[$path][0],
            'id_user' => $request->user()?->id,
            'started_at' => now(),
        ]);

        $run->appendLog("Pembaruan dimulai: {$run->version_from} → {$run->version_to} (jalur {$path}).");

        return redirect()->route('superadmin.pembaruan');
    }

    public function step(UpdateRunner $runner): RedirectResponse
    {
        $run = UpdateRun::where('status', UpdateRun::STATUS_RUNNING)->latest('id')->first();

        if (! $run) {
            return $this->back('Tidak ada pembaruan yang sedang berjalan.');
        }

        try {
            $completed = $runner->performNext($run);
        } catch (Throwable $e) {
            report($e);

            return $this->back('Langkah "'.$run->step.'" gagal: '.$e->getMessage());
        }

        return redirect()->route('superadmin.pembaruan')
            ->with('status', 'Langkah "'.$completed.'" selesai.');
    }

    /**
     * Membatalkan hanya boleh SEBELUM berkas hidup mulai diubah. Setelah itu, "batal" berarti
     * meninggalkan instalasi setengah tertukar — jalan keluarnya menyelesaikan pembaruan atau
     * memulihkan dari backup, bukan berhenti di tengah.
     */
    public function cancel(ArchiveUpdater $archive): RedirectResponse
    {
        $run = UpdateRun::where('status', UpdateRun::STATUS_RUNNING)->latest('id')->first();

        if (! $run) {
            return $this->back('Tidak ada pembaruan yang sedang berjalan.');
        }

        if ($run->hasStartedMutating()) {
            return $this->back('Pembaruan sudah melewati tahap penggantian berkas dan tidak bisa dibatalkan dari sini.');
        }

        $archive->cleanup($run);

        $run->update([
            'status' => UpdateRun::STATUS_FAILED,
            'error_message' => 'Dibatalkan oleh superadmin.',
            'finished_at' => now(),
        ]);
        $run->appendLog('Dibatalkan oleh superadmin; berkas kerja dibersihkan.');

        return redirect()->route('superadmin.pembaruan')->with('status', 'Pembaruan dibatalkan.');
    }

    /**
     * Angkat mode pemeliharaan yang tertinggal menyala setelah pembaruan gagal. Disediakan
     * lewat tombol karena jalan keluar satu-satunya kalau tidak ada tombolnya adalah menghapus
     * storage/framework/down lewat shell — yang belum tentu dimiliki penggunanya.
     */
    public function lift(): RedirectResponse
    {
        if (! File::exists(storage_path('framework/down'))) {
            return $this->back('Aplikasi tidak sedang dalam mode pemeliharaan.');
        }

        Artisan::call('up');

        return redirect()->route('superadmin.pembaruan')->with('status', 'Mode pemeliharaan dimatikan.');
    }

    private function back(string $message): RedirectResponse
    {
        return redirect()->route('superadmin.pembaruan')->with('error', $message);
    }
}
