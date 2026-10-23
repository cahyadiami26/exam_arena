-- Contoh seed 20 soal pilihan tunggal wawasan kebangsaan Indonesia.
-- Jalankan SETELAH database/schema.sql pada database aplikasi E-Assessment (bukan database SSO).
-- Seed ini hanya mengisi bank soal; HR tetap perlu memasukkan soal ke modul/paket melalui Assessment Studio.
-- Kode SAMPLE-WKN-* dicadangkan untuk contoh. Menjalankan ulang tidak menimpa soal/pilihan yang sudah ada.

SET NAMES utf8mb4;
START TRANSACTION;

INSERT INTO question_bank
    (code, category, question_type, question_text, instructions, max_score, weight, is_active)
VALUES
    ('SAMPLE-WKN-001', 'OTHER', 'single_choice', 'Dasar negara Indonesia yang tercantum dalam Pembukaan UUD Negara Republik Indonesia Tahun 1945 adalah …', 'Pilih satu jawaban yang paling tepat.', 1.00, 1.00, 1),
    ('SAMPLE-WKN-002', 'OTHER', 'single_choice', 'Apa bunyi sila pertama Pancasila?', 'Pilih satu jawaban yang paling tepat.', 1.00, 1.00, 1),
    ('SAMPLE-WKN-003', 'OTHER', 'single_choice', 'Apa bunyi sila kedua Pancasila?', 'Pilih satu jawaban yang paling tepat.', 1.00, 1.00, 1),
    ('SAMPLE-WKN-004', 'OTHER', 'single_choice', 'Apa bunyi sila ketiga Pancasila?', 'Pilih satu jawaban yang paling tepat.', 1.00, 1.00, 1),
    ('SAMPLE-WKN-005', 'OTHER', 'single_choice', 'Apa bunyi sila keempat Pancasila?', 'Pilih satu jawaban yang paling tepat.', 1.00, 1.00, 1),
    ('SAMPLE-WKN-006', 'OTHER', 'single_choice', 'Apa bunyi sila kelima Pancasila?', 'Pilih satu jawaban yang paling tepat.', 1.00, 1.00, 1),
    ('SAMPLE-WKN-007', 'OTHER', 'single_choice', 'Apa makna semboyan Bhinneka Tunggal Ika dalam kehidupan berbangsa?', 'Pilih satu jawaban yang paling tepat.', 1.00, 1.00, 1),
    ('SAMPLE-WKN-008', 'OTHER', 'single_choice', 'Kapan Proklamasi Kemerdekaan Indonesia dibacakan?', 'Pilih satu jawaban yang paling tepat.', 1.00, 1.00, 1),
    ('SAMPLE-WKN-009', 'OTHER', 'single_choice', 'Proklamasi Kemerdekaan Indonesia dibacakan oleh Soekarno dengan didampingi oleh …', 'Pilih satu jawaban yang paling tepat.', 1.00, 1.00, 1),
    ('SAMPLE-WKN-010', 'OTHER', 'single_choice', 'Konstitusi yang berlaku di Indonesia saat ini adalah …', 'Pilih satu jawaban yang paling tepat.', 1.00, 1.00, 1),
    ('SAMPLE-WKN-011', 'OTHER', 'single_choice', 'Menurut Pasal 1 ayat (1) UUD Negara Republik Indonesia Tahun 1945, bentuk negara Indonesia adalah …', 'Pilih satu jawaban yang paling tepat.', 1.00, 1.00, 1),
    ('SAMPLE-WKN-012', 'OTHER', 'single_choice', 'Sumpah Pemuda yang menjadi tonggak persatuan pemuda Indonesia diikrarkan pada tanggal …', 'Pilih satu jawaban yang paling tepat.', 1.00, 1.00, 1),
    ('SAMPLE-WKN-013', 'OTHER', 'single_choice', 'Dalam Sumpah Pemuda, bahasa persatuan yang dijunjung adalah …', 'Pilih satu jawaban yang paling tepat.', 1.00, 1.00, 1),
    ('SAMPLE-WKN-014', 'OTHER', 'single_choice', 'Apa nama lambang negara Indonesia?', 'Pilih satu jawaban yang paling tepat.', 1.00, 1.00, 1),
    ('SAMPLE-WKN-015', 'OTHER', 'single_choice', 'Warna bendera negara Indonesia adalah …', 'Pilih satu jawaban yang paling tepat.', 1.00, 1.00, 1),
    ('SAMPLE-WKN-016', 'OTHER', 'single_choice', 'Siapa pencipta lagu kebangsaan Indonesia Raya?', 'Pilih satu jawaban yang paling tepat.', 1.00, 1.00, 1),
    ('SAMPLE-WKN-017', 'OTHER', 'single_choice', 'Pernyataan yang paling tepat mengenai Wawasan Nusantara adalah …', 'Pilih satu jawaban yang paling tepat.', 1.00, 1.00, 1),
    ('SAMPLE-WKN-018', 'OTHER', 'single_choice', 'Asas pemilihan umum yang dikenal dengan singkatan LUBER JURDIL adalah …', 'Pilih satu jawaban yang paling tepat.', 1.00, 1.00, 1),
    ('SAMPLE-WKN-019', 'OTHER', 'single_choice', 'Tiga fungsi DPR menurut UUD Negara Republik Indonesia Tahun 1945 adalah …', 'Pilih satu jawaban yang paling tepat.', 1.00, 1.00, 1),
    ('SAMPLE-WKN-020', 'OTHER', 'single_choice', 'Salah satu kewenangan Mahkamah Konstitusi adalah …', 'Pilih satu jawaban yang paling tepat.', 1.00, 1.00, 1)
