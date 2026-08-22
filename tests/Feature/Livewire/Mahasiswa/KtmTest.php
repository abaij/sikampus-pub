<?php

use App\Livewire\Mahasiswa\Ktm as KtmLivewire;
use App\Models\Ktm;
use App\Models\Mahasiswa;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function ktmMahasiswaUser(): array
{
    $user = User::factory()->create(['role' => 'mahasiswa']);
    $mahasiswa = Mahasiswa::factory()->create(['id_user' => $user->id]);

    return [$user, $mahasiswa];
}

/**
 * Sama dengan seedKtmTemplate() di tests/Feature/Livewire/Admin/KtmTest.php — didefinisikan
 * ulang di sini (bukan di-share) karena kedua file test berjalan sebagai fungsi global Pest
 * yang independen.
 */
function seedKtmTemplateForMahasiswa(): void
{
    $image = imagecreatetruecolor(200, 114);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    ob_start();
    imagepng($image);
    $contents = ob_get_clean();
    imagedestroy($image);

    $path = 'ktm/templates/test-template.png';
    Storage::disk('public')->put($path, $contents);

    Setting::create([
        'key' => 'ktm_template',
        'value' => $path,
        'description' => 'Template gambar KTM (admin)',
        'order' => 0,
    ]);
}

beforeEach(function () {
    Storage::fake('public');
});

it('redirects unauthenticated users to the login page', function () {
    $this->get(route('mahasiswa.ktm'))->assertRedirect(route('login'));
});

it('shows a buat ktm prompt when the mahasiswa has no ktm yet', function () {
    [$user] = ktmMahasiswaUser();

    $this->actingAs($user)->get(route('mahasiswa.ktm'))
        ->assertOk()
        ->assertSee('belum memiliki KTM digital')
        ->assertSee('Buat KTM');
});

it('lets a mahasiswa create their own ktm', function () {
    seedKtmTemplateForMahasiswa();
    [$user, $mahasiswa] = ktmMahasiswaUser();

    Livewire::actingAs($user)
        ->test(KtmLivewire::class)
        ->call('buatKtm')
        ->assertHasNoErrors();

    $ktm = Ktm::where('id_mahasiswa', $mahasiswa->id)->firstOrFail();
    // Nomor KTM diisi petugas lewat panel admin, bukan oleh mahasiswa saat membuat KTM.
    expect($ktm->nomor_ktm)->toBeNull();
    expect($ktm->status)->toBe('active');
    Storage::disk('public')->assertExists($ktm->file);
});

it('shows an error when creating a ktm before the admin has configured a template', function () {
    [$user, $mahasiswa] = ktmMahasiswaUser();

    Livewire::actingAs($user)
        ->test(KtmLivewire::class)
        ->call('buatKtm')
        ->assertSet('ktmId', null);

    expect(Ktm::where('id_mahasiswa', $mahasiswa->id)->exists())->toBeFalse();
});

it('lets a mahasiswa regenerate their existing ktm', function () {
    seedKtmTemplateForMahasiswa();
    [$user, $mahasiswa] = ktmMahasiswaUser();
    $ktm = Ktm::factory()->create(['id_mahasiswa' => $mahasiswa->id, 'file' => 'ktm/old.png']);
    Storage::disk('public')->put('ktm/old.png', 'old');

    Livewire::actingAs($user)
        ->test(KtmLivewire::class)
        ->call('perbaruiKtm')
        ->assertHasNoErrors();

    $ktm->refresh();
    expect($ktm->file)->not->toBe('ktm/old.png');
    Storage::disk('public')->assertExists($ktm->file);
});

it('only shows the ktm belonging to the authenticated mahasiswa', function () {
    [$user, $mahasiswa] = ktmMahasiswaUser();
    Ktm::factory()->create(['id_mahasiswa' => $mahasiswa->id, 'nomor_ktm' => 'MINE', 'file' => 'ktm/mine.png']);
    $otherKtm = Ktm::factory()->create(['nomor_ktm' => 'OTHER']);

    $this->actingAs($user)->get(route('mahasiswa.ktm'))
        ->assertOk()
        ->assertSee('MINE')
        ->assertDontSee('OTHER');

    expect($otherKtm->id_mahasiswa)->not->toBe($mahasiswa->id);
});

it('does not offer a nomor ktm field to the mahasiswa', function () {
    [$user] = ktmMahasiswaUser();

    $this->actingAs($user)->get(route('mahasiswa.ktm'))
        ->assertOk()
        ->assertDontSee('Nomor KTM (opsional)');
});
