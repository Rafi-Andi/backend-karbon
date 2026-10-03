<?php

namespace Database\Seeders;

use App\Models\Mission;
use App\Models\Quiz;
use Illuminate\Database\Seeder;

class QuizSeeder extends Seeder
{
    public function run(): void
    {
        $missions = Mission::where('category', 'quiz')->get();

        if ($missions->isEmpty()) {
            return;
        }

        // Questions pool — distributed across quiz missions round-robin
        $questions = [
            [
                'question' => 'Berapa persen sampah di Indonesia yang belum terkelola dengan baik?',
                'options' => ['A' => 'Sekitar 30%', 'B' => 'Sekitar 45%', 'C' => 'Sekitar 60%', 'D' => 'Sekitar 75%'],
                'correct_answer' => 'C',
                'explanation' => 'Data KLHK menyebut ~60% sampah belum terkelola optimal, sehingga pemilahan di sumber sangat penting.',
            ],
            [
                'question' => 'Warna tempat sampah untuk sampah anorganik yang dapat didaur ulang adalah?',
                'options' => ['A' => 'Hijau', 'B' => 'Kuning', 'C' => 'Merah', 'D' => 'Biru'],
                'correct_answer' => 'B',
                'explanation' => 'Kuning untuk anorganik daur ulang (plastik, kertas), hijau untuk organik.',
            ],
            [
                'question' => 'Bersepeda 5 km menggantikan motor dapat menghemat emisi CO2 sekitar?',
                'options' => ['A' => '0,2 kg CO2', 'B' => '0,6 kg CO2', 'C' => '1,2 kg CO2', 'D' => '2,5 kg CO2'],
                'correct_answer' => 'B',
                'explanation' => 'Motor rata-rata ~120g CO2/km, jadi 5 km ≈ 0,6 kg CO2 yang dihemat.',
            ],
            [
                'question' => 'Manakah yang termasuk sampah B3 rumah tangga?',
                'options' => ['A' => 'Botol plastik', 'B' => 'Sisa makanan', 'C' => 'Baterai bekas', 'D' => 'Kardus'],
                'correct_answer' => 'C',
                'explanation' => 'Baterai mengandung logam berat dan termasuk B3, jangan buang ke sampah biasa.',
            ],
            [
                'question' => 'Gerakan 3R yang paling efektif mengurangi sampah adalah?',
                'options' => ['A' => 'Recycle', 'B' => 'Reuse', 'C' => 'Reduce', 'D' => 'Replace'],
                'correct_answer' => 'C',
                'explanation' => 'Reduce (kurangi) di hulu paling berdampak sebelum sampah tercipta.',
            ],
            [
                'question' => 'Jalan kaki 30 menit membakar kalori sekitar?',
                'options' => ['A' => '50-70 kkal', 'B' => '90-120 kkal', 'C' => '200-250 kkal', 'D' => '300-350 kkal'],
                'correct_answer' => 'B',
                'explanation' => 'Jalan kaki sedang ~90-120 kkal per 30 menit tergantung berat badan.',
            ],
            [
                'question' => 'Sampah organik sebaiknya diolah menjadi?',
                'options' => ['A' => 'Paving block', 'B' => 'Kompos', 'C' => 'Kertas daur ulang', 'D' => 'Plastik daur ulang'],
                'correct_answer' => 'B',
                'explanation' => 'Sisa makanan/daun cocok jadi kompos untuk kesuburan tanah.',
            ],
            [
                'question' => 'Berapa lama botol plastik terurai di alam?',
                'options' => ['A' => '10-20 tahun', 'B' => '50-80 tahun', 'C' => '200-500 tahun', 'D' => '5 tahun'],
                'correct_answer' => 'C',
                'explanation' => 'Botol PET butuh ratusan tahun terurai, jadi kurangi & daur ulang.',
            ],
            [
                'question' => 'Transportasi ramah karbon terbaik untuk jarak <3 km adalah?',
                'options' => ['A' => 'Motor', 'B' => 'Mobil', 'C' => 'Jalan kaki / sepeda', 'D' => 'Ojek online mobil'],
                'correct_answer' => 'C',
                'explanation' => 'Jalan kaki/sepeda nol emisi dan menyehatkan untuk jarak dekat.',
            ],
            [
                'question' => 'Simbol segitiga daur ulang dengan angka 1 (PET) berarti?',
                'options' => ['A' => 'Aman untuk panas tinggi', 'B' => 'Sekali pakai, daur ulang', 'C' => 'Bisa jadi kompos', 'D' => 'Mengandung B3'],
                'correct_answer' => 'B',
                'explanation' => 'PET kode 1 ideal untuk sekali pakai dan dapat didaur ulang.',
            ],
            [
                'question' => 'Apa kepanjangan dari 3R dalam pengelolaan sampah?',
                'options' => ['A' => 'Reduce, Reuse, Recycle', 'B' => 'Read, Ride, Run', 'C' => 'Refill, Repair, Return', 'D' => 'Rot, Reuse, Burn'],
                'correct_answer' => 'A',
                'explanation' => '3R = Reduce (kurangi), Reuse (pakai ulang), Recycle (daur ulang).',
            ],
            [
                'question' => 'Sampah kertas dan kardus sebaiknya dibuang ke tempat sampah warna?',
                'options' => ['A' => 'Merah', 'B' => 'Hijau', 'C' => 'Kuning', 'D' => 'Abu-abu'],
                'correct_answer' => 'C',
                'explanation' => 'Kertas dan kardus termasuk anorganik daur ulang (wadah kuning).',
            ],
            [
                'question' => 'Gas rumah kaca utama yang dihasilkan dari tumpukan sampah organik di TPA adalah?',
                'options' => ['A' => 'Oksigen', 'B' => 'Nitrogen', 'C' => 'Hidrogen', 'D' => 'Metana'],
                'correct_answer' => 'D',
                'explanation' => 'Pembusukan anaerobik sampah organik menghasilkan gas metana (CH4).',
            ],
            [
                'question' => 'Bank sampah adalah sistem pengelolaan sampah yang...?',
                'options' => ['A' => 'Menimbun sampah di bank', 'B' => 'Menabung sampah terpilah yang bernilai ekonomis', 'C' => 'Membakar sampah jadi listrik', 'D' => 'Membuang sampah ke bank'],
                'correct_answer' => 'B',
                'explanation' => 'Bank sampah menampung sampah terpilah warga dan menukarnya dengan tabungan uang.',
            ],
            [
                'question' => 'Minyak jelantah bekas sebaiknya dibuang dengan cara?',
                'options' => ['A' => 'Dibuang ke wastafel', 'B' => 'Dibuang ke tanah', 'C' => 'Dikumpulkan untuk diolah jadi biodiesel', 'D' => 'Dibuang ke tempat sampah organik'],
                'correct_answer' => 'C',
                'explanation' => 'Jelantah mencemari air dan tanah; pengumpulannya bisa diolah jadi biodiesel.',
            ],
            [
                'question' => 'Berapa persen kira-kira emisi karbon yang bisa dihemat dengan memilah dan mendaur ulang sampah plastik?',
                'options' => ['A' => 'Tidak berpengaruh sama sekali', 'B' => 'Sedikit, di bawah 5%', 'C' => 'Signifikan, puluhan persen dibanding produksi baru', 'D' => '100% hilang total'],
                'correct_answer' => 'C',
                'explanation' => 'Daur ulang plastik menghemat energi dan emisi puluhan persen dibanding memproduksi plastik virgin.',
            ],
            [
                'question' => 'Hari Peduli Sampah Nasional Indonesia diperingati setiap tanggal?',
                'options' => ['A' => '21 Februari', 'B' => '22 April', 'C' => '5 Juni', 'D' => '17 Agustus'],
                'correct_answer' => 'A',
                'explanation' => 'HPSN diperingati setiap 21 Februari untuk mengingatkan darurat sampah.',
            ],
            [
                'question' => 'Cara paling tepat membuang sampah masker sekali pakai adalah?',
                'options' => ['A' => 'Dibuang utuh ke tempat sampah', 'B' => 'Digunting talinya lalu dibuang terpisah', 'C' => 'Dicuci dan dipakai lagi', 'D' => 'Dibakar di halaman'],
                'correct_answer' => 'B',
                'explanation' => 'Tali masker digunting agar tidak menjerat satwa, lalu dibuang sebagai sampah medis/domestik khusus.',
            ],
            [
                'question' => 'Jejak karbon (carbon footprint) adalah?',
                'options' => ['A' => 'Jejak kaki di pasir karbon', 'B' => 'Total emisi gas rumah kaca dari aktivitas manusia', 'C' => 'Luas tanah tambang batu bara', 'D' => 'Jumlah pohon yang ditebang'],
                'correct_answer' => 'B',
                'explanation' => 'Jejak karbon mengukur total emisi GRK (terutama CO2) dari gaya hidup dan aktivitas kita.',
            ],
            [
                'question' => 'Transportasi dengan emisi karbon per penumpang tertinggi adalah?',
                'options' => ['A' => 'Sepeda', 'B' => 'Bus TransJakarta penuh', 'C' => 'Mobil pribadi berisi 1 orang', 'D' => 'KRL commuterline penuh'],
                'correct_answer' => 'C',
                'explanation' => 'Mobil pribadi dengan 1 penumpang punya emisi per kapita tertinggi dibanding angkutan massal dan sepeda.',
            ],
            [
                'question' => 'Lampu LED dibanding lampu pijar menghemat listrik sekitar?',
                'options' => ['A' => '5%', 'B' => '10-15%', 'C' => '75-80%', 'D' => 'Tidak hemat sama sekali'],
                'correct_answer' => 'C',
                'explanation' => 'LED mengubah ~80% energi jadi cahaya, jauh lebih hemat dari pijar yang boros jadi panas.',
            ],
            [
                'question' => 'Kebiasaan yang paling menurunkan jejak karbon harian adalah?',
                'options' => ['A' => 'Mandi 3 kali sehari', 'B' => 'Naik kendaraan pribadi jarak dekat', 'C' => 'Jalan kaki atau bersepeda untuk jarak dekat', 'D' => 'Menyalakan AC 16 derajat seharian'],
                'correct_answer' => 'C',
                'explanation' => 'Mobilitas aktif nol emisi untuk jarak dekat adalah cara termudah memangkas jejak karbon.',
            ],
            [
                'question' => 'Sumber energi terbarukan yang paling cocok untuk rumah tangga kota adalah?',
                'options' => ['A' => 'PLTN pribadi', 'B' => 'Panel surya atap', 'C' => 'Turbin angin raksasa', 'D' => 'Generator bensin'],
                'correct_answer' => 'B',
                'explanation' => 'Panel surya atap paling praktis dan ekonomis untuk skala rumah tangga perkotaan.',
            ],
            [
                'question' => 'Istilah food waste merujuk pada?',
                'options' => ['A' => 'Makanan cepat saji', 'B' => 'Makanan yang terbuang sia-sia', 'C' => 'Sampah kemasan makanan', 'D' => 'Pupuk dari sisa makanan'],
                'correct_answer' => 'B',
                'explanation' => 'Food waste adalah makanan layak konsumsi yang terbuang; menguranginya menekan emisi metana TPA.',
            ],
        ];

        $order = 1;
        foreach ($questions as $idx => $q) {
            $mission = $missions[$idx % $missions->count()];
            Quiz::create([
                'mission_id' => $mission->id,
                'question' => $q['question'],
                'options' => $q['options'],
                'correct_answer' => $q['correct_answer'],
                'explanation' => $q['explanation'],
                'order' => $order++,
            ]);
        }
    }
}
