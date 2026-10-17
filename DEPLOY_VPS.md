# Panduan Update di VPS — KarbonKita Backend

Panduan langkah demi langkah untuk menarik & menerapkan update kode ke server
produksi (nginx + git manual, tanpa Docker).

## Detail per langkah

### 1. Tarik kode terbaru

```bash
git pull
```

### 2. Perbarui environment (`.env`)

Tambahkan/ubah variabel yang baru. Contoh (Cloudinary):

```env
CLOUDINARY_CLOUD_NAME=xxxxx
CLOUDINARY_API_KEY=xxxxx
CLOUDINARY_API_SECRET=xxxxx
CLOUDINARY_FOLDER=karbonkita
CLOUDINARY_MOCK=false
```

Catatan:

- `CLOUDINARY_MOCK=false` **wajib** agar benar-benar upload ke Cloudinary.
  Bila `true`, upload hanya menyimpan public_id palsu (`.../mock-xxxx`) dan
  gambar akan broken.

### 3. Install dependency & regenerate autoload

```bash
composer install --no-dev --optimize-autoloader
```

Wajib bila ada class baru (mis. `App\Services\CloudinaryService`,
`App\Support\ImageUrl`). Alternatif minimal:

```bash
composer dump-autoload -o
```

### 4. Refresh cache config

```bash
php artisan config:clear
php artisan config:cache
```
