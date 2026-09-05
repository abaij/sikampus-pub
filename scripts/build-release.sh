#!/usr/bin/env bash
#
# Bangun artefak rilis Sikampus yang siap pakai: zip berisi vendor/ dan public/build/
# yang sudah jadi, sehingga kampus yang memasangnya TIDAK perlu menjalankan composer
# maupun npm sama sekali.
#
# Pemakaian:
#   ./scripts/build-release.sh            # dari HEAD
#   ./scripts/build-release.sh v1.1.0     # dari sebuah tag/commit
#
# Alur rilis yang diharapkan:
#   1. echo 1.1.0 > VERSION && git commit -am "Rilis 1.1.0" && git tag v1.1.0
#   2. ./scripts/build-release.sh v1.1.0
#   3. unggah isi dist/ ke GitHub Releases
#
# Versi TIDAK diambil dari argumen, melainkan dibaca dari berkas VERSION di dalam ref
# yang dibangun. Ini disengaja: versi yang dilaporkan instalasi (config/sikampus.php
# membaca VERSION yang sama) karena itu dijamin sama persis dengan nama artefaknya,
# tanpa bergantung pada ketelitian orang yang mengetik argumen.
#
# Sumbernya `git archive`, bukan direktori kerja Anda. Dengan begitu berkas yang tidak
# terlacak — .env, plugins/ yang terpasang lokal, berkas percobaan — mustahil ikut
# terbawa ke dalam artefak yang dibagikan.

set -euo pipefail

REF="${1:-HEAD}"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIST_DIR="$REPO_ROOT/dist"

# Berkas & direktori yang hanya berguna saat pengembangan. Dibuang dari artefak supaya
# instalasi kampus tidak membawa suite test dan konfigurasi yang tidak pernah dipakainya.
#
# .claude/ ada di daftar ini walaupun juga tercantum di .gitignore: berkasnya sudah terlacak
# lebih dulu sebelum pola itu ditambahkan, dan .gitignore tidak melepas berkas yang SUDAH
# terlacak — jadi `git archive` tetap menyertakannya. Pelajaran umumnya: daftar ini tidak
# boleh berasumsi .gitignore sudah menyaring apa pun.
PRUNE=(
    tests
    phpunit.xml
    .env.testing
    CLAUDE.md
    .github
    .claude
    scripts
)

info()  { printf '\033[1;34m==>\033[0m %s\n' "$*"; }
die()   { printf '\033[1;31mGagal:\033[0m %s\n' "$*" >&2; exit 1; }

# sha256sum ada di Linux, shasum di macOS — dukung keduanya supaya rilis bisa dibangun
# dari laptop mana pun tanpa memasang perkakas tambahan.
sha256_of() {
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$1" | awk '{print $1}'
    else
        shasum -a 256 "$1" | awk '{print $1}'
    fi
}

for cmd in git composer npm zip php; do
    command -v "$cmd" >/dev/null 2>&1 || die "\"$cmd\" tidak ditemukan di PATH."
done

git -C "$REPO_ROOT" rev-parse --verify "$REF" >/dev/null 2>&1 \
    || die "Ref \"$REF\" tidak ada di repositori ini."

BUILD_DIR="$(mktemp -d)"
trap 'rm -rf "$BUILD_DIR"' EXIT

info "Mengekspor $REF ke direktori sementara"
SRC="$BUILD_DIR/src"
mkdir -p "$SRC"
git -C "$REPO_ROOT" archive --format=tar "$REF" | tar -x -C "$SRC"

[ -f "$SRC/VERSION" ] || die "Berkas VERSION tidak ada di ref \"$REF\". Buat dulu, commit, lalu tag."
VERSION="$(tr -d '[:space:]' < "$SRC/VERSION")"
[ -n "$VERSION" ] || die "Berkas VERSION ada tapi kosong di ref \"$REF\"."

RELEASE_NAME="sikampus-$VERSION"
info "Membangun rilis $VERSION"

info "composer install (tanpa dependensi dev)"
( cd "$SRC" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet )

info "npm ci && npm run build"
( cd "$SRC" && npm ci --silent && npm run build )

# node_modules hanya dibutuhkan untuk MEMBANGUN aset; hasil build-nya sudah ada di
# public/build. Menyertakannya akan melipatgandakan ukuran artefak tanpa guna sama sekali.
rm -rf "$SRC/node_modules"

info "Membuang berkas khusus pengembangan"
for path in "${PRUNE[@]}"; do
    rm -rf "${SRC:?}/$path"
done

# Sanity check: kalau salah satu dari ini tidak ada, artefaknya tidak "siap pakai" dan
# kampus akan menemukan aplikasi yang mati — lebih baik gagal di sini.
[ -d "$SRC/vendor" ]       || die "vendor/ tidak terbentuk — composer install gagal diam-diam?"
[ -d "$SRC/public/build" ] || die "public/build/ tidak terbentuk — npm run build gagal diam-diam?"
[ -f "$SRC/.env.example" ] || die ".env.example hilang dari artefak; instalasi baru butuh berkas ini."

info "Menyusun manifest sha256"
php "$REPO_ROOT/scripts/build-manifest.php" "$SRC" "$VERSION"

info "Mengemas zip"
mkdir -p "$DIST_DIR"
rm -f "$DIST_DIR/$RELEASE_NAME.zip" "$DIST_DIR/$RELEASE_NAME.zip.sha256" "$DIST_DIR/$RELEASE_NAME.manifest.json"

# Zip dibungkus satu direktori teratas (sikampus-<versi>/), bukan ditumpahkan apa adanya.
# Updater dan orang yang mengekstrak manual sama-sama butuh titik pijak yang pasti; zip
# tanpa direktori pembungkus gampang tertumpah ke tempat yang salah dan mencampuri
# berkas yang sudah ada di sana.
STAGE="$BUILD_DIR/$RELEASE_NAME"
mv "$SRC" "$STAGE"
( cd "$BUILD_DIR" && zip -rq "$DIST_DIR/$RELEASE_NAME.zip" "$RELEASE_NAME" )

cp "$STAGE/sikampus-manifest.json" "$DIST_DIR/$RELEASE_NAME.manifest.json"
sha256_of "$DIST_DIR/$RELEASE_NAME.zip" > "$DIST_DIR/$RELEASE_NAME.zip.sha256"

SIZE="$(du -h "$DIST_DIR/$RELEASE_NAME.zip" | awk '{print $1}')"

printf '\n'
info "Selesai — versi $VERSION ($SIZE)"
printf '  %s\n' \
    "dist/$RELEASE_NAME.zip" \
    "dist/$RELEASE_NAME.zip.sha256" \
    "dist/$RELEASE_NAME.manifest.json"
printf '\nUnggah ketiganya ke GitHub Releases pada tag yang sesuai.\n'
