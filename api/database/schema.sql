-- =========================================================
-- DateraQuest 2.0 - Database Schema
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
--
-- Desain kunci (ringkas, untuk konteks tim dev):
--   - payment_proofs.team_id bersifat UNIQUE -> satu tim hanya
--     punya satu baris bukti bayar aktif. Upload ulang dari
--     dashboard akan MENIMPA baris lama (UPDATE, bukan INSERT baru),
--     dan status di-reset ke 'pending' oleh aplikasi.
--   - teams.status_registrasi TIDAK berubah otomatis mengikuti
--     payment_proofs.status. Admin yang mengubah status_registrasi
--     secara manual setelah meninjau bukti pembayaran.
-- =========================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -------------------------------------------------------
-- Tabel: competitions
-- Menyimpan 3 kategori kompetisi beserta batas jumlah anggota tim.
-- Data bersifat statis/master data, diisi langsung lewat seed di bawah.
-- -------------------------------------------------------
CREATE TABLE competitions (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nama         VARCHAR(100) NOT NULL COMMENT 'Nama kategori, mis. "Machine Learning"',
    slug         VARCHAR(100) NOT NULL COMMENT 'Slug URL-friendly, mis. "machine-learning"',
    deskripsi    TEXT NULL COMMENT 'Deskripsi singkat kategori untuk ditampilkan di FE',
    min_members  TINYINT UNSIGNED NOT NULL COMMENT 'Jumlah anggota minimum termasuk ketua',
    max_members  TINYINT UNSIGNED NOT NULL COMMENT 'Jumlah anggota maksimum termasuk ketua',
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_competitions_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Master data 3 kategori kompetisi DateraQuest 2.0';

-- -------------------------------------------------------
-- Tabel: teams
-- Satu baris = satu akun peserta (tim untuk Iterative
-- Dashboard/Machine Learning, atau individu untuk Essay Quest).
-- Akun ini yang dipakai bersama oleh seluruh anggota tim.
-- -------------------------------------------------------
CREATE TABLE teams (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    competition_id     INT UNSIGNED NOT NULL COMMENT 'Kategori kompetisi yang diikuti',
    nama_tim           VARCHAR(150) NOT NULL COMMENT 'Nama tim, atau nama peserta untuk Essay Quest',
    username            VARCHAR(50) NOT NULL COMMENT 'Username buatan ketua, dipakai untuk login harian',
    password           VARCHAR(255) NOT NULL COMMENT 'Hash bcrypt (password_hash), bukan plaintext',
    google_email       VARCHAR(150) NOT NULL COMMENT 'Email Google ketua saat OAuth, dipakai cegah daftar ganda',
    status_registrasi  ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending'
                        COMMENT 'Status verifikasi admin atas kelengkapan registrasi + pembayaran',
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_teams_username (username),
    UNIQUE KEY uq_teams_google_email (google_email),
    KEY idx_teams_status_registrasi (status_registrasi),
    KEY idx_teams_competition_id (competition_id),
    CONSTRAINT fk_teams_competition
        FOREIGN KEY (competition_id) REFERENCES competitions (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
        -- RESTRICT: kategori kompetisi tidak boleh terhapus selama masih
        -- ada tim terdaftar di kategori tsb, supaya data pendaftaran tidak
        -- pernah kehilangan konteks kompetisinya secara diam-diam.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Akun tim/peserta, satu baris = satu akun login';

-- -------------------------------------------------------
-- Tabel: team_members
-- Data tiap anggota tim (termasuk ketua) yang mendaftar di bawah
-- satu akun teams. Untuk Essay Quest hanya akan ada 1 baris (is_ketua=1).
-- -------------------------------------------------------
CREATE TABLE team_members (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    team_id       INT UNSIGNED NOT NULL COMMENT 'Relasi ke akun tim pemilik anggota ini',
    nama_lengkap  VARCHAR(150) NOT NULL,
    institusi     VARCHAR(150) NOT NULL COMMENT 'Asal kampus/institusi anggota',
    nim           VARCHAR(50) NOT NULL COMMENT 'Nomor Induk Mahasiswa dari KTM',
    nomor_hp      VARCHAR(20) NOT NULL,
    is_ketua      BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'TRUE untuk anggota yang mendaftar via Google OAuth',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_team_members_team_id (team_id),
    CONSTRAINT fk_team_members_team
        FOREIGN KEY (team_id) REFERENCES teams (id)
        ON DELETE CASCADE ON UPDATE CASCADE
        -- CASCADE: data anggota tidak punya arti tanpa akun tim induknya,
        -- jadi ikut terhapus kalau akun tim dihapus.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Data tiap anggota tim, termasuk ketua';

-- -------------------------------------------------------
-- Tabel: payment_proofs
-- Bukti pembayaran yang diupload tim lewat dashboard. Satu tim
-- hanya punya satu baris aktif (team_id UNIQUE) - upload ulang
-- akan menimpa baris sebelumnya.
-- -------------------------------------------------------
CREATE TABLE payment_proofs (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    team_id        INT UNSIGNED NOT NULL COMMENT 'Tim pemilik bukti bayar ini',
    file_path      VARCHAR(255) NOT NULL COMMENT 'Path relatif file di uploads/bukti_pembayaran',
    status         ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending'
                    COMMENT 'Status peninjauan admin atas bukti bayar ini',
    catatan_admin  TEXT NULL COMMENT 'Alasan admin, terutama saat status rejected',
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_proofs_team_id (team_id),
    CONSTRAINT fk_payment_proofs_team
        FOREIGN KEY (team_id) REFERENCES teams (id)
        ON DELETE CASCADE ON UPDATE CASCADE
        -- CASCADE: bukti pembayaran tidak relevan lagi tanpa akun tim
        -- pemiliknya, jadi ikut terhapus kalau akun tim dihapus.
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Bukti pembayaran per tim, satu baris aktif per tim';

SET FOREIGN_KEY_CHECKS = 1;

-- =========================================================
-- Seed data: 3 kategori kompetisi
-- =========================================================
INSERT INTO competitions (nama, slug, deskripsi, min_members, max_members) VALUES
    ('Iterative Dashboard', 'iterative-dashboard', 'Kompetisi tim membangun dashboard data secara iteratif.', 2, 3),
    ('Machine Learning', 'machine-learning', 'Kompetisi tim menyelesaikan studi kasus machine learning.', 2, 3),
    ('Essay Quest', 'essay-quest', 'Kompetisi individu menulis esai bertema data science.', 1, 1);
