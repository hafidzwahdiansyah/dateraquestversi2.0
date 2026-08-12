# DateraQuest 2.0 — API Specification

Base URL (development lokal): `http://localhost:8080/api`

## Konvensi Response

Semua response berupa JSON dengan struktur konsisten:

```json
{
  "success": true,
  "data": { },
  "message": "..."
}
```

- `success` — boolean, wajib ada di semua response.
- `data` — isi payload saat sukses. `null` kalau tidak relevan (mis. logout).
- `message` — pesan singkat untuk FE (sukses maupun error).

Response error mengikuti struktur yang sama dengan `success: false` dan `data: null`, kecuali disebutkan lain di bawah.

## Autentikasi

Endpoint yang butuh login menggunakan **session cookie** (PHP native session, dibuat saat `/api/auth/login`). FE mengirim cookie ini otomatis lewat `fetch(..., { credentials: "include" })`. Tidak menggunakan token/JWT di Tahap ini.

---

## A. Auth & Registrasi (publik)

### `GET /api/auth/google`

Redirect browser ke halaman consent Google OAuth.

- **Auth**: tidak perlu
- **Request**: tidak ada body (navigasi langsung dari tombol "Continue with Google")
- **Response sukses**: HTTP `302 Found` dengan header `Location` ke URL consent Google (bukan body JSON, karena ini redirect browser)
- **Response error**:
  - `500` — konfigurasi Google OAuth tidak lengkap (client ID/secret kosong)
    ```json
    { "success": false, "data": null, "message": "Konfigurasi Google OAuth tidak ditemukan" }
    ```

### `GET /api/auth/google/callback`

Menerima callback dari Google setelah user menyetujui consent. Menyimpan `google_email` ke session sementara (belum membuat akun tim), lalu redirect ke halaman form registrasi di FE.

- **Auth**: tidak perlu
- **Request**: query string dari Google, mis. `?code=...&state=...`
- **Response sukses**: HTTP `302 Found` ke `{APP_URL}/register.html` (session sudah berisi `google_email` dan `google_verified = true`)
- **Response error**:
  - `400` — kode otorisasi tidak valid/expired, redirect ke `{APP_URL}/login.html?error=google_auth_failed`
  - `502` — gagal menghubungi Google (network/timeout)
    ```json
    { "success": false, "data": null, "message": "Gagal menghubungi Google, coba lagi" }
    ```

### `POST /api/auth/register`

Membuat akun tim baru. Mensyaratkan sesi sudah berisi `google_email` hasil OAuth (lihat callback di atas) — kalau belum OAuth, ditolak.

- **Auth**: tidak perlu (tapi butuh sesi OAuth sementara, bukan sesi login)
- **Request body** (`application/json`):
  ```json
  {
    "competition_id": 2,
    "nama_tim": "Data Wizards",
    "username": "datawizards01",
    "password": "SecurePass123!",
    "members": [
      {
        "nama_lengkap": "Andi Saputra",
        "institusi": "Universitas Contoh",
        "nim": "2110511001",
        "nomor_hp": "081234567890",
        "is_ketua": true
      },
      {
        "nama_lengkap": "Budi Santoso",
        "institusi": "Universitas Contoh",
        "nim": "2110511002",
        "nomor_hp": "081234567891",
        "is_ketua": false
      }
    ]
  }
  ```
- **Validasi**:
  - Sesi harus punya `google_email` tervalidasi (dari step OAuth)
  - `competition_id` valid dan ada di tabel `competitions`
  - Jumlah `members` sesuai `min_members`–`max_members` kompetisi terpilih
  - Tepat satu anggota dengan `is_ketua: true`
  - `username` belum dipakai tim lain
  - `google_email` (dari sesi) belum pernah dipakai tim lain
  - `password` memenuhi panjang minimum (disepakati di implementasi, mis. 8 karakter)
- **Response sukses** (`201 Created`):
  ```json
  {
    "success": true,
    "data": {
      "team_id": 12,
      "nama_tim": "Data Wizards",
      "username": "datawizards01",
      "status_registrasi": "pending"
    },
    "message": "Registrasi berhasil, silakan login dan lanjutkan pembayaran"
  }
  ```
- **Response error**:
  - `401` — belum OAuth Google
    ```json
    { "success": false, "data": null, "message": "Silakan login dengan Google terlebih dahulu" }
    ```
  - `409` — username atau google_email sudah terdaftar
    ```json
    { "success": false, "data": null, "message": "Username sudah digunakan" }
    ```
  - `422` — validasi gagal (jumlah anggota tidak sesuai, dsb.)
    ```json
    {
      "success": false,
      "data": { "members": "Kategori ini membutuhkan 2-3 anggota" },
      "message": "Data registrasi tidak valid"
    }
    ```

### `POST /api/auth/login`

Login dengan `username` + `password`, membuat session login.

- **Auth**: tidak perlu
- **Request body**:
  ```json
  { "username": "datawizards01", "password": "SecurePass123!" }
  ```
- **Response sukses** (`200 OK`):
  ```json
  {
    "success": true,
    "data": {
      "team_id": 12,
      "nama_tim": "Data Wizards",
      "status_registrasi": "pending"
    },
    "message": "Login berhasil"
  }
  ```
- **Response error**:
  - `401` — username/password salah
    ```json
    { "success": false, "data": null, "message": "Username atau password salah" }
    ```
  - `422` — body tidak lengkap
    ```json
    { "success": false, "data": null, "message": "Username dan password wajib diisi" }
    ```