ON DUPLICATE KEY UPDATE code = VALUES(code);

INSERT INTO question_options
    (question_id, option_key, option_text, is_correct, score_value, papi_aspect_code, sort_order)
SELECT q.id, seed.option_key, seed.option_text, seed.is_correct, seed.score_value, NULL, seed.sort_order
FROM question_bank AS q
INNER JOIN (
    SELECT 'SAMPLE-WKN-001' AS question_code, 'A' AS option_key, 'Undang-Undang Dasar Negara Republik Indonesia Tahun 1945' AS option_text, 0 AS is_correct, 0.00 AS score_value, 1 AS sort_order
    UNION ALL SELECT 'SAMPLE-WKN-001', 'B', 'Pancasila', 1, 1.00, 2
    UNION ALL SELECT 'SAMPLE-WKN-001', 'C', 'Bhinneka Tunggal Ika', 0, 0.00, 3
    UNION ALL SELECT 'SAMPLE-WKN-001', 'D', 'Negara Kesatuan Republik Indonesia', 0, 0.00, 4

    UNION ALL SELECT 'SAMPLE-WKN-002', 'A', 'Ketuhanan Yang Maha Esa', 1, 1.00, 1
    UNION ALL SELECT 'SAMPLE-WKN-002', 'B', 'Kemanusiaan yang adil dan beradab', 0, 0.00, 2
    UNION ALL SELECT 'SAMPLE-WKN-002', 'C', 'Persatuan Indonesia', 0, 0.00, 3
    UNION ALL SELECT 'SAMPLE-WKN-002', 'D', 'Keadilan sosial bagi seluruh rakyat Indonesia', 0, 0.00, 4

    UNION ALL SELECT 'SAMPLE-WKN-003', 'A', 'Persatuan Indonesia', 0, 0.00, 1
    UNION ALL SELECT 'SAMPLE-WKN-003', 'B', 'Kerakyatan yang dipimpin oleh hikmat kebijaksanaan dalam permusyawaratan/perwakilan', 0, 0.00, 2
    UNION ALL SELECT 'SAMPLE-WKN-003', 'C', 'Kemanusiaan yang adil dan beradab', 1, 1.00, 3
    UNION ALL SELECT 'SAMPLE-WKN-003', 'D', 'Keadilan sosial bagi seluruh rakyat Indonesia', 0, 0.00, 4

    UNION ALL SELECT 'SAMPLE-WKN-004', 'A', 'Ketuhanan Yang Maha Esa', 0, 0.00, 1
    UNION ALL SELECT 'SAMPLE-WKN-004', 'B', 'Kemanusiaan yang adil dan beradab', 0, 0.00, 2
    UNION ALL SELECT 'SAMPLE-WKN-004', 'C', 'Persatuan Indonesia', 1, 1.00, 3
    UNION ALL SELECT 'SAMPLE-WKN-004', 'D', 'Kerakyatan yang dipimpin oleh hikmat kebijaksanaan dalam permusyawaratan/perwakilan', 0, 0.00, 4

    UNION ALL SELECT 'SAMPLE-WKN-005', 'A', 'Keadilan sosial bagi seluruh rakyat Indonesia', 0, 0.00, 1
    UNION ALL SELECT 'SAMPLE-WKN-005', 'B', 'Persatuan Indonesia', 0, 0.00, 2
    UNION ALL SELECT 'SAMPLE-WKN-005', 'C', 'Kemanusiaan yang adil dan beradab', 0, 0.00, 3
    UNION ALL SELECT 'SAMPLE-WKN-005', 'D', 'Kerakyatan yang dipimpin oleh hikmat kebijaksanaan dalam permusyawaratan/perwakilan', 1, 1.00, 4

    UNION ALL SELECT 'SAMPLE-WKN-006', 'A', 'Persatuan Indonesia', 0, 0.00, 1
    UNION ALL SELECT 'SAMPLE-WKN-006', 'B', 'Keadilan sosial bagi seluruh rakyat Indonesia', 1, 1.00, 2
    UNION ALL SELECT 'SAMPLE-WKN-006', 'C', 'Ketuhanan Yang Maha Esa', 0, 0.00, 3
    UNION ALL SELECT 'SAMPLE-WKN-006', 'D', 'Kemanusiaan yang adil dan beradab', 0, 0.00, 4

    UNION ALL SELECT 'SAMPLE-WKN-007', 'A', 'Berbeda-beda tetapi tetap satu', 1, 1.00, 1
    UNION ALL SELECT 'SAMPLE-WKN-007', 'B', 'Semua warga harus memiliki adat istiadat yang sama', 0, 0.00, 2
    UNION ALL SELECT 'SAMPLE-WKN-007', 'C', 'Perbedaan daerah harus dihilangkan', 0, 0.00, 3
    UNION ALL SELECT 'SAMPLE-WKN-007', 'D', 'Persatuan hanya berlaku bagi kelompok tertentu', 0, 0.00, 4

    UNION ALL SELECT 'SAMPLE-WKN-008', 'A', '1 Juni 1945', 0, 0.00, 1
    UNION ALL SELECT 'SAMPLE-WKN-008', 'B', '28 Oktober 1928', 0, 0.00, 2
    UNION ALL SELECT 'SAMPLE-WKN-008', 'C', '17 Agustus 1945', 1, 1.00, 3
    UNION ALL SELECT 'SAMPLE-WKN-008', 'D', '10 November 1945', 0, 0.00, 4

    UNION ALL SELECT 'SAMPLE-WKN-009', 'A', 'Ki Hajar Dewantara', 0, 0.00, 1
    UNION ALL SELECT 'SAMPLE-WKN-009', 'B', 'Sutan Sjahrir', 0, 0.00, 2
    UNION ALL SELECT 'SAMPLE-WKN-009', 'C', 'Ahmad Soebardjo', 0, 0.00, 3
    UNION ALL SELECT 'SAMPLE-WKN-009', 'D', 'Mohammad Hatta', 1, 1.00, 4

    UNION ALL SELECT 'SAMPLE-WKN-010', 'A', 'Konstitusi RIS Tahun 1949', 0, 0.00, 1
    UNION ALL SELECT 'SAMPLE-WKN-010', 'B', 'UUD Negara Republik Indonesia Tahun 1945', 1, 1.00, 2
    UNION ALL SELECT 'SAMPLE-WKN-010', 'C', 'Undang-Undang tentang Pemerintahan Daerah', 0, 0.00, 3
    UNION ALL SELECT 'SAMPLE-WKN-010', 'D', 'Piagam Jakarta sebagai konstitusi yang berlaku', 0, 0.00, 4

    UNION ALL SELECT 'SAMPLE-WKN-011', 'A', 'Negara kesatuan yang berbentuk republik', 1, 1.00, 1
    UNION ALL SELECT 'SAMPLE-WKN-011', 'B', 'Negara serikat yang berbentuk republik', 0, 0.00, 2
    UNION ALL SELECT 'SAMPLE-WKN-011', 'C', 'Kerajaan konstitusional', 0, 0.00, 3
    UNION ALL SELECT 'SAMPLE-WKN-011', 'D', 'Konfederasi negara-negara bagian', 0, 0.00, 4

    UNION ALL SELECT 'SAMPLE-WKN-012', 'A', '20 Mei 1908', 0, 0.00, 1
    UNION ALL SELECT 'SAMPLE-WKN-012', 'B', '1 Juni 1945', 0, 0.00, 2
    UNION ALL SELECT 'SAMPLE-WKN-012', 'C', '28 Oktober 1928', 1, 1.00, 3
    UNION ALL SELECT 'SAMPLE-WKN-012', 'D', '17 Agustus 1945', 0, 0.00, 4

    UNION ALL SELECT 'SAMPLE-WKN-013', 'A', 'Bahasa Jawa', 0, 0.00, 1
    UNION ALL SELECT 'SAMPLE-WKN-013', 'B', 'Bahasa Indonesia', 1, 1.00, 2
    UNION ALL SELECT 'SAMPLE-WKN-013', 'C', 'Bahasa Melayu daerah', 0, 0.00, 3
    UNION ALL SELECT 'SAMPLE-WKN-013', 'D', 'Bahasa Belanda', 0, 0.00, 4

    UNION ALL SELECT 'SAMPLE-WKN-014', 'A', 'Pohon Beringin', 0, 0.00, 1
    UNION ALL SELECT 'SAMPLE-WKN-014', 'B', 'Banteng', 0, 0.00, 2
    UNION ALL SELECT 'SAMPLE-WKN-014', 'C', 'Bintang', 0, 0.00, 3
    UNION ALL SELECT 'SAMPLE-WKN-014', 'D', 'Garuda Pancasila', 1, 1.00, 4

    UNION ALL SELECT 'SAMPLE-WKN-015', 'A', 'Merah dan putih', 1, 1.00, 1
    UNION ALL SELECT 'SAMPLE-WKN-015', 'B', 'Merah dan biru', 0, 0.00, 2
    UNION ALL SELECT 'SAMPLE-WKN-015', 'C', 'Putih dan hijau', 0, 0.00, 3
    UNION ALL SELECT 'SAMPLE-WKN-015', 'D', 'Kuning dan hitam', 0, 0.00, 4

    UNION ALL SELECT 'SAMPLE-WKN-016', 'A', 'Ismail Marzuki', 0, 0.00, 1
    UNION ALL SELECT 'SAMPLE-WKN-016', 'B', 'Cornel Simanjuntak', 0, 0.00, 2
    UNION ALL SELECT 'SAMPLE-WKN-016', 'C', 'Wage Rudolf Supratman', 1, 1.00, 3
    UNION ALL SELECT 'SAMPLE-WKN-016', 'D', 'Kusbini', 0, 0.00, 4

    UNION ALL SELECT 'SAMPLE-WKN-017', 'A', 'Cara pandang yang mengutamakan kepentingan satu daerah di atas kepentingan nasional', 0, 0.00, 1
    UNION ALL SELECT 'SAMPLE-WKN-017', 'B', 'Cara pandang bangsa Indonesia tentang diri dan lingkungannya sebagai satu kesatuan wilayah dan bangsa', 1, 1.00, 2
    UNION ALL SELECT 'SAMPLE-WKN-017', 'C', 'Kebijakan untuk menyeragamkan seluruh budaya daerah', 0, 0.00, 3
    UNION ALL SELECT 'SAMPLE-WKN-017', 'D', 'Pembagian wilayah Indonesia menjadi negara-negara yang berdiri sendiri', 0, 0.00, 4

    UNION ALL SELECT 'SAMPLE-WKN-018', 'A', 'Terbuka, umum, bersama, rahasia, jujur, dan adil', 0, 0.00, 1
    UNION ALL SELECT 'SAMPLE-WKN-018', 'B', 'Langsung, khusus, bebas, terbuka, jujur, dan adil', 0, 0.00, 2
    UNION ALL SELECT 'SAMPLE-WKN-018', 'C', 'Langsung, umum, bebas, terbuka, jujur, dan adil', 0, 0.00, 3
    UNION ALL SELECT 'SAMPLE-WKN-018', 'D', 'Langsung, umum, bebas, rahasia, jujur, dan adil', 1, 1.00, 4

    UNION ALL SELECT 'SAMPLE-WKN-019', 'A', 'Legislasi, anggaran, dan pengawasan', 1, 1.00, 1
    UNION ALL SELECT 'SAMPLE-WKN-019', 'B', 'Peradilan, diplomasi, dan keamanan', 0, 0.00, 2
    UNION ALL SELECT 'SAMPLE-WKN-019', 'C', 'Audit, penuntutan, dan penyidikan', 0, 0.00, 3
    UNION ALL SELECT 'SAMPLE-WKN-019', 'D', 'Moneter, fiskal, dan administrasi', 0, 0.00, 4

    UNION ALL SELECT 'SAMPLE-WKN-020', 'A', 'Mengangkat dan memberhentikan seluruh kepala daerah', 0, 0.00, 1
    UNION ALL SELECT 'SAMPLE-WKN-020', 'B', 'Mengaudit seluruh laporan keuangan kementerian', 0, 0.00, 2
    UNION ALL SELECT 'SAMPLE-WKN-020', 'C', 'Menguji undang-undang terhadap UUD Negara Republik Indonesia Tahun 1945', 1, 1.00, 3
    UNION ALL SELECT 'SAMPLE-WKN-020', 'D', 'Membentuk undang-undang tanpa persetujuan DPR', 0, 0.00, 4
) AS seed ON seed.question_code = q.code
ON DUPLICATE KEY UPDATE option_key = VALUES(option_key);

COMMIT;
