-- Contoh seed 20 item forced-choice latihan untuk kategori PAPI_KOSTICK.
-- Item ditulis khusus sebagai contoh non-resmi; bukan salinan atau bentuk PAPI Kostick terstandar/tervalidasi.
-- Tidak ada pilihan benar/salah. Most menambah +1 dan Least -1 ke kode aspek pilihan, sebagai skor mentah saja.
-- Tidak ada norma, interpretasi, atau rekomendasi otomatis.
-- Jalankan SETELAH database/schema.sql pada database aplikasi E-Assessment (bukan database SSO).
-- Seed hanya mengisi bank soal; HR tetap perlu memasukkan item ke modul/paket melalui Assessment Studio.
-- Kode SAMPLE-PAPI-* dicadangkan untuk contoh. Menjalankan ulang tidak menimpa soal/pilihan yang sudah ada.

SET NAMES utf8mb4;
START TRANSACTION;

INSERT INTO question_bank
    (code, category, question_type, question_text, instructions, papi_item_code, max_score, weight, is_active)
VALUES
    ('SAMPLE-PAPI-001', 'PAPI_KOSTICK', 'forced_choice', 'Di antara dua pernyataan kerja berikut, pilih yang paling menggambarkan dan yang paling tidak menggambarkan kebiasaan Anda.', 'Contoh item latihan orisinal, bukan butir resmi PAPI Kostick dan belum tervalidasi. Pilih satu pernyataan sebagai Most dan satu pernyataan berbeda sebagai Least. Tidak ada jawaban benar atau salah; hasil hanya dihitung sebagai skor mentah per aspek, tanpa norma atau interpretasi otomatis.', 'SAMPLE-PAPI-001', 1.00, 1.00, 1),
    ('SAMPLE-PAPI-002', 'PAPI_KOSTICK', 'forced_choice', 'Di antara dua pernyataan kerja berikut, pilih yang paling menggambarkan dan yang paling tidak menggambarkan kebiasaan Anda.', 'Contoh item latihan orisinal, bukan butir resmi PAPI Kostick dan belum tervalidasi. Pilih satu pernyataan sebagai Most dan satu pernyataan berbeda sebagai Least. Tidak ada jawaban benar atau salah; hasil hanya dihitung sebagai skor mentah per aspek, tanpa norma atau interpretasi otomatis.', 'SAMPLE-PAPI-002', 1.00, 1.00, 1),
    ('SAMPLE-PAPI-003', 'PAPI_KOSTICK', 'forced_choice', 'Di antara dua pernyataan kerja berikut, pilih yang paling menggambarkan dan yang paling tidak menggambarkan kebiasaan Anda.', 'Contoh item latihan orisinal, bukan butir resmi PAPI Kostick dan belum tervalidasi. Pilih satu pernyataan sebagai Most dan satu pernyataan berbeda sebagai Least. Tidak ada jawaban benar atau salah; hasil hanya dihitung sebagai skor mentah per aspek, tanpa norma atau interpretasi otomatis.', 'SAMPLE-PAPI-003', 1.00, 1.00, 1),
    ('SAMPLE-PAPI-004', 'PAPI_KOSTICK', 'forced_choice', 'Di antara dua pernyataan kerja berikut, pilih yang paling menggambarkan dan yang paling tidak menggambarkan kebiasaan Anda.', 'Contoh item latihan orisinal, bukan butir resmi PAPI Kostick dan belum tervalidasi. Pilih satu pernyataan sebagai Most dan satu pernyataan berbeda sebagai Least. Tidak ada jawaban benar atau salah; hasil hanya dihitung sebagai skor mentah per aspek, tanpa norma atau interpretasi otomatis.', 'SAMPLE-PAPI-004', 1.00, 1.00, 1),
    ('SAMPLE-PAPI-005', 'PAPI_KOSTICK', 'forced_choice', 'Di antara dua pernyataan kerja berikut, pilih yang paling menggambarkan dan yang paling tidak menggambarkan kebiasaan Anda.', 'Contoh item latihan orisinal, bukan butir resmi PAPI Kostick dan belum tervalidasi. Pilih satu pernyataan sebagai Most dan satu pernyataan berbeda sebagai Least. Tidak ada jawaban benar atau salah; hasil hanya dihitung sebagai skor mentah per aspek, tanpa norma atau interpretasi otomatis.', 'SAMPLE-PAPI-005', 1.00, 1.00, 1),
    ('SAMPLE-PAPI-006', 'PAPI_KOSTICK', 'forced_choice', 'Di antara dua pernyataan kerja berikut, pilih yang paling menggambarkan dan yang paling tidak menggambarkan kebiasaan Anda.', 'Contoh item latihan orisinal, bukan butir resmi PAPI Kostick dan belum tervalidasi. Pilih satu pernyataan sebagai Most dan satu pernyataan berbeda sebagai Least. Tidak ada jawaban benar atau salah; hasil hanya dihitung sebagai skor mentah per aspek, tanpa norma atau interpretasi otomatis.', 'SAMPLE-PAPI-006', 1.00, 1.00, 1),
    ('SAMPLE-PAPI-007', 'PAPI_KOSTICK', 'forced_choice', 'Di antara dua pernyataan kerja berikut, pilih yang paling menggambarkan dan yang paling tidak menggambarkan kebiasaan Anda.', 'Contoh item latihan orisinal, bukan butir resmi PAPI Kostick dan belum tervalidasi. Pilih satu pernyataan sebagai Most dan satu pernyataan berbeda sebagai Least. Tidak ada jawaban benar atau salah; hasil hanya dihitung sebagai skor mentah per aspek, tanpa norma atau interpretasi otomatis.', 'SAMPLE-PAPI-007', 1.00, 1.00, 1),
    ('SAMPLE-PAPI-008', 'PAPI_KOSTICK', 'forced_choice', 'Di antara dua pernyataan kerja berikut, pilih yang paling menggambarkan dan yang paling tidak menggambarkan kebiasaan Anda.', 'Contoh item latihan orisinal, bukan butir resmi PAPI Kostick dan belum tervalidasi. Pilih satu pernyataan sebagai Most dan satu pernyataan berbeda sebagai Least. Tidak ada jawaban benar atau salah; hasil hanya dihitung sebagai skor mentah per aspek, tanpa norma atau interpretasi otomatis.', 'SAMPLE-PAPI-008', 1.00, 1.00, 1),
    ('SAMPLE-PAPI-009', 'PAPI_KOSTICK', 'forced_choice', 'Di antara dua pernyataan kerja berikut, pilih yang paling menggambarkan dan yang paling tidak menggambarkan kebiasaan Anda.', 'Contoh item latihan orisinal, bukan butir resmi PAPI Kostick dan belum tervalidasi. Pilih satu pernyataan sebagai Most dan satu pernyataan berbeda sebagai Least. Tidak ada jawaban benar atau salah; hasil hanya dihitung sebagai skor mentah per aspek, tanpa norma atau interpretasi otomatis.', 'SAMPLE-PAPI-009', 1.00, 1.00, 1),
    ('SAMPLE-PAPI-010', 'PAPI_KOSTICK', 'forced_choice', 'Di antara dua pernyataan kerja berikut, pilih yang paling menggambarkan dan yang paling tidak menggambarkan kebiasaan Anda.', 'Contoh item latihan orisinal, bukan butir resmi PAPI Kostick dan belum tervalidasi. Pilih satu pernyataan sebagai Most dan satu pernyataan berbeda sebagai Least. Tidak ada jawaban benar atau salah; hasil hanya dihitung sebagai skor mentah per aspek, tanpa norma atau interpretasi otomatis.', 'SAMPLE-PAPI-010', 1.00, 1.00, 1),
    ('SAMPLE-PAPI-011', 'PAPI_KOSTICK', 'forced_choice', 'Di antara dua pernyataan kerja berikut, pilih yang paling menggambarkan dan yang paling tidak menggambarkan kebiasaan Anda.', 'Contoh item latihan orisinal, bukan butir resmi PAPI Kostick dan belum tervalidasi. Pilih satu pernyataan sebagai Most dan satu pernyataan berbeda sebagai Least. Tidak ada jawaban benar atau salah; hasil hanya dihitung sebagai skor mentah per aspek, tanpa norma atau interpretasi otomatis.', 'SAMPLE-PAPI-011', 1.00, 1.00, 1),
    ('SAMPLE-PAPI-012', 'PAPI_KOSTICK', 'forced_choice', 'Di antara dua pernyataan kerja berikut, pilih yang paling menggambarkan dan yang paling tidak menggambarkan kebiasaan Anda.', 'Contoh item latihan orisinal, bukan butir resmi PAPI Kostick dan belum tervalidasi. Pilih satu pernyataan sebagai Most dan satu pernyataan berbeda sebagai Least. Tidak ada jawaban benar atau salah; hasil hanya dihitung sebagai skor mentah per aspek, tanpa norma atau interpretasi otomatis.', 'SAMPLE-PAPI-012', 1.00, 1.00, 1),
    ('SAMPLE-PAPI-013', 'PAPI_KOSTICK', 'forced_choice', 'Di antara dua pernyataan kerja berikut, pilih yang paling menggambarkan dan yang paling tidak menggambarkan kebiasaan Anda.', 'Contoh item latihan orisinal, bukan butir resmi PAPI Kostick dan belum tervalidasi. Pilih satu pernyataan sebagai Most dan satu pernyataan berbeda sebagai Least. Tidak ada jawaban benar atau salah; hasil hanya dihitung sebagai skor mentah per aspek, tanpa norma atau interpretasi otomatis.', 'SAMPLE-PAPI-013', 1.00, 1.00, 1),
    ('SAMPLE-PAPI-014', 'PAPI_KOSTICK', 'forced_choice', 'Di antara dua pernyataan kerja berikut, pilih yang paling menggambarkan dan yang paling tidak menggambarkan kebiasaan Anda.', 'Contoh item latihan orisinal, bukan butir resmi PAPI Kostick dan belum tervalidasi. Pilih satu pernyataan sebagai Most dan satu pernyataan berbeda sebagai Least. Tidak ada jawaban benar atau salah; hasil hanya dihitung sebagai skor mentah per aspek, tanpa norma atau interpretasi otomatis.', 'SAMPLE-PAPI-014', 1.00, 1.00, 1),
    ('SAMPLE-PAPI-015', 'PAPI_KOSTICK', 'forced_choice', 'Di antara dua pernyataan kerja berikut, pilih yang paling menggambarkan dan yang paling tidak menggambarkan kebiasaan Anda.', 'Contoh item latihan orisinal, bukan butir resmi PAPI Kostick dan belum tervalidasi. Pilih satu pernyataan sebagai Most dan satu pernyataan berbeda sebagai Least. Tidak ada jawaban benar atau salah; hasil hanya dihitung sebagai skor mentah per aspek, tanpa norma atau interpretasi otomatis.', 'SAMPLE-PAPI-015', 1.00, 1.00, 1),
    ('SAMPLE-PAPI-016', 'PAPI_KOSTICK', 'forced_choice', 'Di antara dua pernyataan kerja berikut, pilih yang paling menggambarkan dan yang paling tidak menggambarkan kebiasaan Anda.', 'Contoh item latihan orisinal, bukan butir resmi PAPI Kostick dan belum tervalidasi. Pilih satu pernyataan sebagai Most dan satu pernyataan berbeda sebagai Least. Tidak ada jawaban benar atau salah; hasil hanya dihitung sebagai skor mentah per aspek, tanpa norma atau interpretasi otomatis.', 'SAMPLE-PAPI-016', 1.00, 1.00, 1),
    ('SAMPLE-PAPI-017', 'PAPI_KOSTICK', 'forced_choice', 'Di antara dua pernyataan kerja berikut, pilih yang paling menggambarkan dan yang paling tidak menggambarkan kebiasaan Anda.', 'Contoh item latihan orisinal, bukan butir resmi PAPI Kostick dan belum tervalidasi. Pilih satu pernyataan sebagai Most dan satu pernyataan berbeda sebagai Least. Tidak ada jawaban benar atau salah; hasil hanya dihitung sebagai skor mentah per aspek, tanpa norma atau interpretasi otomatis.', 'SAMPLE-PAPI-017', 1.00, 1.00, 1),
    ('SAMPLE-PAPI-018', 'PAPI_KOSTICK', 'forced_choice', 'Di antara dua pernyataan kerja berikut, pilih yang paling menggambarkan dan yang paling tidak menggambarkan kebiasaan Anda.', 'Contoh item latihan orisinal, bukan butir resmi PAPI Kostick dan belum tervalidasi. Pilih satu pernyataan sebagai Most dan satu pernyataan berbeda sebagai Least. Tidak ada jawaban benar atau salah; hasil hanya dihitung sebagai skor mentah per aspek, tanpa norma atau interpretasi otomatis.', 'SAMPLE-PAPI-018', 1.00, 1.00, 1),
    ('SAMPLE-PAPI-019', 'PAPI_KOSTICK', 'forced_choice', 'Di antara dua pernyataan kerja berikut, pilih yang paling menggambarkan dan yang paling tidak menggambarkan kebiasaan Anda.', 'Contoh item latihan orisinal, bukan butir resmi PAPI Kostick dan belum tervalidasi. Pilih satu pernyataan sebagai Most dan satu pernyataan berbeda sebagai Least. Tidak ada jawaban benar atau salah; hasil hanya dihitung sebagai skor mentah per aspek, tanpa norma atau interpretasi otomatis.', 'SAMPLE-PAPI-019', 1.00, 1.00, 1),
    ('SAMPLE-PAPI-020', 'PAPI_KOSTICK', 'forced_choice', 'Di antara dua pernyataan kerja berikut, pilih yang paling menggambarkan dan yang paling tidak menggambarkan kebiasaan Anda.', 'Contoh item latihan orisinal, bukan butir resmi PAPI Kostick dan belum tervalidasi. Pilih satu pernyataan sebagai Most dan satu pernyataan berbeda sebagai Least. Tidak ada jawaban benar atau salah; hasil hanya dihitung sebagai skor mentah per aspek, tanpa norma atau interpretasi otomatis.', 'SAMPLE-PAPI-020', 1.00, 1.00, 1)