### `POST /api/auth/logout`

Menghapus session login aktif.

- **Auth**: perlu login
- **Request**: tidak ada body
- **Response sukses** (`200 OK`):
  ```json
  { "success": true, "data": null, "message": "Logout berhasil" }
  ```
- **Response error**:
  - `401` — tidak ada sesi login aktif
    ```json
    { "success": false, "data": null, "message": "Belum login" }
    ```

---

## B. Peserta (butuh login)

### `GET /api/me`

Info lengkap akun tim yang sedang login: data tim, anggota, status registrasi, dan status bukti bayar.

- **Auth**: perlu login
- **Request**: tidak ada body
- **Response sukses** (`200 OK`):
  ```json
  {
    "success": true,
    "data": {
      "team_id": 12,
      "nama_tim": "Data Wizards",
      "competition": { "id": 2, "nama": "Machine Learning", "slug": "machine-learning" },
      "status_registrasi": "pending",
      "members": [
        { "nama_lengkap": "Andi Saputra", "institusi": "Universitas Contoh", "nim": "2110511001", "nomor_hp": "081234567890", "is_ketua": true },
        { "nama_lengkap": "Budi Santoso", "institusi": "Universitas Contoh", "nim": "2110511002", "nomor_hp": "081234567891", "is_ketua": false }
      ],
      "payment_proof": {
        "status": "pending",
        "file_path": "bukti_pembayaran/12_1699999999.jpg",
        "catatan_admin": null
      }
    },
    "message": "OK"
  }
  ```
  Catatan: `payment_proof` bernilai `null` kalau tim belum pernah upload bukti bayar.
- **Response error**:
  - `401` — belum login
    ```json
    { "success": false, "data": null, "message": "Belum login" }
    ```

### `GET /api/competitions`

Daftar semua kategori kompetisi (dipakai FE untuk dropdown di form registrasi, dan info di dashboard).

- **Auth**: perlu login
- **Request**: tidak ada body
- **Response sukses** (`200 OK`):
  ```json
  {
    "success": true,
    "data": [
      { "id": 1, "nama": "Iterative Dashboard", "slug": "iterative-dashboard", "min_members": 2, "max_members": 3 },
      { "id": 2, "nama": "Machine Learning", "slug": "machine-learning", "min_members": 2, "max_members": 3 },
      { "id": 3, "nama": "Essay Quest", "slug": "essay-quest", "min_members": 1, "max_members": 1 }
    ],
    "message": "OK"
  }
  ```
- **Response error**:
  - `401` — belum login
    ```json
    { "success": false, "data": null, "message": "Belum login" }
    ```

> Catatan: endpoint ini secara data juga bisa berguna untuk form registrasi publik (sebelum login). Ditandai "butuh login" sesuai spesifikasi FE saat ini; kalau nantinya form registrasi butuh daftar kompetisi tanpa login, endpoint ini perlu dibuka publik — didiskusikan lagi saat implementasi FE registrasi.

### `POST /api/payment/upload`

Upload file bukti pembayaran. Tim hanya boleh upload saat `status_registrasi = pending`. Upload ulang akan menimpa bukti bayar sebelumnya (satu baris aktif per tim) dan status bukti bayar direset ke `pending`.

- **Auth**: perlu login
- **Request**: `multipart/form-data`
  - Field `file`: file bukti pembayaran (jpg/png/pdf, maks 2MB)
- **Validasi**:
  - `status_registrasi` tim harus `pending`
  - Ekstensi/MIME type file hanya `jpg`, `jpeg`, `png`, `pdf`
  - Ukuran file maksimal 2MB
- **Response sukses** (`200 OK`):
  ```json
  {
    "success": true,
    "data": {
      "file_path": "bukti_pembayaran/12_1699999999.jpg",
      "status": "pending"
    },
    "message": "Bukti pembayaran berhasil diupload"
  }
  ```
- **Response error**:
  - `401` — belum login
    ```json
    { "success": false, "data": null, "message": "Belum login" }
    ```
  - `403` — status_registrasi tim bukan `pending` (mis. sudah `verified`)
    ```json
    { "success": false, "data": null, "message": "Tim ini tidak bisa mengupload bukti pembayaran saat ini" }
    ```
  - `413` — file lebih dari 2MB
    ```json
    { "success": false, "data": null, "message": "Ukuran file maksimal 2MB" }
    ```
  - `422` — format file tidak didukung / field `file` kosong
    ```json
    { "success": false, "data": null, "message": "Format file harus jpg, png, atau pdf" }
    ```

---

## Ringkasan HTTP Status Code

| Kode | Arti dalam konteks API ini |
|------|------------------------------|
| 200  | Sukses (GET/POST tanpa membuat resource baru) |
| 201  | Sukses membuat resource baru (registrasi tim) |
| 302  | Redirect (alur OAuth Google) |
| 401  | Belum login / sesi OAuth belum ada |
| 403  | Login valid tapi aksi tidak diizinkan untuk status tim saat ini |
| 409  | Konflik data unik (username/google_email sudah dipakai) |
| 413  | Ukuran file melebihi batas |
| 422  | Validasi input gagal |
| 500  | Kesalahan server/konfigurasi |
| 502  | Kesalahan komunikasi ke layanan eksternal (Google) |