ON DUPLICATE KEY UPDATE code = VALUES(code);

INSERT INTO question_options
    (question_id, option_key, option_text, is_correct, score_value, papi_aspect_code, sort_order)
SELECT q.id, seed.option_key, seed.option_text, 0, 0.00, seed.papi_aspect_code, seed.sort_order
FROM question_bank AS q
INNER JOIN (
    SELECT 'SAMPLE-PAPI-001' AS question_code, 'A' AS option_key, 'Saya berupaya menuntaskan satu pekerjaan sebelum beralih ke tugas lain.' AS option_text, 'N' AS papi_aspect_code, 1 AS sort_order
    UNION ALL SELECT 'SAMPLE-PAPI-001', 'B', 'Saya bersedia menambah usaha saat diperlukan agar target kerja tercapai.', 'G', 2
    UNION ALL SELECT 'SAMPLE-PAPI-002', 'A', 'Saya tetap mengerahkan tenaga ekstra ketika tanggung jawab sedang padat.', 'G', 1
    UNION ALL SELECT 'SAMPLE-PAPI-002', 'B', 'Saya tertantang mencapai sasaran kerja yang menuntut usaha lebih.', 'A', 2
    UNION ALL SELECT 'SAMPLE-PAPI-003', 'A', 'Saya menetapkan standar hasil yang tinggi bagi pekerjaan saya.', 'A', 1
    UNION ALL SELECT 'SAMPLE-PAPI-003', 'B', 'Saya bersedia mengoordinasikan rekan ketika tim membutuhkan arah kerja.', 'L', 2
    UNION ALL SELECT 'SAMPLE-PAPI-004', 'A', 'Saya nyaman mengambil inisiatif untuk menyatukan langkah tim.', 'L', 1
    UNION ALL SELECT 'SAMPLE-PAPI-004', 'B', 'Saya tertarik memantau kemajuan tugas tim agar tenggat terjaga.', 'P', 2
    UNION ALL SELECT 'SAMPLE-PAPI-005', 'A', 'Saya suka memastikan pembagian tugas berjalan sesuai rencana.', 'P', 1
    UNION ALL SELECT 'SAMPLE-PAPI-005', 'B', 'Setelah menimbang informasi utama, saya nyaman menetapkan pilihan.', 'I', 2
    UNION ALL SELECT 'SAMPLE-PAPI-006', 'A', 'Saya tidak terlalu lama menunda keputusan ketika tanggung jawabnya jelas.', 'I', 1
    UNION ALL SELECT 'SAMPLE-PAPI-006', 'B', 'Saya menyukai ritme kerja yang cepat saat prioritas sudah ditentukan.', 'T', 2
    UNION ALL SELECT 'SAMPLE-PAPI-007', 'A', 'Saya menikmati menyelesaikan pekerjaan dengan tempo yang dinamis.', 'T', 1
    UNION ALL SELECT 'SAMPLE-PAPI-007', 'B', 'Saya dapat menjaga tenaga untuk menyelesaikan rangkaian tugas dalam satu hari.', 'V', 2
    UNION ALL SELECT 'SAMPLE-PAPI-008', 'A', 'Saya tetap fokus meski pekerjaan membutuhkan konsentrasi dalam waktu cukup lama.', 'V', 1
    UNION ALL SELECT 'SAMPLE-PAPI-008', 'B', 'Saya menikmati bertukar informasi dengan rekan dari unit lain.', 'S', 2
    UNION ALL SELECT 'SAMPLE-PAPI-009', 'A', 'Saya senang mengenal rekan kerja baru dalam kegiatan profesional.', 'S', 1
    UNION ALL SELECT 'SAMPLE-PAPI-009', 'B', 'Saya merasa nyaman saat menjadi bagian dari tim yang bekerja bersama.', 'B', 2
    UNION ALL SELECT 'SAMPLE-PAPI-010', 'A', 'Saya menyukai kegiatan kerja yang melibatkan kolaborasi rutin.', 'B', 1
    UNION ALL SELECT 'SAMPLE-PAPI-010', 'B', 'Saya menghargai hubungan kerja yang hangat dan saling peduli.', 'O', 2
    UNION ALL SELECT 'SAMPLE-PAPI-011', 'A', 'Saya senang menjaga kedekatan dan komunikasi yang baik dengan rekan.', 'O', 1
    UNION ALL SELECT 'SAMPLE-PAPI-011', 'B', 'Saya merasa bersemangat ketika kontribusi saya mendapat apresiasi.', 'X', 2
    UNION ALL SELECT 'SAMPLE-PAPI-012', 'A', 'Saya senang saat hasil kerja saya diketahui oleh orang yang berkepentingan.', 'X', 1
    UNION ALL SELECT 'SAMPLE-PAPI-012', 'B', 'Saya terbiasa merencanakan urutan langkah sebelum mulai bekerja.', 'C', 2
    UNION ALL SELECT 'SAMPLE-PAPI-013', 'A', 'Saya menyukai jadwal kerja dan proses yang tertata.', 'C', 1
    UNION ALL SELECT 'SAMPLE-PAPI-013', 'B', 'Saya memeriksa rincian pekerjaan untuk mengurangi kekeliruan.', 'D', 2
    UNION ALL SELECT 'SAMPLE-PAPI-014', 'A', 'Saya memberi perhatian pada ketepatan data dan dokumen.', 'D', 1
    UNION ALL SELECT 'SAMPLE-PAPI-014', 'B', 'Saya senang memahami alasan dan konsep di balik prosedur.', 'R', 2
    UNION ALL SELECT 'SAMPLE-PAPI-015', 'A', 'Saya tertarik mempelajari kerangka berpikir yang mendasari suatu pekerjaan.', 'R', 1
    UNION ALL SELECT 'SAMPLE-PAPI-015', 'B', 'Saya terbuka mencoba cara baru ketika situasi kerja berubah.', 'Z', 2
    UNION ALL SELECT 'SAMPLE-PAPI-016', 'A', 'Saya menikmati variasi tugas yang memberi kesempatan belajar pendekatan berbeda.', 'Z', 1
    UNION ALL SELECT 'SAMPLE-PAPI-016', 'B', 'Saya berusaha tetap tenang ketika menghadapi tekanan kerja.', 'E', 2
    UNION ALL SELECT 'SAMPLE-PAPI-017', 'A', 'Saya menjaga kestabilan sikap saat pekerjaan tidak berjalan sesuai rencana.', 'E', 1
    UNION ALL SELECT 'SAMPLE-PAPI-017', 'B', 'Saya dapat menyampaikan perbedaan pendapat secara profesional.', 'K', 2
    UNION ALL SELECT 'SAMPLE-PAPI-018', 'A', 'Saya bersedia membahas persoalan yang tidak disepakati demi menemukan solusi.', 'K', 1
    UNION ALL SELECT 'SAMPLE-PAPI-018', 'B', 'Saya siap membantu atasan menyiapkan informasi yang dibutuhkan tim.', 'F', 2
    UNION ALL SELECT 'SAMPLE-PAPI-019', 'A', 'Saya senang mendukung atasan dalam menindaklanjuti kebutuhan pekerjaan.', 'F', 1
    UNION ALL SELECT 'SAMPLE-PAPI-019', 'B', 'Saya bekerja lebih nyaman bila prosedur dan batas tanggung jawab dijelaskan.', 'W', 2
    UNION ALL SELECT 'SAMPLE-PAPI-020', 'A', 'Arahan dan pengawasan yang jelas membantu saya memastikan pekerjaan sesuai standar.', 'W', 1
    UNION ALL SELECT 'SAMPLE-PAPI-020', 'B', 'Saya berusaha menyelesaikan tugas sampai tuntas sebelum menganggapnya selesai.', 'N', 2
) AS seed ON seed.question_code = q.code
ON DUPLICATE KEY UPDATE option_key = VALUES(option_key);

COMMIT;
